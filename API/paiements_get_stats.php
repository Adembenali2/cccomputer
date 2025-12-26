<?php
/**
 * API pour récupérer les statistiques d'impression (noir et blanc, couleur, total pages)
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
    
    // Construction de la requête SQL
    // On récupère les données mensuelles d'impression par client
    // Pour chaque mois, on calcule la différence entre le MAX et le MIN (consommation du mois)
    $whereClause = " WHERE 1=1";
    $params = [];
    
    // Filtre par client
    if ($idClient !== null && $idClient > 0) {
        $whereClause .= " AND pc.id_client = :id_client";
        $params[':id_client'] = $idClient;
    }
    
    // Filtre par année
    if ($annee !== null && $annee > 0) {
        $whereClause .= " AND YEAR(cr.Timestamp) = :annee";
        $params[':annee'] = $annee;
    }
    
    // Filtre par mois (nécessite aussi l'année)
    if ($mois !== null && $mois > 0 && $mois <= 12) {
        if ($annee === null || $annee <= 0) {
            // Si mois spécifié sans année, utiliser l'année en cours
            $annee = (int)date('Y');
            $whereClause .= " AND YEAR(cr.Timestamp) = :annee";
            $params[':annee'] = $annee;
        }
        $whereClause .= " AND MONTH(cr.Timestamp) = :mois";
        $params[':mois'] = $mois;
    }
    
    // Si aucun filtre de date, limiter aux 12 derniers mois
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
    
    // Formatage des données pour le graphique
    $data = [
        'labels' => [],
        'noir_blanc' => [],
        'couleur' => [],
        'total_pages' => []
    ];
    
    foreach ($results as $row) {
        // Format de label : "Jan 2024"
        $moisNoms = ['', 'Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Jun', 'Jul', 'Aoû', 'Sep', 'Oct', 'Nov', 'Déc'];
        $moisNom = $moisNoms[(int)$row['mois']] ?? '';
        $label = $moisNom . ' ' . $row['annee'];
        
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
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_get_stats.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

