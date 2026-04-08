<?php
/**
 * API pour récupérer les consommations d'un client pour une période donnée
 */

// Désactiver l'affichage des erreurs PHP (on veut du JSON propre)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// S'assurer que le header JSON est bien défini (au cas où auth.php aurait fait un output)
header('Content-Type: application/json; charset=utf-8');

$clientId = filter_input(INPUT_GET, 'client_id', FILTER_VALIDATE_INT);
$offre = filter_input(INPUT_GET, 'offre', FILTER_VALIDATE_INT);
$dateDebut = filter_input(INPUT_GET, 'date_debut', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: filter_input(INPUT_GET, 'date_debut', FILTER_UNSAFE_RAW);
$dateFin = filter_input(INPUT_GET, 'date_fin', FILTER_SANITIZE_FULL_SPECIAL_CHARS) ?: filter_input(INPUT_GET, 'date_fin', FILTER_UNSAFE_RAW);

// Nettoyer et valider les dates
$dateDebut = trim($dateDebut ?? '');
$dateFin = trim($dateFin ?? '');

if (!$clientId || !$offre || !$dateDebut || !$dateFin) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres manquants: client_id, offre, date_debut et date_fin sont requis'], 400);
}

// Valider le format des dates (YYYY-MM-DD)
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
    jsonResponse(['ok' => false, 'error' => 'Format de date invalide (attendu: YYYY-MM-DD)'], 400);
}

if (!in_array($offre, [1000, 2000], true)) {
    jsonResponse(['ok' => false, 'error' => 'Offre invalide (doit être 1000 ou 2000)'], 400);
}

try {
    $pdo = getPdo();
    // Activer le mode d'erreur pour voir les erreurs SQL
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Vérifier le nombre de photocopieurs
    $stmt = $pdo->prepare("SELECT COUNT(*) as nb FROM photocopieurs_clients WHERE id_client = :client_id");
    $stmt->execute([':client_id' => $clientId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nbPhotocopieurs = (int)($result['nb'] ?? 0);
    
    if ($offre === 2000 && $nbPhotocopieurs !== 2) {
        jsonResponse(['ok' => false, 'error' => "L'offre 2000 nécessite exactement 2 photocopieurs. Ce client en a {$nbPhotocopieurs}."], 400);
    }
    
    if ($nbPhotocopieurs === 0) {
        jsonResponse(['ok' => false, 'error' => 'Aucun photocopieur trouvé pour ce client'], 400);
    }
    
    // Récupérer les photocopieurs du client avec leurs dernières infos
    $stmt = $pdo->prepare("
        SELECT 
            pc.id,
            pc.SerialNumber,
            pc.MacAddress,
            pc.mac_norm
        FROM photocopieurs_clients pc
        WHERE pc.id_client = :client_id
        ORDER BY pc.id
        LIMIT 2
    ");
    $stmt->execute([':client_id' => $clientId]);
    $photocopieursRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer les infos les plus récentes pour chaque photocopieur (dans les deux tables)
    $photocopieurs = [];
    foreach ($photocopieursRaw as $pc) {
        $macNorm = $pc['mac_norm'];
        
        // Récupérer les infos les plus récentes du photocopieur (recherche dans les deux tables)
        // Chercher d'abord dans compteur_relevee
        $stmtInfo1 = $pdo->prepare("
            SELECT Nom, Model, Timestamp
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtInfo1->execute([':mac_norm' => $macNorm]);
        $info1 = $stmtInfo1->fetch(PDO::FETCH_ASSOC);
        
        // Chercher ensuite dans compteur_relevee_ancien
        $stmtInfo2 = $pdo->prepare("
            SELECT Nom, Model, Timestamp
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac_norm
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtInfo2->execute([':mac_norm' => $macNorm]);
        $info2 = $stmtInfo2->fetch(PDO::FETCH_ASSOC);
        
        // Prendre la plus récente des deux
        $info = null;
        if ($info1 && $info2) {
            $ts1 = strtotime($info1['Timestamp']);
            $ts2 = strtotime($info2['Timestamp']);
            $info = ($ts1 >= $ts2) ? $info1 : $info2;
        } elseif ($info1) {
            $info = $info1;
        } elseif ($info2) {
            $info = $info2;
        }
        
        $photocopieurs[] = [
            'id' => $pc['id'],
            'SerialNumber' => $pc['SerialNumber'],
            'MacAddress' => $pc['MacAddress'],
            'mac_norm' => $macNorm,
            'nom' => !empty($info['Nom']) ? $info['Nom'] : ('Imprimante ' . $pc['id']),
            'modele' => !empty($info['Model']) ? $info['Model'] : 'Inconnu'
        ];
    }
    
    $machines = [];
    
    foreach ($photocopieurs as $pc) {
        $macNorm = $pc['mac_norm'] ?? '';
        // Vérifier que mac_norm n'est pas vide
        if (empty($macNorm) || strlen(trim($macNorm)) === 0) {
            error_log("Photocopieur ID {$pc['id']} n'a pas de mac_norm valide");
            continue;
        }
        $macNorm = trim($macNorm);
        
        // Récupérer le PREMIER relevé du jour de début (recherche dans les deux tables)
        // Chercher d'abord dans compteur_relevee
        $stmtStart1 = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) = :date_debut
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp ASC
            LIMIT 1
        ");
        $stmtStart1->execute([
            ':mac_norm' => $macNorm,
            ':date_debut' => $dateDebut
        ]);
        $startReleve1 = $stmtStart1->fetch(PDO::FETCH_ASSOC);
        
        // Chercher ensuite dans compteur_relevee_ancien
        $stmtStart2 = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) = :date_debut
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp ASC
            LIMIT 1
        ");
        $stmtStart2->execute([
            ':mac_norm' => $macNorm,
            ':date_debut' => $dateDebut
        ]);
        $startReleve2 = $stmtStart2->fetch(PDO::FETCH_ASSOC);
        
        // Prendre le plus ancien des deux (premier du jour)
        $startReleve = null;
        $startDateAjustee = false;
        if ($startReleve1 && $startReleve2) {
            // Comparer les timestamps
            $ts1 = strtotime($startReleve1['Timestamp']);
            $ts2 = strtotime($startReleve2['Timestamp']);
            $startReleve = ($ts1 <= $ts2) ? $startReleve1 : $startReleve2;
        } elseif ($startReleve1) {
            $startReleve = $startReleve1;
        } elseif ($startReleve2) {
            $startReleve = $startReleve2;
        } else {
            // Aucun relevé à la date exacte : chercher le dernier relevé avant cette date
            $stmtStartFallback1 = $pdo->prepare("
                SELECT 
                    COALESCE(TotalBW, 0) as TotalBW,
                    COALESCE(TotalColor, 0) as TotalColor,
                    Timestamp
                FROM compteur_relevee
                WHERE mac_norm = :mac_norm
                  AND DATE(Timestamp) < :date_debut
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
                ORDER BY Timestamp DESC
                LIMIT 1
            ");
            $stmtStartFallback1->execute([
                ':mac_norm' => $macNorm,
                ':date_debut' => $dateDebut
            ]);
            $startFallback1 = $stmtStartFallback1->fetch(PDO::FETCH_ASSOC);
            
            $stmtStartFallback2 = $pdo->prepare("
                SELECT 
                    COALESCE(TotalBW, 0) as TotalBW,
                    COALESCE(TotalColor, 0) as TotalColor,
                    Timestamp
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac_norm
                  AND DATE(Timestamp) < :date_debut
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
                ORDER BY Timestamp DESC
                LIMIT 1
            ");
            $stmtStartFallback2->execute([
                ':mac_norm' => $macNorm,
                ':date_debut' => $dateDebut
            ]);
            $startFallback2 = $stmtStartFallback2->fetch(PDO::FETCH_ASSOC);
            
            // Prendre le plus récent des deux (dernier avant la date)
            if ($startFallback1 && $startFallback2) {
                $ts1 = strtotime($startFallback1['Timestamp']);
                $ts2 = strtotime($startFallback2['Timestamp']);
                $startReleve = ($ts1 >= $ts2) ? $startFallback1 : $startFallback2;
            } elseif ($startFallback1) {
                $startReleve = $startFallback1;
            } elseif ($startFallback2) {
                $startReleve = $startFallback2;
            }
            
            if ($startReleve) {
                $startDateAjustee = true;
            }
        }
        
        // Récupérer le DERNIER relevé du jour de fin (recherche dans les deux tables)
        // Chercher d'abord dans compteur_relevee
        $stmtEnd1 = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM compteur_relevee
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) = :date_fin
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtEnd1->execute([
            ':mac_norm' => $macNorm,
            ':date_fin' => $dateFin
        ]);
        $endReleve1 = $stmtEnd1->fetch(PDO::FETCH_ASSOC);
        
        // Chercher ensuite dans compteur_relevee_ancien
        $stmtEnd2 = $pdo->prepare("
            SELECT 
                COALESCE(TotalBW, 0) as TotalBW,
                COALESCE(TotalColor, 0) as TotalColor,
                Timestamp
            FROM compteur_relevee_ancien
            WHERE mac_norm = :mac_norm
              AND DATE(Timestamp) = :date_fin
              AND mac_norm IS NOT NULL
              AND mac_norm != ''
            ORDER BY Timestamp DESC
            LIMIT 1
        ");
        $stmtEnd2->execute([
            ':mac_norm' => $macNorm,
            ':date_fin' => $dateFin
        ]);
        $endReleve2 = $stmtEnd2->fetch(PDO::FETCH_ASSOC);
        
        // Prendre le plus récent des deux (dernier du jour)
        $endReleve = null;
        $endDateAjustee = false;
        if ($endReleve1 && $endReleve2) {
            // Comparer les timestamps
            $ts1 = strtotime($endReleve1['Timestamp']);
            $ts2 = strtotime($endReleve2['Timestamp']);
            $endReleve = ($ts1 >= $ts2) ? $endReleve1 : $endReleve2;
        } elseif ($endReleve1) {
            $endReleve = $endReleve1;
        } elseif ($endReleve2) {
            $endReleve = $endReleve2;
        } else {
            // Aucun relevé à la date exacte : chercher le dernier relevé avant cette date
            $stmtEndFallback1 = $pdo->prepare("
                SELECT 
                    COALESCE(TotalBW, 0) as TotalBW,
                    COALESCE(TotalColor, 0) as TotalColor,
                    Timestamp
                FROM compteur_relevee
                WHERE mac_norm = :mac_norm
                  AND DATE(Timestamp) < :date_fin
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
                ORDER BY Timestamp DESC
                LIMIT 1
            ");
            $stmtEndFallback1->execute([
                ':mac_norm' => $macNorm,
                ':date_fin' => $dateFin
            ]);
            $endFallback1 = $stmtEndFallback1->fetch(PDO::FETCH_ASSOC);
            
            $stmtEndFallback2 = $pdo->prepare("
                SELECT 
                    COALESCE(TotalBW, 0) as TotalBW,
                    COALESCE(TotalColor, 0) as TotalColor,
                    Timestamp
                FROM compteur_relevee_ancien
                WHERE mac_norm = :mac_norm
                  AND DATE(Timestamp) < :date_fin
                  AND mac_norm IS NOT NULL
                  AND mac_norm != ''
                ORDER BY Timestamp DESC
                LIMIT 1
            ");
            $stmtEndFallback2->execute([
                ':mac_norm' => $macNorm,
                ':date_fin' => $dateFin
            ]);
            $endFallback2 = $stmtEndFallback2->fetch(PDO::FETCH_ASSOC);
            
            // Prendre le plus récent des deux (dernier avant la date)
            if ($endFallback1 && $endFallback2) {
                $ts1 = strtotime($endFallback1['Timestamp']);
                $ts2 = strtotime($endFallback2['Timestamp']);
                $endReleve = ($ts1 >= $ts2) ? $endFallback1 : $endFallback2;
            } elseif ($endFallback1) {
                $endReleve = $endFallback1;
            } elseif ($endFallback2) {
                $endReleve = $endFallback2;
            }
            
            if ($endReleve) {
                $endDateAjustee = true;
            }
        }
        
        // Calculer les consommations
        $compteurDebutNB = 0;
        $compteurDebutCouleur = 0;
        $compteurFinNB = 0;
        $compteurFinCouleur = 0;
        $consoNB = 0;
        $consoColor = 0;
        
        if ($startReleve) {
            $compteurDebutNB = (int)$startReleve['TotalBW'];
            $compteurDebutCouleur = (int)$startReleve['TotalColor'];
        }
        
        if ($endReleve) {
            $compteurFinNB = (int)$endReleve['TotalBW'];
            $compteurFinCouleur = (int)$endReleve['TotalColor'];
        }
        
        // Calcul : compteur fin - compteur début
        if ($startReleve && $endReleve) {
            $consoNB = max(0, $compteurFinNB - $compteurDebutNB);
            $consoColor = max(0, $compteurFinCouleur - $compteurDebutCouleur);
        }
        
        $machines[] = [
            'id' => $pc['id'],
            'nom' => $pc['nom'],
            'modele' => $pc['modele'],
            'mac_norm' => $macNorm,
            'compteur_debut_nb' => $compteurDebutNB,
            'compteur_debut_couleur' => $compteurDebutCouleur,
            'compteur_fin_nb' => $compteurFinNB,
            'compteur_fin_couleur' => $compteurFinCouleur,
            'conso_nb' => $consoNB,
            'conso_couleur' => $consoColor,
            'date_debut_releve' => $startReleve ? $startReleve['Timestamp'] : null,
            'date_fin_releve' => $endReleve ? $endReleve['Timestamp'] : null,
            'date_debut_ajustee' => $startDateAjustee,
            'date_fin_ajustee' => $endDateAjustee
        ];
    }
    
    // Vérifier si des dates ont été ajustées
    $datesAjustees = [];
    foreach ($machines as $machine) {
        if ($machine['date_debut_ajustee']) {
            $dateAjustee = date('d/m/Y', strtotime($machine['date_debut_releve']));
            $datesAjustees[] = [
                'type' => 'debut',
                'date_demandee' => date('d/m/Y', strtotime($dateDebut)),
                'date_utilisee' => $dateAjustee,
                'machine' => $machine['nom']
            ];
        }
        if ($machine['date_fin_ajustee']) {
            $dateAjustee = date('d/m/Y', strtotime($machine['date_fin_releve']));
            $datesAjustees[] = [
                'type' => 'fin',
                'date_demandee' => date('d/m/Y', strtotime($dateFin)),
                'date_utilisee' => $dateAjustee,
                'machine' => $machine['nom']
            ];
        }
    }
    
    jsonResponse([
        'ok' => true,
        'offre' => $offre,
        'nb_photocopieurs' => count($machines),
        'machines' => $machines,
        'dates_ajustees' => $datesAjustees,
        'date_debut_demandee' => $dateDebut,
        'date_fin_demandee' => $dateFin
    ]);
    
} catch (PDOException $e) {
    $errorInfo = $e->errorInfo ?? [];
    error_log('Erreur PDO factures_get_consommation: ' . $e->getMessage());
    error_log('SQL State: ' . ($errorInfo[0] ?? 'N/A'));
    error_log('Driver Code: ' . ($errorInfo[1] ?? 'N/A'));
    error_log('Driver Message: ' . ($errorInfo[2] ?? 'N/A'));
    error_log('Trace: ' . $e->getTraceAsString());
    
    // En mode développement, retourner plus de détails
    $isDev = true; // Toujours activer pour le debug
    $driverMessage = $errorInfo[2] ?? $e->getMessage();
    $errorMsg = $isDev 
        ? 'Erreur base de données: ' . $driverMessage . ' (SQL State: ' . ($errorInfo[0] ?? 'N/A') . ', Code: ' . ($errorInfo[1] ?? 'N/A') . ')'
        : 'Erreur base de données lors du calcul des consommations';
    
    jsonResponse(['ok' => false, 'error' => $errorMsg, 'debug' => $isDev ? [
        'sql_state' => $errorInfo[0] ?? null,
        'driver_code' => $errorInfo[1] ?? null,
        'driver_message' => $errorInfo[2] ?? null,
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ] : null], 500);
} catch (Exception $e) {
    error_log('Erreur factures_get_consommation: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());
    
    $isDev = (getenv('APP_ENV') ?: 'production') === 'development';
    $errorMsg = $isDev 
        ? 'Erreur: ' . $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')'
        : 'Erreur lors du calcul des consommations';
    
    jsonResponse(['ok' => false, 'error' => $errorMsg], 500);
} catch (Throwable $e) {
    error_log('Erreur fatale factures_get_consommation: ' . $e->getMessage());
    error_log('File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('Trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur fatale lors du calcul des consommations'], 500);
}
?>
