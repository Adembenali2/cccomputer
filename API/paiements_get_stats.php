<?php
/**
 * API pour récupérer les statistiques de consommation quotidienne
 * Calcule la consommation jour par jour (valeur du jour - valeur du jour précédent)
 * Combine les données de compteur_relevee et compteur_relevee_ancien
 * Filtrable par client, mois et année
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

try {
    $pdo = getPdoOrFail();
    
    // Récupération des paramètres
    $idClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : null;
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : null;
    
    // Construire les conditions WHERE
    $whereConditions = [];
    $params = [];
    
    // Filtre par client
    if ($idClient !== null && $idClient > 0) {
        $whereConditions[] = "EXISTS (
            SELECT 1 FROM photocopieurs_clients pc 
            WHERE pc.mac_norm = cr.mac_norm AND pc.id_client = :id_client
        )";
        $params[':id_client'] = $idClient;
    }
    
    // Filtre par année
    if ($annee !== null && $annee > 0) {
        $whereConditions[] = "YEAR(cr.Timestamp) = :annee";
        $params[':annee'] = $annee;
    }
    
    // Filtre par mois
    if ($mois !== null && $mois > 0 && $mois <= 12) {
        if ($annee === null || $annee <= 0) {
            $annee = (int)date('Y');
            $whereConditions[] = "YEAR(cr.Timestamp) = :annee";
            $params[':annee'] = $annee;
        }
        $whereConditions[] = "MONTH(cr.Timestamp) = :mois";
        $params[':mois'] = $mois;
    }
    
    // Si aucun filtre de date, limiter aux 90 derniers jours
    if ($annee === null && $mois === null) {
        $whereConditions[] = "DATE(cr.Timestamp) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
    }
    
    $whereClause = !empty($whereConditions) ? " AND " . implode(" AND ", $whereConditions) : "";
    
    // Requête pour calculer la consommation quotidienne
    // 1. Combiner les deux tables
    // 2. Prendre le dernier relevé de chaque jour pour chaque mac_norm
    // 3. Calculer la différence avec le jour précédent
    $sql = "
        SELECT 
            date_jour,
            annee,
            mois,
            jour,
            COALESCE(SUM(consommation_bw), 0) AS total_noir_blanc,
            COALESCE(SUM(consommation_color), 0) AS total_couleur,
            COALESCE(SUM(consommation_pages), 0) AS total_pages
        FROM (
            SELECT 
                date_jour,
                annee,
                mois,
                jour,
                mac_norm,
                -- Calcul de la consommation : valeur du jour - valeur du jour précédent
                -- LAG() retourne NULL pour le premier jour (pas de consommation)
                CASE 
                    WHEN prev_total_bw IS NULL THEN 0  -- Premier jour : pas de consommation
                    ELSE GREATEST(total_bw - prev_total_bw, 0)
                END AS consommation_bw,
                CASE 
                    WHEN prev_total_color IS NULL THEN 0  -- Premier jour : pas de consommation
                    ELSE GREATEST(total_color - prev_total_color, 0)
                END AS consommation_color,
                CASE 
                    WHEN prev_total_pages IS NULL THEN 0  -- Premier jour : pas de consommation
                    ELSE GREATEST(total_pages - prev_total_pages, 0)
                END AS consommation_pages
            FROM (
                SELECT 
                    date_jour,
                    annee,
                    mois,
                    jour,
                    mac_norm,
                    total_bw,
                    total_color,
                    total_pages,
                    -- Récupérer la valeur du jour précédent pour ce mac_norm
                    LAG(total_bw) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_total_bw,
                    LAG(total_color) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_total_color,
                    LAG(total_pages) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_total_pages
                FROM (
                    -- D'abord, combiner les deux tables et prendre le dernier relevé de chaque jour
                    SELECT 
                        DATE(cr.Timestamp) AS date_jour,
                        YEAR(cr.Timestamp) AS annee,
                        MONTH(cr.Timestamp) AS mois,
                        DAY(cr.Timestamp) AS jour,
                        cr.mac_norm,
                        MAX(COALESCE(cr.TotalBW, 0)) AS total_bw,
                        MAX(COALESCE(cr.TotalColor, 0)) AS total_color,
                        MAX(COALESCE(cr.TotalPages, 0)) AS total_pages
                    FROM (
                        SELECT Timestamp, mac_norm, TotalBW, TotalColor, TotalPages
                        FROM compteur_relevee
                        WHERE mac_norm IS NOT NULL AND mac_norm != ''
                        " . $whereClause . "
                        UNION ALL
                        SELECT Timestamp, mac_norm, TotalBW, TotalColor, TotalPages
                        FROM compteur_relevee_ancien
                        WHERE mac_norm IS NOT NULL AND mac_norm != ''
                        " . $whereClause . "
                    ) cr
                    INNER JOIN photocopieurs_clients pc ON cr.mac_norm = pc.mac_norm
                    GROUP BY DATE(cr.Timestamp), YEAR(cr.Timestamp), MONTH(cr.Timestamp), DAY(cr.Timestamp), cr.mac_norm
                ) AS daily_max
            ) AS with_prev
        ) AS daily_consumption
        GROUP BY date_jour, annee, mois, jour
        ORDER BY date_jour ASC
    ";
    
    // Dupliquer les paramètres pour les deux parties de l'UNION
    $finalParams = [];
    foreach ($params as $key => $value) {
        $finalParams[$key . '_1'] = $value;
        $finalParams[$key . '_2'] = $value;
    }
    
    // Remplacer les paramètres dans la requête pour la première partie
    $sql = str_replace(':id_client', ':id_client_1', $sql);
    $sql = str_replace(':annee', ':annee_1', $sql);
    $sql = str_replace(':mois', ':mois_1', $sql);
    
    // Remplacer dans la deuxième partie de l'UNION (après UNION ALL)
    $sqlParts = explode('UNION ALL', $sql);
    if (count($sqlParts) === 2) {
        $sqlParts[1] = str_replace([':id_client_1', ':annee_1', ':mois_1'], [':id_client_2', ':annee_2', ':mois_2'], $sqlParts[1]);
        $sql = $sqlParts[0] . 'UNION ALL' . $sqlParts[1];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($finalParams);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formatage des données pour le graphique
    $data = [
        'labels' => [],
        'noir_blanc' => [],
        'couleur' => [],
        'total_pages' => []
    ];
    
    foreach ($results as $row) {
        // Format de label : "01 Jan" pour afficher le jour
        $moisNoms = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $moisNom = $moisNoms[(int)$row['mois']] ?? '';
        $jour = str_pad((string)$row['jour'], 2, '0', STR_PAD_LEFT);
        $label = $jour . ' ' . $moisNom;
        
        $data['labels'][] = $label;
        $data['noir_blanc'][] = (int)$row['total_noir_blanc'];
        $data['couleur'][] = (int)$row['total_couleur'];
        $data['total_pages'][] = (int)$row['total_pages'];
    }
    
    // Récupération des informations du client si spécifié
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
