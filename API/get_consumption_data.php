<?php
// /API/get_consumption_data.php
// API pour récupérer les données de consommation avec filtres

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json; charset=utf-8');

// Fonction pour récupérer les données de consommation
function getConsumptionData($pdo, $clientId = null, $dateStart = null, $dateEnd = null) {
    $data = [
        'daily' => [],
        'monthly' => [],
        'yearly' => []
    ];
    
    try {
        $clientFilter = '';
        $params = [];
        
        if ($clientId) {
            $clientFilter = "AND pc.id_client = :client_id";
            $params[':client_id'] = (int)$clientId;
        }
        
        $dateFilter = '';
        if ($dateStart) {
            $dateFilter .= "AND DATE(cr.Timestamp) >= :date_start ";
            $params[':date_start'] = $dateStart;
        }
        if ($dateEnd) {
            $dateFilter .= "AND DATE(cr.Timestamp) <= :date_end ";
            $params[':date_end'] = $dateEnd;
        }
        
        // Données quotidiennes
        $sqlDaily = "
            SELECT 
                DATE(cr.Timestamp) as date,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY DATE(cr.Timestamp)
            ORDER BY date DESC
            LIMIT 365
        ";
        
        $stmt = $pdo->prepare($sqlDaily);
        $stmt->execute($params);
        $dailyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($dailyRows as $row) {
            $data['daily'][] = [
                'date' => $row['date'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
        // Données mensuelles
        $sqlMonthly = "
            SELECT 
                DATE_FORMAT(cr.Timestamp, '%Y-%m') as month,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY DATE_FORMAT(cr.Timestamp, '%Y-%m')
            ORDER BY month DESC
            LIMIT 24
        ";
        
        $stmt = $pdo->prepare($sqlMonthly);
        $stmt->execute($params);
        $monthlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($monthlyRows as $row) {
            $data['monthly'][] = [
                'month' => $row['month'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
        // Données annuelles
        $sqlYearly = "
            SELECT 
                YEAR(cr.Timestamp) as year,
                SUM(cr.TotalBW) as nb_pages,
                SUM(cr.TotalColor) as color_pages,
                SUM(cr.TotalBW + cr.TotalColor) as total_pages,
                SUM(cr.TotalBW) * 0.03 + SUM(cr.TotalColor) * 0.15 as amount
            FROM compteur_relevee cr
            LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm
            WHERE cr.Timestamp IS NOT NULL
            {$clientFilter}
            {$dateFilter}
            GROUP BY YEAR(cr.Timestamp)
            ORDER BY year DESC
            LIMIT 10
        ";
        
        $stmt = $pdo->prepare($sqlYearly);
        $stmt->execute($params);
        $yearlyRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($yearlyRows as $row) {
            $data['yearly'][] = [
                'year' => (string)$row['year'],
                'nb_pages' => (int)($row['nb_pages'] ?? 0),
                'color_pages' => (int)($row['color_pages'] ?? 0),
                'total_pages' => (int)($row['total_pages'] ?? 0),
                'amount' => round((float)($row['amount'] ?? 0), 2)
            ];
        }
        
    } catch (PDOException $e) {
        error_log('get_consumption_data.php - Erreur SQL: ' . $e->getMessage());
        throw $e;
    }
    
    return $data;
}

// Fonction pour calculer les estimations
function calculateEstimations($data) {
    $estimations = [
        'next_months' => [],
        'next_year' => null
    ];
    
    if (empty($data['monthly'])) {
        return $estimations;
    }
    
    $recentMonths = array_slice($data['monthly'], 0, 6);
    $recentMonths = array_reverse($recentMonths);
    
    if (count($recentMonths) < 2) {
        return $estimations;
    }
    
    $avgNB = 0;
    $avgColor = 0;
    $trendNB = 0;
    $trendColor = 0;
    
    foreach ($recentMonths as $month) {
        $avgNB += $month['nb_pages'];
        $avgColor += $month['color_pages'];
    }
    $avgNB = $avgNB / count($recentMonths);
    $avgColor = $avgColor / count($recentMonths);
    
    // Calcul de la tendance
    $n = count($recentMonths);
    $sumX = 0;
    $sumYNB = 0;
    $sumYColor = 0;
    $sumXYNB = 0;
    $sumXYColor = 0;
    $sumX2 = 0;
    
    for ($i = 0; $i < $n; $i++) {
        $x = $i;
        $yNB = $recentMonths[$i]['nb_pages'];
        $yColor = $recentMonths[$i]['color_pages'];
        
        $sumX += $x;
        $sumYNB += $yNB;
        $sumYColor += $yColor;
        $sumXYNB += $x * $yNB;
        $sumXYColor += $x * $yColor;
        $sumX2 += $x * $x;
    }
    
    if ($n > 1 && $sumX2 > 0) {
        $trendNB = ($n * $sumXYNB - $sumX * $sumYNB) / ($n * $sumX2 - $sumX * $sumX);
        $trendColor = ($n * $sumXYColor - $sumX * $sumYColor) / ($n * $sumX2 - $sumX * $sumX);
    }
    
    // Prévisions pour les 6 prochains mois
    $lastMonth = $recentMonths[count($recentMonths) - 1];
    $lastMonthDate = new DateTime($lastMonth['month'] . '-01');
    
    for ($i = 1; $i <= 6; $i++) {
        $forecastDate = clone $lastMonthDate;
        $forecastDate->modify("+$i months");
        $monthKey = $forecastDate->format('Y-m');
        
        $estimatedNB = max(0, $avgNB + ($trendNB * ($n + $i)));
        $estimatedColor = max(0, $avgColor + ($trendColor * ($n + $i)));
        $estimatedTotal = $estimatedNB + $estimatedColor;
        $estimatedAmount = round($estimatedNB * 0.03 + $estimatedColor * 0.15, 2);
        
        $estimations['next_months'][] = [
            'month' => $monthKey,
            'nb_pages' => (int)$estimatedNB,
            'color_pages' => (int)$estimatedColor,
            'total_pages' => (int)$estimatedTotal,
            'amount' => $estimatedAmount,
            'is_forecast' => true
        ];
    }
    
    // Estimation pour l'année prochaine
    $yearlyData = array_slice($data['yearly'], 0, 1);
    if (!empty($yearlyData)) {
        $currentYear = $yearlyData[0];
        $nextYear = (string)((int)$currentYear['year'] + 1);
        
        $estimations['next_year'] = [
            'year' => $nextYear,
            'nb_pages' => (int)($avgNB * 12),
            'color_pages' => (int)($avgColor * 12),
            'total_pages' => (int)(($avgNB + $avgColor) * 12),
            'amount' => round(($avgNB * 0.03 + $avgColor * 0.15) * 12, 2),
            'is_forecast' => true
        ];
    }
    
    return $estimations;
}

// Récupérer les paramètres
$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
$dateStart = isset($_GET['date_start']) ? $_GET['date_start'] : null;
$dateEnd = isset($_GET['date_end']) ? $_GET['date_end'] : null;

try {
    $data = getConsumptionData($pdo, $clientId, $dateStart, $dateEnd);
    $estimations = calculateEstimations($data);
    
    echo json_encode([
        'ok' => true,
        'data' => $data,
        'estimations' => $estimations
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
    
} catch (Exception $e) {
    error_log('get_consumption_data.php - Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur lors de la récupération des données'
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
}

