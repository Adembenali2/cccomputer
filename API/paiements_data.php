<?php
// API pour récupérer les données de consommation de papier (pour la page Paiements)
// NOUVELLE LOGIQUE : Calcul de la consommation depuis le premier compteur enregistré pour chaque MAC
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
    // Nettoyer la MAC : enlever tous les caractères non hexadécimaux
    $macNorm = strtoupper(preg_replace('/[^0-9A-F]/', '', $macFilter));
    
    // Si la MAC nettoyée est vide ou invalide, essayer d'autres formats
    if (empty($macNorm)) {
        // Si c'est un nombre pur, essayer de le convertir en hexadécimal
        if (is_numeric($macFilter)) {
            $macNorm = strtoupper(dechex((int)$macFilter));
        } else {
            jsonResponse(['ok' => false, 'error' => 'Format MAC invalide'], 400);
        }
    }
    
    // Si la MAC fait moins de 12 caractères mais est valide, compléter avec des zéros à gauche
    if (strlen($macNorm) < 12 && preg_match('/^[0-9A-F]+$/', $macNorm)) {
        $macNorm = str_pad($macNorm, 12, '0', STR_PAD_LEFT);
    }
    
    // Vérifier que la MAC normalisée fait exactement 12 caractères hexadécimaux
    if (strlen($macNorm) !== 12 || !preg_match('/^[0-9A-F]{12}$/', $macNorm)) {
        jsonResponse(['ok' => false, 'error' => 'Format MAC invalide. Format attendu: 12 caractères hexadécimaux (ex: AABBCCDDEEFF ou AA:BB:CC:DD:EE:FF). Reçu: ' . htmlspecialchars($macFilter)], 400);
    }
}

/**
 * Fonction pour obtenir la période de facturation (20→20) pour une date donnée
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
 * Trouve le premier compteur de la période de facturation pour une MAC
 */
function getFirstCounterInPeriod($pdo, $macNorm, DateTime $periodStart) {
    if (empty($macNorm) || !($periodStart instanceof DateTime)) {
        return null;
    }
    
    try {
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
        if (!$stmt) {
            error_log('paiements_data.php - Erreur préparation requête getFirstCounterInPeriod');
            return null;
        }
        
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
    } catch (Exception $e) {
        error_log('paiements_data.php - Exception dans getFirstCounterInPeriod: ' . $e->getMessage());
        return null;
    }
}

try {
    // Initialiser les variables importantes
    $firstCounters = [];
    $releves = [];
    $consumption = [];
    
    // ÉTAPE 1 : Trouver le premier compteur (compteur de départ) pour chaque MAC
    // On cherche dans les deux tables et on prend le plus ancien pour chaque MAC
    $sqlFirstCounters = "
        SELECT 
            mac_norm,
            MIN(Timestamp) as first_timestamp,
            (
                SELECT TotalBW 
                FROM (
                    SELECT mac_norm, Timestamp, TotalBW
                    FROM compteur_relevee
                    WHERE mac_norm = t.mac_norm AND Timestamp = t.min_ts
                    UNION ALL
                    SELECT mac_norm, Timestamp, TotalBW
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = t.mac_norm AND Timestamp = t.min_ts
                    ORDER BY Timestamp ASC
                    LIMIT 1
                ) AS first_bw
            ) as first_bw,
            (
                SELECT TotalColor 
                FROM (
                    SELECT mac_norm, Timestamp, TotalColor
                    FROM compteur_relevee
                    WHERE mac_norm = t.mac_norm AND Timestamp = t.min_ts
                    UNION ALL
                    SELECT mac_norm, Timestamp, TotalColor
                    FROM compteur_relevee_ancien
                    WHERE mac_norm = t.mac_norm AND Timestamp = t.min_ts
                    ORDER BY Timestamp ASC
                    LIMIT 1
                ) AS first_color
            ) as first_color
        FROM (
            SELECT 
                mac_norm,
                MIN(Timestamp) as min_ts
            FROM (
                SELECT mac_norm, Timestamp
                FROM compteur_relevee
                WHERE mac_norm IS NOT NULL AND mac_norm != ''
                " . ($macNorm ? "AND mac_norm = :mac_norm_base" : "") . "
                UNION ALL
                SELECT mac_norm, Timestamp
                FROM compteur_relevee_ancien
                WHERE mac_norm IS NOT NULL AND mac_norm != ''
                " . ($macNorm ? "AND mac_norm = :mac_norm_base" : "") . "
            ) AS all_releves
            GROUP BY mac_norm
        ) AS t
        GROUP BY mac_norm
    ";
    
    // Approche simplifiée : récupérer tous les relevés triés, puis trouver le premier par MAC
    $sqlAllReleves = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor,
            Model,
            MacAddress
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              " . ($macNorm ? "AND mac_norm = :mac_norm1" : "") . "
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              " . ($macNorm ? "AND mac_norm = :mac_norm2" : "") . "
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $params = [];
    if ($macNorm) {
        $params[':mac_norm1'] = $macNorm;
        $params[':mac_norm2'] = $macNorm;
    }
    
    $stmt = $pdo->prepare($sqlAllReleves);
    $stmt->execute($params);
    $allReleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Trouver le premier compteur pour chaque MAC (compteur de départ)
    $firstCounters = []; // [mac_norm => ['bw' => value, 'color' => value]]
    foreach ($allReleves as $releve) {
        $mac = $releve['mac_norm'];
        if (!isset($firstCounters[$mac])) {
            $firstCounters[$mac] = [
                'bw' => (int)($releve['TotalBW'] ?? 0),
                'color' => (int)($releve['TotalColor'] ?? 0)
            ];
        }
    }
    
    // ÉTAPE 2 : Filtrer les relevés dans la période demandée
    $dateStartFull = $dateStart . ' 00:00:00';
    $dateEndFull = $dateEnd . ' 23:59:59';
    
    $sqlFiltered = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor,
            Model,
            MacAddress
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start1 
              AND Timestamp <= :date_end1
              " . ($macNorm ? "AND mac_norm = :mac_norm1" : "") . "
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start2 
              AND Timestamp <= :date_end2
              " . ($macNorm ? "AND mac_norm = :mac_norm2" : "") . "
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $paramsFiltered = [
        ':date_start1' => $dateStartFull,
        ':date_end1' => $dateEndFull,
        ':date_start2' => $dateStartFull,
        ':date_end2' => $dateEndFull
    ];
    
    if ($macNorm) {
        $paramsFiltered[':mac_norm1'] = $macNorm;
        $paramsFiltered[':mac_norm2'] = $macNorm;
    }
    
    $stmtFiltered = $pdo->prepare($sqlFiltered);
    $stmtFiltered->execute($paramsFiltered);
    $releves = $stmtFiltered->fetchAll(PDO::FETCH_ASSOC);
    
    // ÉTAPE 3 : Calculer la consommation réelle (compteur_actuel - compteur_depart_de_la_periode)
    $consumption = []; // Structure: [period_label => ['bw' => total, 'color' => total]]
    
    // Cache pour les premiers compteurs de période (évite les requêtes multiples)
    $periodFirstCountersCache = []; // [mac_period_key => ['bw' => value, 'color' => value]]
    
    foreach ($releves as $releve) {
        // Ignorer les relevés sans MAC ou sans timestamp valide
        if (empty($releve['mac_norm']) || empty($releve['Timestamp'])) {
            continue;
        }
        
        $mac = $releve['mac_norm'];
        
        // Gérer les erreurs de date
        try {
            $timestamp = new DateTime($releve['Timestamp']);
        } catch (Exception $e) {
            error_log('paiements_data.php - Date invalide pour MAC ' . $mac . ': ' . $releve['Timestamp']);
            continue;
        }
        
        $currentBw = (int)($releve['TotalBW'] ?? 0);
        $currentColor = (int)($releve['TotalColor'] ?? 0);
        
        // Déterminer la période de facturation pour ce relevé (20→20)
        $billingPeriod = getBillingPeriodForDate($timestamp);
        $periodKey = $mac . '_' . $billingPeriod['start']->format('Y-m-d');
        
        // Récupérer ou mettre en cache le premier compteur de cette période
        if (!isset($periodFirstCountersCache[$periodKey])) {
            try {
                $firstCounter = getFirstCounterInPeriod($pdo, $mac, $billingPeriod['start']);
                if ($firstCounter === null) {
                    // Si pas de compteur dans la période, utiliser le premier compteur global comme fallback
                    $periodFirstCountersCache[$periodKey] = [
                        'bw' => $firstCounters[$mac]['bw'] ?? 0,
                        'color' => $firstCounters[$mac]['color'] ?? 0
                    ];
                } else {
                    $periodFirstCountersCache[$periodKey] = $firstCounter;
                }
            } catch (Exception $e) {
                error_log('paiements_data.php - Erreur getFirstCounterInPeriod pour MAC ' . $mac . ': ' . $e->getMessage());
                // En cas d'erreur, utiliser le premier compteur global comme fallback
                $periodFirstCountersCache[$periodKey] = [
                    'bw' => $firstCounters[$mac]['bw'] ?? 0,
                    'color' => $firstCounters[$mac]['color'] ?? 0
                ];
            }
        }
        
        if (!isset($periodFirstCountersCache[$periodKey])) {
            continue; // Ignorer ce relevé si on ne peut pas trouver le compteur de départ
        }
        
        $firstBw = $periodFirstCountersCache[$periodKey]['bw'] ?? 0;
        $firstColor = $periodFirstCountersCache[$periodKey]['color'] ?? 0;
        
        // Calculer la consommation réelle : compteur_actuel - compteur_depart_de_la_periode
        $consumptionBw = max(0, $currentBw - $firstBw);
        $consumptionColor = max(0, $currentColor - $firstColor);
        
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
        
        // Pour chaque période, on garde la consommation maximale (le dernier relevé de la période)
        // Cela évite de compter plusieurs fois la même période
        if (!isset($consumption[$periodLabel])) {
            $consumption[$periodLabel] = [
                'bw' => $consumptionBw,
                'color' => $consumptionColor,
                'timestamp' => $timestamp
            ];
        } else {
            // Si on a un relevé plus récent dans la même période, on prend celui-ci
            if ($timestamp > $consumption[$periodLabel]['timestamp']) {
                $consumption[$periodLabel] = [
                    'bw' => $consumptionBw,
                    'color' => $consumptionColor,
                    'timestamp' => $timestamp
                ];
            }
        }
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
            SELECT DISTINCT mac_norm, MacAddress, SerialNumber, Model
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
            UNION
            SELECT DISTINCT mac_norm, MacAddress, SerialNumber, Model
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) AS r
        LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = r.mac_norm
        LEFT JOIN clients c ON c.id = pc.id_client
        ORDER BY client_name, Model, MacAddress
    ";
    
    try {
        $stmtPhotocopieurs = $pdo->prepare($sqlPhotocopieurs);
        $stmtPhotocopieurs->execute();
        $photocopieursRaw = $stmtPhotocopieurs->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('paiements_data.php - Erreur récupération photocopieurs: ' . $e->getMessage());
        $photocopieursRaw = [];
    }
    
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
            'total_bw' => !empty($bwData) ? max($bwData) : 0, // Consommation totale = maximum atteint
            'total_color' => !empty($colorData) ? max($colorData) : 0
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
    error_log('paiements_data.php SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    error_log('paiements_data.php Error Code: ' . ($e->errorInfo[1] ?? 'N/A'));
    error_log('paiements_data.php Error Message: ' . ($e->errorInfo[2] ?? 'N/A'));
    error_log('paiements_data.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    
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
    error_log('paiements_data.php error: ' . $e->getMessage());
    error_log('paiements_data.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('paiements_data.php trace: ' . $e->getTraceAsString());
    
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
