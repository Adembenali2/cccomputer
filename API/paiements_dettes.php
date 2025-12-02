<?php
// API pour calculer les dettes mensuelles des clients
// Période comptable : du 20 du mois au 20 du mois suivant
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

// Paramètres : mois et année (optionnels, par défaut mois courant)
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');

// Validation
if ($month < 1 || $month > 12) {
    $month = (int)date('m');
}
if ($year < 2020 || $year > 2100) {
    $year = (int)date('Y');
}

// Tarifs (en euros)
define('PRIX_BW_HT', 0.05);
define('PRIX_BW_TTC', 0.06);
define('PRIX_COLOR_HT', 0.09);
define('PRIX_COLOR_TTC', 0.11);

// Calculer la période comptable : du 20 du mois au 20 du mois suivant
try {
    $dateDebut = new DateTime("$year-$month-20");
} catch (Exception $e) {
    error_log('paiements_dettes.php - Erreur date début: ' . $e->getMessage());
    $dateDebut = new DateTime();
    $dateDebut->setDate((int)date('Y'), (int)date('m'), 20);
}

$dateFin = clone $dateDebut;
$dateFin->modify('+1 month'); // 20 du mois suivant

$dateDebutStr = $dateDebut->format('Y-m-d') . ' 00:00:00';
$dateFinStr = $dateFin->format('Y-m-d') . ' 23:59:59';

try {
    // ÉTAPE 1 : Récupérer tous les relevés pour trouver le premier compteur par MAC
    $sqlAllReleves = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $stmt = $pdo->prepare($sqlAllReleves);
    $stmt->execute();
    $allReleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Trouver le premier compteur pour chaque MAC (compteur de départ)
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
    
    // ÉTAPE 2 : Récupérer les relevés dans la période comptable (20 → 20)
    $sqlPeriodReleves = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start1 
              AND Timestamp <= :date_end1
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start2 
              AND Timestamp <= :date_end2
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $stmtPeriod = $pdo->prepare($sqlPeriodReleves);
    $stmtPeriod->execute([
        ':date_start1' => $dateDebutStr,
        ':date_end1' => $dateFinStr,
        ':date_start2' => $dateDebutStr,
        ':date_end2' => $dateFinStr
    ]);
    $periodReleves = $stmtPeriod->fetchAll(PDO::FETCH_ASSOC);
    
    // ÉTAPE 3 : Calculer les compteurs début/fin pour chaque MAC dans la période
    $macPeriodData = [];
    
    foreach ($periodReleves as $releve) {
        $mac = trim($releve['mac_norm'] ?? '');
        if (empty($mac) || empty($releve['Timestamp'])) {
            continue;
        }
        
        try {
            $timestamp = new DateTime($releve['Timestamp']);
        } catch (Exception $e) {
            error_log('paiements_dettes.php - Erreur parsing timestamp: ' . $e->getMessage());
            continue;
        }
        
        $bw = (int)($releve['TotalBW'] ?? 0);
        $color = (int)($releve['TotalColor'] ?? 0);
        
        if (!isset($macPeriodData[$mac])) {
            $macPeriodData[$mac] = [
                'start_bw' => $bw,
                'start_color' => $color,
                'start_timestamp' => $timestamp,
                'end_bw' => $bw,
                'end_color' => $color,
                'end_timestamp' => $timestamp
            ];
        } else {
            // Premier relevé = compteur début
            if ($timestamp < $macPeriodData[$mac]['start_timestamp']) {
                $macPeriodData[$mac]['start_bw'] = $bw;
                $macPeriodData[$mac]['start_color'] = $color;
                $macPeriodData[$mac]['start_timestamp'] = $timestamp;
            }
            // Dernier relevé = compteur fin
            if ($timestamp > $macPeriodData[$mac]['end_timestamp']) {
                $macPeriodData[$mac]['end_bw'] = $bw;
                $macPeriodData[$mac]['end_color'] = $color;
                $macPeriodData[$mac]['end_timestamp'] = $timestamp;
            }
        }
    }
    
    // Si pas de relevé dans la période, chercher le dernier relevé disponible avant ou dans la période
    // Pour chaque MAC qui n'a pas de relevé dans la période
    $allMacs = array_keys($firstCounters);
    foreach ($allMacs as $mac) {
        if (empty($mac) || isset($macPeriodData[$mac])) {
            continue;
        }
        
        // Chercher le dernier relevé disponible (avant ou dans la période)
        $sqlLastAvailable = "
            SELECT 
                mac_norm,
                Timestamp,
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor
            FROM (
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee
                WHERE mac_norm = :mac1 AND Timestamp <= :date_end1
                UNION ALL
                SELECT mac_norm, Timestamp, TotalBW, TotalColor
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac2 AND Timestamp <= :date_end2
            ) AS combined
            ORDER BY Timestamp DESC
            LIMIT 1
        ";
        
        try {
            $stmtLast = $pdo->prepare($sqlLastAvailable);
            $stmtLast->execute([
                ':mac1' => $mac,
                ':date_end1' => $dateFinStr,
                ':mac2' => $mac,
                ':date_end2' => $dateFinStr
            ]);
            $lastReleve = $stmtLast->fetch(PDO::FETCH_ASSOC);
            
            if ($lastReleve && !empty($lastReleve['Timestamp'])) {
                try {
                    $lastTimestamp = new DateTime($lastReleve['Timestamp']);
                    $macPeriodData[$mac] = [
                        'start_bw' => (int)($lastReleve['TotalBW'] ?? 0),
                        'start_color' => (int)($lastReleve['TotalColor'] ?? 0),
                        'start_timestamp' => $lastTimestamp,
                        'end_bw' => (int)($lastReleve['TotalBW'] ?? 0),
                        'end_color' => (int)($lastReleve['TotalColor'] ?? 0),
                        'end_timestamp' => $lastTimestamp
                    ];
                } catch (Exception $e) {
                    error_log('paiements_dettes.php - Erreur date pour MAC ' . $mac . ': ' . $e->getMessage());
                }
            } else {
                // Si vraiment aucun relevé, utiliser le compteur de départ
                if (isset($firstCounters[$mac])) {
                    $macPeriodData[$mac] = [
                        'start_bw' => $firstCounters[$mac]['bw'] ?? 0,
                        'start_color' => $firstCounters[$mac]['color'] ?? 0,
                        'start_timestamp' => $dateDebut,
                        'end_bw' => $firstCounters[$mac]['bw'] ?? 0,
                        'end_color' => $firstCounters[$mac]['color'] ?? 0,
                        'end_timestamp' => $dateDebut
                    ];
                }
            }
        } catch (PDOException $e) {
            error_log('paiements_dettes.php - Erreur SQL pour MAC ' . $mac . ': ' . $e->getMessage());
            // Continuer avec le compteur de départ si disponible
            if (isset($firstCounters[$mac])) {
                $macPeriodData[$mac] = [
                    'start_bw' => $firstCounters[$mac]['bw'] ?? 0,
                    'start_color' => $firstCounters[$mac]['color'] ?? 0,
                    'start_timestamp' => $dateDebut,
                    'end_bw' => $firstCounters[$mac]['bw'] ?? 0,
                    'end_color' => $firstCounters[$mac]['color'] ?? 0,
                    'end_timestamp' => $dateDebut
                ];
            }
        }
    }
    
    // ÉTAPE 4 : Récupérer les clients et leurs photocopieurs
    // Optimisation : remplacer les sous-requêtes corrélées par des LEFT JOIN
    $sqlClients = "
        SELECT 
            c.id as client_id,
            c.numero_client,
            c.raison_sociale,
            pc.mac_norm,
            pc.MacAddress,
            pc.SerialNumber,
            COALESCE(
                r1.Model,
                r2.Model,
                'Inconnu'
            ) as Model
        FROM clients c
        INNER JOIN photocopieurs_clients pc ON pc.id_client = c.id
        LEFT JOIN (
            SELECT mac_norm, Model, 
                   ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
            FROM compteur_relevee
            WHERE Model IS NOT NULL
        ) r1 ON r1.mac_norm = pc.mac_norm AND r1.rn = 1
        LEFT JOIN (
            SELECT mac_norm, Model,
                   ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) as rn
            FROM compteur_relevee_ancien
            WHERE Model IS NOT NULL
        ) r2 ON r2.mac_norm = pc.mac_norm AND r2.rn = 1
        WHERE pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
        ORDER BY c.raison_sociale, pc.mac_norm
    ";
    
    $stmtClients = $pdo->prepare($sqlClients);
    $stmtClients->execute();
    $clientsData = $stmtClients->fetchAll(PDO::FETCH_ASSOC);
    
    // ÉTAPE 5 : Calculer les dettes pour chaque client
    $dettes = [];
    
    foreach ($clientsData as $clientRow) {
        $clientId = $clientRow['client_id'];
        $mac = trim($clientRow['mac_norm'] ?? '');
        
        // Ignorer si MAC vide
        if (empty($mac)) {
            continue;
        }
        
        // Initialiser le client si pas encore dans le tableau
        if (!isset($dettes[$clientId])) {
            $dettes[$clientId] = [
                'client_id' => $clientId,
                'numero_client' => $clientRow['numero_client'] ?? '',
                'raison_sociale' => $clientRow['raison_sociale'] ?? '',
                'photocopieurs' => [],
                'total_ht' => 0,
                'total_ttc' => 0
            ];
        }
        
        // Récupérer les compteurs avec vérifications
        $firstBw = 0;
        $firstColor = 0;
        if (isset($firstCounters[$mac])) {
            $firstBw = (int)($firstCounters[$mac]['bw'] ?? 0);
            $firstColor = (int)($firstCounters[$mac]['color'] ?? 0);
        }
        
        $startBw = $firstBw;
        $startColor = $firstColor;
        $endBw = $firstBw;
        $endColor = $firstColor;
        
        if (isset($macPeriodData[$mac])) {
            $startBw = (int)($macPeriodData[$mac]['start_bw'] ?? $firstBw);
            $startColor = (int)($macPeriodData[$mac]['start_color'] ?? $firstColor);
            $endBw = (int)($macPeriodData[$mac]['end_bw'] ?? $firstBw);
            $endColor = (int)($macPeriodData[$mac]['end_color'] ?? $firstColor);
        }
        
        // Calculer la consommation (depuis le compteur de départ)
        $consumptionBw = max(0, $endBw - $firstBw);
        $consumptionColor = max(0, $endColor - $firstColor);
        
        // Calculer les montants
        $montantBwHt = $consumptionBw * PRIX_BW_HT;
        $montantBwTtc = $consumptionBw * PRIX_BW_TTC;
        $montantColorHt = $consumptionColor * PRIX_COLOR_HT;
        $montantColorTtc = $consumptionColor * PRIX_COLOR_TTC;
        
        $totalHt = $montantBwHt + $montantColorHt;
        $totalTtc = $montantBwTtc + $montantColorTtc;
        
        // Ajouter le photocopieur au client
        $dettes[$clientId]['photocopieurs'][] = [
            'mac_norm' => $mac,
            'mac_address' => $clientRow['MacAddress'] ?? '',
            'serial' => $clientRow['SerialNumber'] ?? '',
            'model' => $clientRow['Model'] ?? 'Inconnu',
            'compteur_depart_bw' => $firstBw,
            'compteur_depart_color' => $firstColor,
            'compteur_debut_bw' => $startBw,
            'compteur_debut_color' => $startColor,
            'compteur_fin_bw' => $endBw,
            'compteur_fin_color' => $endColor,
            'consumption_bw' => $consumptionBw,
            'consumption_color' => $consumptionColor,
            'montant_bw_ht' => round($montantBwHt, 2),
            'montant_bw_ttc' => round($montantBwTtc, 2),
            'montant_color_ht' => round($montantColorHt, 2),
            'montant_color_ttc' => round($montantColorTtc, 2),
            'total_ht' => round($totalHt, 2),
            'total_ttc' => round($totalTtc, 2)
        ];
        
        // Ajouter au total du client
        $dettes[$clientId]['total_ht'] += $totalHt;
        $dettes[$clientId]['total_ttc'] += $totalTtc;
    }
    
    // Arrondir les totaux
    foreach ($dettes as &$dette) {
        $dette['total_ht'] = round($dette['total_ht'], 2);
        $dette['total_ttc'] = round($dette['total_ttc'], 2);
    }
    unset($dette); // Libérer la référence
    
    // Convertir en tableau indexé
    $dettesArray = array_values($dettes);
    
    jsonResponse([
        'ok' => true,
        'dettes' => $dettesArray,
        'period' => [
            'month' => $month,
            'year' => $year,
            'date_debut' => $dateDebut->format('Y-m-d'),
            'date_fin' => $dateFin->format('Y-m-d'),
            'label' => $dateDebut->format('d/m/Y') . ' → ' . $dateFin->format('d/m/Y')
        ],
        'tarifs' => [
            'bw_ht' => PRIX_BW_HT,
            'bw_ttc' => PRIX_BW_TTC,
            'color_ht' => PRIX_COLOR_HT,
            'color_ttc' => PRIX_COLOR_TTC
        ]
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_dettes.php PDO error: ' . $e->getMessage());
    error_log('paiements_dettes.php SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    error_log('paiements_dettes.php Error Code: ' . ($e->errorInfo[1] ?? 'N/A'));
    error_log('paiements_dettes.php Error Message: ' . ($e->errorInfo[2] ?? 'N/A'));
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur base de données',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'sql_state' => $e->errorInfo[0] ?? null,
            'code' => $e->errorInfo[1] ?? null,
            'error_info' => $e->errorInfo[2] ?? null
        ] : null
    ], 500);
} catch (Throwable $e) {
    error_log('paiements_dettes.php error: ' . $e->getMessage());
    error_log('paiements_dettes.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => explode("\n", $e->getTraceAsString())
        ] : null
    ], 500);
}
