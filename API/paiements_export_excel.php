<?php
/**
 * API pour exporter les statistiques d'impression en Excel
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

try {
    $pdo = getPdo();
    
    // Récupération des paramètres
    $idClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : null;
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : null;
    
    // Construction de la requête SQL (identique à paiements_get_stats.php)
    $whereClause = " WHERE 1=1";
    $params = [];
    
    if ($idClient !== null && $idClient > 0) {
        $whereClause .= " AND pc.id_client = :id_client";
        $params[':id_client'] = $idClient;
    }
    
    if ($annee !== null && $annee > 0) {
        $whereClause .= " AND YEAR(cr.Timestamp) = :annee";
        $params[':annee'] = $annee;
    }
    
    if ($mois !== null && $mois > 0 && $mois <= 12) {
        if ($annee === null || $annee <= 0) {
            $annee = (int)date('Y');
            $whereClause .= " AND YEAR(cr.Timestamp) = :annee";
            $params[':annee'] = $annee;
        }
        $whereClause .= " AND MONTH(cr.Timestamp) = :mois";
        $params[':mois'] = $mois;
    }
    
    if ($annee === null && $mois === null) {
        $whereClause .= " AND cr.Timestamp >= DATE_SUB(NOW(), INTERVAL 12 MONTH)";
    }
    
    $sql = "
        SELECT 
            periode,
            annee,
            mois,
            COALESCE(SUM(diff_bw), 0) AS total_noir_blanc,
            COALESCE(SUM(diff_color), 0) AS total_couleur,
            COALESCE(SUM(diff_pages), 0) AS total_pages
        FROM (
            SELECT 
                DATE_FORMAT(cr.Timestamp, '%Y-%m') AS periode,
                YEAR(cr.Timestamp) AS annee,
                MONTH(cr.Timestamp) AS mois,
                cr.mac_norm,
                GREATEST(MAX(cr.TotalBW) - MIN(cr.TotalBW), 0) AS diff_bw,
                GREATEST(MAX(cr.TotalColor) - MIN(cr.TotalColor), 0) AS diff_color,
                GREATEST(MAX(cr.TotalPages) - MIN(cr.TotalPages), 0) AS diff_pages
            FROM compteur_relevee cr
            INNER JOIN photocopieurs_clients pc ON cr.mac_norm = pc.mac_norm
            " . $whereClause . "
            GROUP BY DATE_FORMAT(cr.Timestamp, '%Y-%m'), YEAR(cr.Timestamp), MONTH(cr.Timestamp), cr.mac_norm
        ) AS monthly_stats
        GROUP BY periode, annee, mois
        ORDER BY annee ASC, mois ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
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
    
    // Génération du fichier CSV (format Excel compatible)
    $filename = 'statistiques_impression_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    // BOM UTF-8 pour Excel
    echo "\xEF\xBB\xBF";
    
    // Ouvrir le flux de sortie
    $output = fopen('php://output', 'w');
    
    // En-têtes
    fputcsv($output, ['Statistiques d\'impression'], ';');
    fputcsv($output, ['Client: ' . $clientNom], ';');
    fputcsv($output, []); // Ligne vide
    fputcsv($output, ['Période', 'Noir et Blanc', 'Couleur', 'Total Pages'], ';');
    
    // Données
    $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 
                 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];
    
    foreach ($results as $row) {
        $moisNom = $moisNoms[(int)$row['mois']] ?? '';
        $periode = $moisNom . ' ' . $row['annee'];
        fputcsv($output, [
            $periode,
            $row['total_noir_blanc'],
            $row['total_couleur'],
            $row['total_pages']
        ], ';');
    }
    
    fclose($output);
    exit;
    
} catch (PDOException $e) {
    error_log('paiements_export_excel.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_export_excel.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

