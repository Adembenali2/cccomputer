<?php
// API pour récupérer les clients avec leur consommation et dettes
// Calcule selon les règles : N&B 0.05€ si > 1000 copies/mois, Couleur 0.09€
// Période de facturation : du 20 du mois au 20 du mois suivant
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/includes/paiements_helpers.php';

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
    // Initialiser les variables importantes
    $photocopieursByClient = [];
    $clients = [];
    
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
        // Pour la période actuelle (20→20), on utilise calculatePeriodConsumption
        $totalBw = 0;
        $totalColor = 0;
        
        foreach ($clientPhotos as $photo) {
            $mac = $photo['mac_norm'];
            if (empty($mac)) {
                continue;
            }
            
            $consumption = calculatePeriodConsumption(
                $pdo,
                $mac,
                $currentPeriod['start'],
                $currentPeriod['end']
            );
            
            $totalBw += $consumption['bw'] ?? 0;
            $totalColor += $consumption['color'] ?? 0;
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
            
            // Calculer la consommation pour cette période selon la logique 20→20
            $histBw = 0;
            $histColor = 0;
            $histStartCounters = ['bw' => 0, 'color' => 0];
            $histEndCounters = ['bw' => 0, 'color' => 0];
            $histStartDate = null;
            $histEndDate = null;
            
            foreach ($clientPhotos as $photo) {
                $mac = $photo['mac_norm'];
                if (empty($mac)) {
                    continue;
                }
                
                $consumption = calculatePeriodConsumption(
                    $pdo,
                    $mac,
                    $histPeriod['start'],
                    $histPeriod['end']
                );
                
                $histBw += $consumption['bw'] ?? 0;
                $histColor += $consumption['color'] ?? 0;
                
                // Agréger les compteurs de départ et de fin (pour la première MAC, on prend ses valeurs)
                if ($histStartDate === null && isset($consumption['start_counter'])) {
                    $histStartCounters['bw'] = $consumption['start_counter']['bw'] ?? 0;
                    $histStartCounters['color'] = $consumption['start_counter']['color'] ?? 0;
                    if (isset($consumption['start_counter']['timestamp'])) {
                        $histStartDate = $consumption['start_counter']['timestamp'];
                    }
                }
                
                if (isset($consumption['end_counter'])) {
                    // Pour la fin, on prend les compteurs de la dernière MAC (ou on pourrait sommer)
                    $histEndCounters['bw'] = max($histEndCounters['bw'], $consumption['end_counter']['bw'] ?? 0);
                    $histEndCounters['color'] = max($histEndCounters['color'], $consumption['end_counter']['color'] ?? 0);
                    if (isset($consumption['end_counter']['timestamp'])) {
                        $endTimestamp = $consumption['end_counter']['timestamp'];
                        if ($histEndDate === null || $endTimestamp > $histEndDate) {
                            $histEndDate = $endTimestamp;
                        }
                    }
                }
            }
            
            // Calculer la dette pour cette période
            $histDebt = calculateDebt($histBw, $histColor);
            
            $history[] = [
                'period_label' => $histPeriod['label'],
                'period_start' => $histPeriod['start']->format('Y-m-d'),
                'period_end' => $histPeriod['end']->format('Y-m-d'),
                'counter_start_bw' => $histStartCounters['bw'],
                'counter_start_color' => $histStartCounters['color'],
                'counter_start_date' => $histStartDate ? $histStartDate->format('Y-m-d H:i:s') : null,
                'counter_end_bw' => $histEndCounters['bw'],
                'counter_end_color' => $histEndCounters['color'],
                'counter_end_date' => $histEndDate ? $histEndDate->format('Y-m-d H:i:s') : null,
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
    error_log('paiements_clients.php SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    error_log('paiements_clients.php Error Code: ' . ($e->errorInfo[1] ?? 'N/A'));
    error_log('paiements_clients.php Error Message: ' . ($e->errorInfo[2] ?? 'N/A'));
    error_log('paiements_clients.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données: ' . htmlspecialchars($e->getMessage()),
        'debug' => [
            'message' => $e->getMessage(),
            'sql_state' => $e->errorInfo[0] ?? null,
            'code' => $e->errorInfo[1] ?? null,
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ], 500);
} catch (Throwable $e) {
    error_log('paiements_clients.php error: ' . $e->getMessage());
    error_log('paiements_clients.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('paiements_clients.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur: ' . htmlspecialchars($e->getMessage()),
        'debug' => [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ]
    ], 500);
}
