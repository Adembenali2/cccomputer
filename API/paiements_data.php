<?php
// API pour récupérer les données de consommation de papier (pour la page Paiements)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

// Paramètres de filtrage
$period = trim($_GET['period'] ?? 'month'); // 'day', 'month', 'year'
$macFilter = trim($_GET['mac'] ?? ''); // MAC spécifique ou vide pour toute la flotte
$dateStart = trim($_GET['date_start'] ?? '');
$dateEnd = trim($_GET['date_end'] ?? '');

// Validation de la période
if (!in_array($period, ['day', 'month', 'year'], true)) {
    $period = 'month';
}

// Calculer les dates par défaut si non fournies
if (empty($dateStart) || empty($dateEnd)) {
    $endDate = new DateTime();
    $startDate = clone $endDate;
    
    switch ($period) {
        case 'day':
            $startDate->modify('-30 days'); // 30 derniers jours
            break;
        case 'month':
            $startDate->modify('-12 months'); // 12 derniers mois
            break;
        case 'year':
            $startDate->modify('-5 years'); // 5 dernières années
            break;
    }
    
    $dateStart = $startDate->format('Y-m-d');
    $dateEnd = $endDate->format('Y-m-d');
}

// Normaliser la MAC si fournie
$macNorm = null;
if (!empty($macFilter)) {
    $macNorm = strtoupper(preg_replace('/[^0-9A-F]/', '', $macFilter));
    if (strlen($macNorm) !== 12) {
        jsonResponse(['ok' => false, 'error' => 'Format MAC invalide'], 400);
    }
}

try {
    // Construire la requête pour récupérer les relevés des deux tables
    // On utilise UNION ALL pour combiner les deux tables
    $sql = "
        SELECT 
            mac_norm,
            Timestamp,
            TotalBW,
            TotalColor,
            Model,
            MacAddress
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start 
              AND Timestamp <= :date_end
              " . ($macNorm ? "AND mac_norm = :mac_norm" : "") . "
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start 
              AND Timestamp <= :date_end
              " . ($macNorm ? "AND mac_norm = :mac_norm" : "") . "
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $params = [
        ':date_start' => $dateStart . ' 00:00:00',
        ':date_end' => $dateEnd . ' 23:59:59'
    ];
    
    if ($macNorm) {
        $params[':mac_norm'] = $macNorm;
    }
    
    $stmt->execute($params);
    $releves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculer la consommation (différence entre relevés successifs)
    $consumption = []; // Structure: [period_label => ['bw' => total, 'color' => total]]
    $lastValues = []; // Structure: [mac_norm => ['bw' => value, 'color' => value, 'timestamp' => datetime]]
    
    foreach ($releves as $releve) {
        $mac = $releve['mac_norm'];
        $timestamp = new DateTime($releve['Timestamp']);
        $bw = (int)($releve['TotalBW'] ?? 0);
        $color = (int)($releve['TotalColor'] ?? 0);
        
        // Déterminer la période selon le filtre
        $periodLabel = '';
        switch ($period) {
            case 'day':
                $periodLabel = $timestamp->format('Y-m-d');
                break;
            case 'month':
                $periodLabel = $timestamp->format('Y-m');
                break;
            case 'year':
                $periodLabel = $timestamp->format('Y');
                break;
        }
        
        // Si on a déjà une valeur précédente pour cette MAC, calculer la différence
        if (isset($lastValues[$mac])) {
            $lastBw = $lastValues[$mac]['bw'];
            $lastColor = $lastValues[$mac]['color'];
            
            // Calculer la différence (consommation)
            $diffBw = max(0, $bw - $lastBw); // Éviter les valeurs négatives
            $diffColor = max(0, $color - $lastColor);
            
            // Ajouter à la consommation de la période
            if (!isset($consumption[$periodLabel])) {
                $consumption[$periodLabel] = ['bw' => 0, 'color' => 0];
            }
            $consumption[$periodLabel]['bw'] += $diffBw;
            $consumption[$periodLabel]['color'] += $diffColor;
        }
        
        // Mettre à jour la dernière valeur pour cette MAC
        $lastValues[$mac] = [
            'bw' => $bw,
            'color' => $color,
            'timestamp' => $timestamp
        ];
    }
    
    // Trier les périodes chronologiquement
    ksort($consumption);
    
    // Formater les données pour le graphique
    $labels = [];
    $bwData = [];
    $colorData = [];
    
    foreach ($consumption as $periodLabel => $data) {
        $labels[] = $periodLabel;
        $bwData[] = $data['bw'];
        $colorData[] = $data['color'];
    }
    
    // Récupérer la liste des photocopieurs pour le filtre
    $photocopieurs = [];
    $sqlPhotocopieurs = "
        SELECT DISTINCT
            COALESCE(pc.mac_norm, r.mac_norm) as mac_norm,
            COALESCE(pc.MacAddress, r.MacAddress) as MacAddress,
            COALESCE(pc.SerialNumber, r.SerialNumber) as SerialNumber,
            COALESCE(r.Model, 'Inconnu') as Model,
            COALESCE(c.raison_sociale, 'Photocopieur non attribué') as client_name,
            pc.id_client
        FROM (
            SELECT mac_norm, MacAddress, SerialNumber, Model
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
            UNION
            SELECT mac_norm, MacAddress, SerialNumber, Model
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) AS r
        LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = r.mac_norm
        LEFT JOIN clients c ON c.id = pc.id_client
        ORDER BY client_name, Model, MacAddress
    ";
    
    $stmtPhotocopieurs = $pdo->prepare($sqlPhotocopieurs);
    $stmtPhotocopieurs->execute();
    $photocopieursRaw = $stmtPhotocopieurs->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($photocopieursRaw as $p) {
        $photocopieurs[] = [
            'mac_norm' => $p['mac_norm'],
            'mac_address' => $p['MacAddress'],
            'serial' => $p['SerialNumber'],
            'model' => $p['Model'],
            'client_name' => $p['client_name'],
            'label' => ($p['client_name'] ? $p['client_name'] . ' - ' : '') . 
                      ($p['Model'] ? $p['Model'] : 'Inconnu') . 
                      ($p['MacAddress'] ? ' (' . $p['MacAddress'] . ')' : '')
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'data' => [
            'labels' => $labels,
            'bw' => $bwData,
            'color' => $colorData,
            'total_bw' => array_sum($bwData),
            'total_color' => array_sum($colorData)
        ],
        'photocopieurs' => $photocopieurs,
        'filters' => [
            'period' => $period,
            'mac' => $macFilter,
            'date_start' => $dateStart,
            'date_end' => $dateEnd
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_data.php PDO error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('paiements_data.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()], 500);
}

