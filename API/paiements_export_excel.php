<?php
/**
 * API pour exporter les statistiques de consommation quotidienne en Excel
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
    
    // Construire les conditions WHERE pour les tables individuelles (sans alias cr)
    $whereConditions = [];
    $params = [];
    
    // Filtre par année
    if ($annee !== null && $annee > 0) {
        $whereConditions[] = "YEAR(Timestamp) = :annee";
        $params[':annee'] = $annee;
    }
    
    // Filtre par mois
    if ($mois !== null && $mois > 0 && $mois <= 12) {
        if ($annee === null || $annee <= 0) {
            $annee = (int)date('Y');
            $whereConditions[] = "YEAR(Timestamp) = :annee";
            $params[':annee'] = $annee;
        }
        $whereConditions[] = "MONTH(Timestamp) = :mois";
        $params[':mois'] = $mois;
    }
    
    // Si aucun filtre de date, limiter aux 90 derniers jours
    if ($annee === null && $mois === null) {
        $whereConditions[] = "DATE(Timestamp) >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)";
    }
    
    $whereClause = !empty($whereConditions) ? " AND " . implode(" AND ", $whereConditions) : "";
    
    // Construire la condition pour le filtre client (sera appliquée après le JOIN)
    $clientFilter = "";
    if ($idClient !== null && $idClient > 0) {
        $clientFilter = " AND pc.id_client = :id_client";
        $params[':id_client'] = $idClient;
    }
    
    // Requête pour calculer la consommation quotidienne (identique à paiements_get_stats.php)
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
                    WHERE 1=1 " . $clientFilter . "
                    GROUP BY DATE(cr.Timestamp), YEAR(cr.Timestamp), MONTH(cr.Timestamp), DAY(cr.Timestamp), cr.mac_norm
                ) AS daily_max
            ) AS with_prev
        ) AS daily_consumption
        GROUP BY date_jour, annee, mois, jour
        ORDER BY date_jour ASC
    ";
    
    // Dupliquer les paramètres pour les deux parties de l'UNION (sauf id_client qui est après le JOIN)
    $finalParams = [];
    foreach ($params as $key => $value) {
        if ($key === ':id_client') {
            // Le filtre client est utilisé une seule fois après le JOIN
            $finalParams[$key] = $value;
        } else {
            // Les autres paramètres sont utilisés deux fois (une fois par table dans l'UNION)
            $finalParams[$key . '_1'] = $value;
            $finalParams[$key . '_2'] = $value;
        }
    }
    
    // Remplacer les paramètres dans la requête pour la première partie de l'UNION
    $sql = str_replace(':annee', ':annee_1', $sql);
    $sql = str_replace(':mois', ':mois_1', $sql);
    
    // Remplacer dans la deuxième partie de l'UNION (après UNION ALL)
    $sqlParts = explode('UNION ALL', $sql);
    if (count($sqlParts) === 2) {
        $sqlParts[1] = str_replace([':annee_1', ':mois_1'], [':annee_2', ':mois_2'], $sqlParts[1]);
        $sql = $sqlParts[0] . 'UNION ALL' . $sqlParts[1];
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($finalParams);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupération du nom du client si spécifié
    $clientNom = 'Tous les clients';
    if ($idClient !== null && $idClient > 0) {
        $stmtClient = $pdo->prepare("SELECT raison_sociale FROM clients WHERE id = :id LIMIT 1");
        $stmtClient->execute([':id' => $idClient]);
        $client = $stmtClient->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $clientNom = $client['raison_sociale'];
        }
    }
    
    // Construire le nom du fichier avec les filtres
    $filenameParts = ['consommation_quotidienne'];
    if ($clientNom !== 'Tous les clients') {
        $filenameParts[] = preg_replace('/[^a-zA-Z0-9_-]/', '_', $clientNom);
    }
    if ($annee) {
        $filenameParts[] = $annee;
    }
    if ($mois) {
        $moisNoms = ['', 'Jan', 'Fev', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aou', 'Sep', 'Oct', 'Nov', 'Dec'];
        $filenameParts[] = $moisNoms[$mois] ?? $mois;
    }
    $filenameParts[] = date('Y-m-d_His');
    $filename = implode('_', $filenameParts) . '.csv';
    
    // Génération du fichier CSV (format Excel compatible)
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // BOM UTF-8 pour Excel
    echo "\xEF\xBB\xBF";
    
    // Ouvrir le flux de sortie
    $output = fopen('php://output', 'w');
    
    // En-têtes
    fputcsv($output, ['Consommation quotidienne des clients'], ';');
    fputcsv($output, ['Client: ' . $clientNom], ';');
    
    // Afficher les filtres appliqués
    $filters = [];
    if ($annee) {
        $filters[] = 'Année: ' . $annee;
    }
    if ($mois) {
        $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                     'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
        $filters[] = 'Mois: ' . ($moisNoms[$mois] ?? $mois);
    }
    if (empty($filters)) {
        $filters[] = 'Période: 90 derniers jours';
    }
    fputcsv($output, [implode(' - ', $filters)], ';');
    fputcsv($output, ['Date d\'export: ' . date('d/m/Y H:i:s')], ';');
    fputcsv($output, []); // Ligne vide
    
    // En-têtes des colonnes
    fputcsv($output, ['Date', 'Jour', 'Noir et Blanc', 'Couleur', 'Total Pages'], ';');
    
    // Données
    $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    
    $totalBW = 0;
    $totalColor = 0;
    $totalPages = 0;
    
    foreach ($results as $row) {
        $dateStr = $row['date_jour'];
        $moisNom = $moisNoms[(int)$row['mois']] ?? '';
        $jour = str_pad((string)$row['jour'], 2, '0', STR_PAD_LEFT);
        $dateFormatted = $jour . ' ' . $moisNom . ' ' . $row['annee'];
        
        $totalBW += (int)$row['total_noir_blanc'];
        $totalColor += (int)$row['total_couleur'];
        $totalPages += (int)$row['total_pages'];
        
        fputcsv($output, [
            $dateFormatted,
            $dateStr,
            number_format((int)$row['total_noir_blanc'], 0, ',', ' '),
            number_format((int)$row['total_couleur'], 0, ',', ' '),
            number_format((int)$row['total_pages'], 0, ',', ' ')
        ], ';');
    }
    
    // Ligne de totaux
    if (count($results) > 0) {
        fputcsv($output, []); // Ligne vide
        fputcsv($output, ['TOTAL', '', number_format($totalBW, 0, ',', ' '), number_format($totalColor, 0, ',', ' '), number_format($totalPages, 0, ',', ' ')], ';');
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    error_log('paiements_export_excel.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('paiements_export_excel.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}
