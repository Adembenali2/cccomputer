<?php
// Fonctions utilitaires partagées pour le calcul des paiements et consommations
// Logique : compteur de départ = compteur du 20 ou dernier avant le 20
//           compteur de fin = compteur du 20 suivant ou dernier avant/égal au 20 suivant

/**
 * Obtient la période de facturation (20→20) pour une date donnée
 */
function getBillingPeriodForDate(DateTime $date) {
    $year = (int)$date->format('Y');
    $month = (int)$date->format('m');
    $day = (int)$date->format('d');
    
    // Si on est avant le 20, la période commence le 20 du mois précédent
    if ($day < 20) {
        $periodStart = new DateTime("$year-$month-20 00:00:00");
        $periodStart->modify('-1 month');
        $periodEnd = new DateTime("$year-$month-20 23:59:59");
    } else {
        // Sinon, la période commence le 20 du mois courant
        $periodStart = new DateTime("$year-$month-20 00:00:00");
        $periodEnd = clone $periodStart;
        $periodEnd->modify('+1 month');
    }
    
    return [
        'start' => $periodStart,
        'end' => $periodEnd
    ];
}

/**
 * Trouve le compteur de DÉPART de la période (idéalement du 20, sinon dernier avant le 20)
 * @param PDO $pdo
 * @param string $macNorm
 * @param DateTime $periodStart Date de début de période (20 du mois)
 * @return array|null ['bw' => int, 'color' => int, 'timestamp' => DateTime] ou null
 */
function getPeriodStartCounter($pdo, $macNorm, DateTime $periodStart) {
    if (empty($macNorm) || !($periodStart instanceof DateTime)) {
        return null;
    }
    
    try {
        // Date de référence : 20 du mois à 00:00:00
        $periodStartDate = $periodStart->format('Y-m-d');
        $periodStartStr = $periodStartDate . ' 00:00:00';
        $periodStartEndStr = $periodStartDate . ' 23:59:59';
        
        // 1. D'abord, chercher le compteur exactement du 20 (jour entier)
        $sqlAt20 = "
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 
                  AND DATE(Timestamp) = :period_date1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 
                  AND DATE(Timestamp) = :period_date2
            ) AS combined
            ORDER BY Timestamp ASC
            LIMIT 1
        ";
        
        $stmtAt20 = $pdo->prepare($sqlAt20);
        if ($stmtAt20) {
            $stmtAt20->execute([
                ':mac1' => $macNorm,
                ':period_date1' => $periodStartDate,
                ':mac2' => $macNorm,
                ':period_date2' => $periodStartDate
            ]);
            $resultAt20 = $stmtAt20->fetch(PDO::FETCH_ASSOC);
            
            if ($resultAt20 && !empty($resultAt20['Timestamp'])) {
                return [
                    'bw' => (int)($resultAt20['TotalBW'] ?? 0),
                    'color' => (int)($resultAt20['TotalColor'] ?? 0),
                    'timestamp' => new DateTime($resultAt20['Timestamp'])
                ];
            }
        }
        
        // 2. Si pas de compteur du 20, chercher le premier compteur APRÈS le 20
        $sqlAfter = "
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 AND Timestamp > :period_start_end1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 AND Timestamp > :period_start_end2
            ) AS combined
            ORDER BY Timestamp ASC
            LIMIT 1
        ";
        
        $stmtAfter = $pdo->prepare($sqlAfter);
        if ($stmtAfter) {
            $stmtAfter->execute([
                ':mac1' => $macNorm,
                ':period_start_end1' => $periodStartEndStr,
                ':mac2' => $macNorm,
                ':period_start_end2' => $periodStartEndStr
            ]);
            $resultAfter = $stmtAfter->fetch(PDO::FETCH_ASSOC);
            
            if ($resultAfter && !empty($resultAfter['Timestamp'])) {
                return [
                    'bw' => (int)($resultAfter['TotalBW'] ?? 0),
                    'color' => (int)($resultAfter['TotalColor'] ?? 0),
                    'timestamp' => new DateTime($resultAfter['Timestamp'])
                ];
            }
        }
        
        // 3. Si pas de compteur au 20 ou après, chercher le dernier AVANT le 20
        $sqlBefore = "
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 AND Timestamp < :period_start1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 AND Timestamp < :period_start2
            ) AS combined
            ORDER BY Timestamp DESC
            LIMIT 1
        ";
        
        $stmtBefore = $pdo->prepare($sqlBefore);
        if (!$stmtBefore) {
            return null;
        }
        
        $stmtBefore->execute([
            ':mac1' => $macNorm,
            ':period_start1' => $periodStartStr,
            ':mac2' => $macNorm,
            ':period_start2' => $periodStartStr
        ]);
        $resultBefore = $stmtBefore->fetch(PDO::FETCH_ASSOC);
        
        if ($resultBefore && !empty($resultBefore['Timestamp'])) {
            return [
                'bw' => (int)($resultBefore['TotalBW'] ?? 0),
                'color' => (int)($resultBefore['TotalColor'] ?? 0),
                'timestamp' => new DateTime($resultBefore['Timestamp'])
            ];
        }
        
        return null;
    } catch (Exception $e) {
        error_log('getPeriodStartCounter error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Trouve le compteur de FIN de la période (idéalement du 20 suivant, sinon dernier avant/égal au 20 suivant)
 * @param PDO $pdo
 * @param string $macNorm
 * @param DateTime $periodEnd Date de fin de période (20 du mois suivant)
 * @return array|null ['bw' => int, 'color' => int, 'timestamp' => DateTime] ou null
 */
function getPeriodEndCounter($pdo, $macNorm, DateTime $periodEnd) {
    if (empty($macNorm) || !($periodEnd instanceof DateTime)) {
        return null;
    }
    
    try {
        // Date de référence : 20 du mois suivant
        $periodEndDate = $periodEnd->format('Y-m-d');
        $periodEndStr = $periodEndDate . ' 23:59:59';
        
        // 1. D'abord, chercher le compteur exactement du 20 (jour entier)
        $sqlAt20 = "
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 
                  AND DATE(Timestamp) = :period_date1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 
                  AND DATE(Timestamp) = :period_date2
            ) AS combined
            ORDER BY Timestamp DESC
            LIMIT 1
        ";
        
        $stmtAt20 = $pdo->prepare($sqlAt20);
        if ($stmtAt20) {
            $stmtAt20->execute([
                ':mac1' => $macNorm,
                ':period_date1' => $periodEndDate,
                ':mac2' => $macNorm,
                ':period_date2' => $periodEndDate
            ]);
            $resultAt20 = $stmtAt20->fetch(PDO::FETCH_ASSOC);
            
            if ($resultAt20 && !empty($resultAt20['Timestamp'])) {
                return [
                    'bw' => (int)($resultAt20['TotalBW'] ?? 0),
                    'color' => (int)($resultAt20['TotalColor'] ?? 0),
                    'timestamp' => new DateTime($resultAt20['Timestamp'])
                ];
            }
        }
        
        // 2. Si pas de compteur du 20, chercher le dernier compteur AVANT ou ÉGAL au 20
        $sql = "
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 AND Timestamp <= :period_end1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 AND Timestamp <= :period_end2
            ) AS combined
            ORDER BY Timestamp DESC
            LIMIT 1
        ";
        
        $stmt = $pdo->prepare($sql);
        if (!$stmt) {
            return null;
        }
        
        $stmt->execute([
            ':mac1' => $macNorm,
            ':period_end1' => $periodEndStr,
            ':mac2' => $macNorm,
            ':period_end2' => $periodEndStr,
            ':period_end' => $periodEndStr
        ]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && !empty($result['Timestamp'])) {
            return [
                'bw' => (int)($result['TotalBW'] ?? 0),
                'color' => (int)($result['TotalColor'] ?? 0),
                'timestamp' => new DateTime($result['Timestamp'])
            ];
        }
        
        return null;
    } catch (Exception $e) {
        error_log('getPeriodEndCounter error: ' . $e->getMessage());
        return null;
    }
}

/**
 * Calcule la consommation pour une période donnée selon la logique 20→20
 * @param PDO $pdo
 * @param string $macNorm
 * @param DateTime $periodStart Date de début de période (20 du mois)
 * @param DateTime $periodEnd Date de fin de période (20 du mois suivant)
 * @return array ['bw' => int, 'color' => int, 'start_counter' => array, 'end_counter' => array] ou ['bw' => 0, 'color' => 0]
 */
function calculatePeriodConsumption($pdo, $macNorm, DateTime $periodStart, DateTime $periodEnd) {
    $startCounter = getPeriodStartCounter($pdo, $macNorm, $periodStart);
    $endCounter = getPeriodEndCounter($pdo, $macNorm, $periodEnd);
    
    if (!$startCounter || !$endCounter) {
        return [
            'bw' => 0,
            'color' => 0,
            'start_counter' => $startCounter,
            'end_counter' => $endCounter
        ];
    }
    
    $consumptionBw = max(0, $endCounter['bw'] - $startCounter['bw']);
    $consumptionColor = max(0, $endCounter['color'] - $startCounter['color']);
    
    return [
        'bw' => $consumptionBw,
        'color' => $consumptionColor,
        'start_counter' => $startCounter,
        'end_counter' => $endCounter
    ];
}

