<?php
// API pour récupérer les clients avec leur consommation et dettes
// Calcule selon les règles : N&B 0.05€ si > 1000 copies/mois, Couleur 0.09€
// Période de facturation : du 20 du mois au 20 du mois suivant
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

// Tarifs (en euros)
define('PRIX_BW', 0.05);      // 0.05€ par copie N&B si > 1000 copies/mois
define('PRIX_COLOR', 0.09);   // 0.09€ par copie couleur
define('SEUIL_BW', 1000);     // Seuil de 1000 copies pour N&B

/**
 * Calcule la période de facturation (20→20) pour un mois donné
 */
function getBillingPeriod($year, $month) {
    $dateStart = new DateTime("$year-$month-20 00:00:00");
    $dateEnd = clone $dateStart;
    $dateEnd->modify('+1 month');
    return [
        'start' => $dateStart,
        'end' => $dateEnd,
        'label' => $dateStart->format('d/m/Y') . ' → ' . $dateEnd->format('d/m/Y')
    ];
}

/**
 * Trouve le premier compteur de la période de facturation pour une MAC
 */
function getFirstCounterInPeriod($pdo, $macNorm, DateTime $periodStart) {
    $sql = "
        SELECT 
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee
            WHERE mac_norm = :mac AND Timestamp >= :period_start
            UNION ALL
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac AND Timestamp >= :period_start
        ) AS combined
        ORDER BY Timestamp ASC
        LIMIT 1
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':mac' => $macNorm,
        ':period_start' => $periodStart->format('Y-m-d H:i:s')
    ]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        return null;
    }
    
    return [
        'bw' => (int)($result['TotalBW'] ?? 0),
        'color' => (int)($result['TotalColor'] ?? 0)
    ];
}

/**
 * Calcule la consommation pour une période donnée
 * @param bool $monthly Si true, calcule la consommation mensuelle (différence entre début et fin)
 *                       Si false, calcule la consommation cumulée depuis le premier compteur
 */
function calculateConsumption($pdo, $macNorm, $periodStart, $periodEnd, $firstCounters, $monthly = false) {
    // Récupérer le relevé au début de la période
    $sqlStart = "
        SELECT 
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee
            WHERE mac_norm = :mac AND Timestamp <= :period_start
            UNION ALL
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac AND Timestamp <= :period_start
        ) AS combined
        ORDER BY Timestamp DESC
        LIMIT 1
    ";
    
    // Récupérer le relevé à la fin de la période
    $sqlEnd = "
        SELECT 
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee
            WHERE mac_norm = :mac AND Timestamp <= :period_end
            UNION ALL
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac AND Timestamp <= :period_end
        ) AS combined
        ORDER BY Timestamp DESC
        LIMIT 1
    ";
    
    $stmtStart = $pdo->prepare($sqlStart);
    $stmtStart->execute([
        ':mac' => $macNorm,
        ':period_start' => $periodStart->format('Y-m-d H:i:s')
    ]);
    $resultStart = $stmtStart->fetch(PDO::FETCH_ASSOC);
    
    $stmtEnd = $pdo->prepare($sqlEnd);
    $stmtEnd->execute([
        ':mac' => $macNorm,
        ':period_end' => $periodEnd->format('Y-m-d H:i:s')
    ]);
    $resultEnd = $stmtEnd->fetch(PDO::FETCH_ASSOC);
    
    if (!$resultEnd) {
        return ['bw' => 0, 'color' => 0];
    }
    
    $endBw = (int)($resultEnd['TotalBW'] ?? 0);
    $endColor = (int)($resultEnd['TotalColor'] ?? 0);
    
    if ($monthly) {
        // Consommation mensuelle : différence entre début et fin de période
        // Le début de période doit être le premier compteur à partir du 20 du mois (début de période)
        // Si pas de relevé au début exact, chercher le premier relevé dans la période
        if (!$resultStart) {
            // Chercher le premier relevé dans la période
            $sqlFirstInPeriod = "
                SELECT 
                    COALESCE(TotalBW, 0) as TotalBW,
                    COALESCE(TotalColor, 0) as TotalColor
                FROM (
                    SELECT mac_norm, Timestamp, TotalBW, TotalColor
                    FROM compteur_relevee
                    WHERE mac_norm = :mac AND Timestamp >= :period_start AND Timestamp <= :period_end
                    UNION ALL
                    SELECT mac_norm, Timestamp, TotalBW, TotalColor
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = :mac AND Timestamp >= :period_start AND Timestamp <= :period_end
                ) AS combined
                ORDER BY Timestamp ASC
                LIMIT 1
            ";
            
            $stmtFirst = $pdo->prepare($sqlFirstInPeriod);
            $stmtFirst->execute([
                ':mac' => $macNorm,
                ':period_start' => $periodStart->format('Y-m-d H:i:s'),
                ':period_end' => $periodEnd->format('Y-m-d H:i:s')
            ]);
            $resultStart = $stmtFirst->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$resultStart) {
            // Si vraiment aucun relevé dans la période, consommation = 0
            return ['bw' => 0, 'color' => 0];
        }
        
        $startBw = (int)($resultStart['TotalBW'] ?? 0);
        $startColor = (int)($resultStart['TotalColor'] ?? 0);
        
        return [
            'bw' => max(0, $endBw - $startBw),
            'color' => max(0, $endColor - $startColor)
        ];
    } else {
        // Consommation cumulée depuis le premier compteur de la période
        // On utilise le premier compteur à partir du début de période (20 du mois)
        $firstCounter = getFirstCounterInPeriod($pdo, $macNorm, $periodStart);
        if ($firstCounter === null) {
            // Fallback : utiliser le premier compteur global
            $firstBw = $firstCounters[$macNorm]['bw'] ?? 0;
            $firstColor = $firstCounters[$macNorm]['color'] ?? 0;
        } else {
            $firstBw = $firstCounter['bw'];
            $firstColor = $firstCounter['color'];
        }
        
        return [
            'bw' => max(0, $endBw - $firstBw),
            'color' => max(0, $endColor - $firstColor)
        ];
    }
}

/**
 * Calcule la dette selon les règles de tarification
 */
function calculateDebt($consumptionBw, $consumptionColor) {
    $debt = 0;
    // N&B : 0.05€ si > 1000 copies/mois
    if ($consumptionBw > SEUIL_BW) {
        $debt += $consumptionBw * PRIX_BW;
    }
    // Couleur : 0.09€ par copie
    $debt += $consumptionColor * PRIX_COLOR;
    return round($debt, 2);
}

try {
    // Récupérer tous les clients
    $sqlClients = "
        SELECT 
            c.id,
            c.numero_client,
            c.raison_sociale,
            c.adresse,
            c.code_postal,
            c.ville,
            c.email,
            c.telephone1
        FROM clients c
        ORDER BY c.raison_sociale ASC
    ";
    
    $stmtClients = $pdo->prepare($sqlClients);
    $stmtClients->execute();
    $clientsRaw = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer tous les relevés pour trouver le premier compteur par MAC
    $sqlAllReleves = "
        SELECT 
            mac_norm,
            MIN(Timestamp) as first_timestamp,
            (
                SELECT TotalBW 
                FROM compteur_relevee 
                WHERE mac_norm = t.mac_norm AND Timestamp = t.first_ts
                LIMIT 1
            ) as first_bw,
            (
                SELECT TotalColor 
                FROM compteur_relevee 
                WHERE mac_norm = t.mac_norm AND Timestamp = t.first_ts
                LIMIT 1
            ) as first_color
        FROM (
            SELECT 
                mac_norm,
                MIN(Timestamp) as first_ts
            FROM (
                SELECT mac_norm, Timestamp
                FROM compteur_relevee
                WHERE mac_norm IS NOT NULL AND mac_norm != ''
                UNION ALL
                SELECT mac_norm, Timestamp
                FROM compteur_relevee_ancien
                WHERE mac_norm IS NOT NULL AND mac_norm != ''
            ) AS all_releves
            GROUP BY mac_norm
        ) AS t
    ";
    
    // Approche simplifiée : récupérer tous les relevés triés
    $sqlAllRelevesSimple = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
            UNION ALL
            SELECT mac_norm, Timestamp, TotalBW, TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $stmt = $pdo->prepare($sqlAllRelevesSimple);
    $stmt->execute();
    $allReleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Trouver le premier compteur pour chaque MAC
    $firstCounters = [];
    foreach ($allReleves as $releve) {
        $mac = trim($releve['mac_norm'] ?? '');
        if (empty($mac)) {
            continue;
        }
        if (!isset($firstCounters[$mac])) {
            $firstCounters[$mac] = [
                'bw' => (int)($releve['TotalBW'] ?? 0),
                'color' => (int)($releve['TotalColor'] ?? 0)
            ];
        }
    }
    
    // Récupérer les photocopieurs par client
    $sqlPhotocopieurs = "
        SELECT 
            pc.id_client,
            pc.mac_norm,
            pc.MacAddress,
            pc.SerialNumber
        FROM photocopieurs_clients pc
        WHERE pc.id_client IS NOT NULL
          AND pc.mac_norm IS NOT NULL 
          AND pc.mac_norm != ''
    ";
    
    $stmtPhotocopieurs = $pdo->prepare($sqlPhotocopieurs);
    $stmtPhotocopieurs->execute();
    $photocopieursRaw = $stmtPhotocopieurs->fetchAll(PDO::FETCH_ASSOC);
    
    // Grouper les photocopieurs par client
    $photocopieursByClient = [];
    foreach ($photocopieursRaw as $photo) {
        $clientId = (int)($photo['id_client'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        if (!isset($photocopieursByClient[$clientId])) {
            $photocopieursByClient[$clientId] = [];
        }
        $photocopieursByClient[$clientId][] = [
            'mac_norm' => trim($photo['mac_norm'] ?? ''),
            'mac_address' => $photo['MacAddress'] ?? '',
            'serial' => $photo['SerialNumber'] ?? ''
        ];
    }
    
    // Période actuelle (mois en cours selon règle 20→20)
    $today = new DateTime();
    $currentDay = (int)$today->format('d');
    $currentMonth = (int)$today->format('m');
    $currentYear = (int)$today->format('Y');
    
    // Déterminer la période de facturation actuelle
    if ($currentDay >= 20) {
        // Période : 20 du mois courant → 20 du mois suivant
        $currentPeriod = getBillingPeriod($currentYear, $currentMonth);
    } else {
        // Période : 20 du mois précédent → 20 du mois courant
        $prevMonth = $currentMonth - 1;
        $prevYear = $currentYear;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        $currentPeriod = getBillingPeriod($prevYear, $prevMonth);
    }
    
    // Construire la réponse avec les clients
    $clients = [];
    
    foreach ($clientsRaw as $clientRow) {
        $clientId = (int)($clientRow['id'] ?? 0);
        if ($clientId <= 0) {
            continue;
        }
        
        $clientPhotos = $photocopieursByClient[$clientId] ?? [];
        
        // Calculer la consommation mensuelle totale pour ce client (somme de tous ses photocopieurs)
        // Pour la période actuelle (20→20), on calcule la différence entre début et fin
        $totalBw = 0;
        $totalColor = 0;
        
        foreach ($clientPhotos as $photo) {
            $mac = $photo['mac_norm'];
            if (!isset($firstCounters[$mac])) {
                continue;
            }
            
            $consumption = calculateConsumption(
                $pdo,
                $mac,
                $currentPeriod['start'],
                $currentPeriod['end'],
                $firstCounters,
                true // monthly = true pour consommation mensuelle
            );
            
            $totalBw += $consumption['bw'];
            $totalColor += $consumption['color'];
        }
        
        // Calculer la dette selon les règles
        $debt = calculateDebt($totalBw, $totalColor);
        
        // Générer l'historique (12 derniers mois)
        $history = [];
        for ($i = 0; $i < 12; $i++) {
            $histDate = clone $today;
            $histDate->modify("-$i months");
            $histDay = (int)$histDate->format('d');
            $histMonth = (int)$histDate->format('m');
            $histYear = (int)$histDate->format('Y');
            
            // Déterminer la période de facturation pour ce mois
            if ($histDay >= 20) {
                $histPeriod = getBillingPeriod($histYear, $histMonth);
            } else {
                $prevMonth = $histMonth - 1;
                $prevYear = $histYear;
                if ($prevMonth < 1) {
                    $prevMonth = 12;
                    $prevYear--;
                }
                $histPeriod = getBillingPeriod($prevYear, $prevMonth);
            }
            
            // Calculer la consommation pour cette période (mensuelle : différence entre début et fin)
            $histBw = 0;
            $histColor = 0;
            
            foreach ($clientPhotos as $photo) {
                $mac = $photo['mac_norm'];
                if (!isset($firstCounters[$mac])) {
                    continue;
                }
                
                $consumption = calculateConsumption(
                    $pdo,
                    $mac,
                    $histPeriod['start'],
                    $histPeriod['end'],
                    $firstCounters,
                    true // monthly = true pour calculer la différence mensuelle
                );
                
                $histBw += $consumption['bw'];
                $histColor += $consumption['color'];
            }
            
            // Calculer la dette pour cette période
            $histDebt = calculateDebt($histBw, $histColor);
            
            $history[] = [
                'period_label' => $histPeriod['label'],
                'period_start' => $histPeriod['start']->format('Y-m-d'),
                'period_end' => $histPeriod['end']->format('Y-m-d'),
                'consumption_bw' => $histBw,
                'consumption_color' => $histColor,
                'debt' => $histDebt,
                'facture_url' => null // TODO: Générer l'URL de facture si disponible
            ];
        }
        
        // Inverser l'historique pour avoir les plus récents en premier
        $history = array_reverse($history);
        
        $clients[] = [
            'id' => $clientId,
            'numero_client' => $clientRow['numero_client'] ?? '',
            'raison_sociale' => $clientRow['raison_sociale'] ?? '',
            'adresse' => $clientRow['adresse'] ?? '',
            'code_postal' => $clientRow['code_postal'] ?? '',
            'ville' => $clientRow['ville'] ?? '',
            'email' => $clientRow['email'] ?? '',
            'telephone1' => $clientRow['telephone1'] ?? '',
            'consumption_bw' => $totalBw,
            'consumption_color' => $totalColor,
            'debt' => $debt,
            'balance' => round(-$debt, 2), // Solde = dette négative (à payer)
            'history' => $history
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'clients' => $clients
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_clients.php PDO error: ' . $e->getMessage());
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données'
    ], 500);
} catch (Throwable $e) {
    error_log('paiements_clients.php error: ' . $e->getMessage());
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur'
    ], 500);
}
