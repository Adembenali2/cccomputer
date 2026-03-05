<?php
/**
 * API pour récupérer les statistiques de consommation (deltas réels)
 * Calcule la consommation = différence entre compteurs cumulés de deux périodes successives
 * - Mensuel : dernier relevé du mois - dernier relevé du mois précédent
 * - Journalier : dernier relevé du jour - dernier relevé du jour précédent
 * Mapping client -> machines : photocopieurs_clients (id_client, mac_norm)
 * Si delta négatif (reset compteur) : clamp à 0
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

try {
    $pdo = getPdoOrFail();

    $idClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : null;
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : null;

    $groupByMonth = ($annee !== null && $annee > 0 && ($mois === null || $mois <= 0));

    // Bornes de période (inclure la période précédente pour calculer le delta du 1er bucket)
    if ($groupByMonth) {
        $dateStart = sprintf('%04d-01-01', $annee);
        $dateEnd = sprintf('%04d-12-31 23:59:59', $annee);
        $dateStartExtended = sprintf('%04d-01-01', $annee - 1); // inclure déc. année précédente
    } elseif ($mois !== null && $mois > 0 && $annee !== null && $annee > 0) {
        $dateStart = sprintf('%04d-%02d-01', $annee, $mois);
        $lastDay = (int)date('t', mktime(0, 0, 0, $mois, 1, $annee));
        $dateEnd = sprintf('%04d-%02d-%02d 23:59:59', $annee, $mois, $lastDay);
        $prevMonth = $mois - 1;
        $prevYear = $annee;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        $dateStartExtended = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
    } else {
        $dateStart = date('Y-m-d', strtotime('-90 days'));
        $dateEnd = date('Y-m-d 23:59:59');
        $dateStartExtended = date('Y-m-d', strtotime($dateStart . ' -1 day'));
    }

    $clientFilter = "";
    $params = [':date_start' => $dateStartExtended, ':date_end' => $dateEnd];
    if ($idClient !== null && $idClient > 0) {
        $clientFilter = " AND pc.id_client = :id_client";
        $params[':id_client'] = $idClient;
    }

    if ($groupByMonth) {
        $sql = "
            WITH raw AS (
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start AND r.Timestamp <= :date_end
                  " . $clientFilter . "
                UNION ALL
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee_ancien r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start2 AND r.Timestamp <= :date_end2
                  " . str_replace(':id_client', ':id_client2', $clientFilter) . "
            ),
            last_per_month AS (
                SELECT mac_norm, YEAR(Timestamp) AS annee, MONTH(Timestamp) AS mois,
                       TotalPages, TotalBW, TotalColor,
                       ROW_NUMBER() OVER (PARTITION BY mac_norm, YEAR(Timestamp), MONTH(Timestamp) ORDER BY Timestamp DESC) AS rn
                FROM raw
            ),
            one_per_month AS (
                SELECT mac_norm, annee, mois, TotalPages AS tp, TotalBW AS tb, TotalColor AS tc
                FROM last_per_month
                WHERE rn = 1
            ),
            with_prev AS (
                SELECT mac_norm, annee, mois,
                       tp, tb, tc,
                       LAG(tp) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tp,
                       LAG(tb) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tb,
                       LAG(tc) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tc
                FROM one_per_month
            ),
            deltas AS (
                SELECT annee, mois,
                       COALESCE(SUM(CASE WHEN prev_tp IS NULL THEN 0 ELSE GREATEST(0, tp - prev_tp) END), 0) AS total_pages,
                       COALESCE(SUM(CASE WHEN prev_tb IS NULL THEN 0 ELSE GREATEST(0, tb - prev_tb) END), 0) AS total_noir_blanc,
                       COALESCE(SUM(CASE WHEN prev_tc IS NULL THEN 0 ELSE GREATEST(0, tc - prev_tc) END), 0) AS total_couleur
                FROM with_prev
                GROUP BY annee, mois
            )
            SELECT annee, mois, total_pages, total_noir_blanc, total_couleur
            FROM deltas
            WHERE annee = :annee_filter AND mois >= 1 AND mois <= 12
            ORDER BY annee ASC, mois ASC
        ";
        $params[':date_start2'] = $dateStartExtended;
        $params[':date_end2'] = $dateEnd;
        $params[':annee_filter'] = $annee;
    } else {
        $sql = "
            WITH raw AS (
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start AND r.Timestamp <= :date_end
                  " . $clientFilter . "
                UNION ALL
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee_ancien r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start2 AND r.Timestamp <= :date_end2
                  " . str_replace(':id_client', ':id_client2', $clientFilter) . "
            ),
            last_per_day AS (
                SELECT mac_norm, DATE(Timestamp) AS date_jour,
                       YEAR(Timestamp) AS annee, MONTH(Timestamp) AS mois, DAY(Timestamp) AS jour,
                       TotalPages, TotalBW, TotalColor,
                       ROW_NUMBER() OVER (PARTITION BY mac_norm, DATE(Timestamp) ORDER BY Timestamp DESC) AS rn
                FROM raw
            ),
            one_per_day AS (
                SELECT mac_norm, date_jour, annee, mois, jour,
                       TotalPages AS tp, TotalBW AS tb, TotalColor AS tc
                FROM last_per_day
                WHERE rn = 1
            ),
            with_prev AS (
                SELECT mac_norm, date_jour, annee, mois, jour,
                       tp, tb, tc,
                       LAG(tp) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tp,
                       LAG(tb) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tb,
                       LAG(tc) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tc
                FROM one_per_day
            ),
            deltas AS (
                SELECT date_jour, annee, mois, jour,
                       COALESCE(SUM(CASE WHEN prev_tp IS NULL THEN 0 ELSE GREATEST(0, tp - prev_tp) END), 0) AS total_pages,
                       COALESCE(SUM(CASE WHEN prev_tb IS NULL THEN 0 ELSE GREATEST(0, tb - prev_tb) END), 0) AS total_noir_blanc,
                       COALESCE(SUM(CASE WHEN prev_tc IS NULL THEN 0 ELSE GREATEST(0, tc - prev_tc) END), 0) AS total_couleur
                FROM with_prev
                GROUP BY date_jour, annee, mois, jour
            )
            SELECT date_jour, annee, mois, jour, total_pages, total_noir_blanc, total_couleur
            FROM deltas
            WHERE date_jour >= :date_start_filter
            ORDER BY date_jour ASC
        ";
        $params[':date_start_filter'] = $dateStart;
        $params[':date_start2'] = $dateStartExtended;
        $params[':date_end2'] = $dateEnd;
    }

    if ($idClient !== null && $idClient > 0) {
        $params[':id_client2'] = $idClient;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $data = [
        'labels' => [],
        'noir_blanc' => [],
        'couleur' => [],
        'total_pages' => [],
        'group_by' => $groupByMonth ? 'month' : 'day'
    ];

    $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    $moisNomsCourts = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];

    if ($groupByMonth) {
        foreach ($results as $row) {
            $moisNom = $moisNoms[(int)$row['mois']] ?? '';
            $data['labels'][] = $moisNom . ' ' . $row['annee'];
            $data['noir_blanc'][] = (int)$row['total_noir_blanc'];
            $data['couleur'][] = (int)$row['total_couleur'];
            $data['total_pages'][] = (int)$row['total_pages'];
        }
    } else {
        if ($mois !== null && $mois > 0 && $annee !== null && $annee > 0) {
            $lastDay = (int)date('t', mktime(0, 0, 0, (int)$mois, 1, (int)$annee));
            $byDay = [];
            foreach ($results as $row) {
                $j = (int)($row['jour'] ?? 0);
                if ($j >= 1 && $j <= 31) {
                    $byDay[$j] = [
                        'nb' => (int)$row['total_noir_blanc'],
                        'couleur' => (int)$row['total_couleur'],
                        'total' => (int)$row['total_pages']
                    ];
                }
            }
            $data['dates_full'] = [];
            for ($j = 1; $j <= $lastDay; $j++) {
                $dateObj = new DateTime(sprintf('%04d-%02d-%02d', (int)$annee, (int)$mois, $j));
                $data['labels'][] = str_pad((string)$j, 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$mois, 2, '0', STR_PAD_LEFT);
                $data['dates_full'][] = $dateObj->format('d/m/Y');
                $v = $byDay[$j] ?? ['nb' => 0, 'couleur' => 0, 'total' => 0];
                $data['noir_blanc'][] = $v['nb'];
                $data['couleur'][] = $v['couleur'];
                $data['total_pages'][] = $v['total'];
            }
        } else {
            foreach ($results as $row) {
                $moisNom = $moisNomsCourts[(int)$row['mois']] ?? '';
                $jour = isset($row['jour']) ? str_pad((string)$row['jour'], 2, '0', STR_PAD_LEFT) : '';
                $data['labels'][] = $jour . ' ' . $moisNom;
                $data['dates_full'][] = ($row['date_jour'] ?? '') ? date('d/m/Y', strtotime($row['date_jour'])) : '';
                $data['noir_blanc'][] = (int)$row['total_noir_blanc'];
                $data['couleur'][] = (int)$row['total_couleur'];
                $data['total_pages'][] = (int)$row['total_pages'];
            }
        }
    }

    $clientInfo = null;
    if ($idClient !== null && $idClient > 0) {
        $stmtClient = $pdo->prepare("SELECT id, numero_client, raison_sociale FROM clients WHERE id = :id LIMIT 1");
        $stmtClient->execute([':id' => $idClient]);
        $clientInfo = $stmtClient->fetch(PDO::FETCH_ASSOC);
    }

    jsonResponse([
        'ok' => true,
        'data' => $data,
        'client' => $clientInfo,
        'filters' => [
            'client_id' => $idClient,
            'mois' => $mois,
            'annee' => $annee
        ]
    ]);

} catch (PDOException $e) {
    error_log('paiements_get_stats.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('paiements_get_stats.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}
