#!/usr/bin/env php
<?php
/**
 * scripts/import_ionos_cron.php
 * Script CLI pour importer les relevés depuis l'endpoint IONOS
 * 
 * Usage: php scripts/import_ionos_cron.php
 * 
 * Fonctionnalités:
 * - Lock MySQL pour éviter les doubles exécutions
 * - Curseur pour reprendre là où on s'est arrêté
 * - Import max 20 lignes par exécution
 * - Logging dans import_run
 * 
 * Fréquence recommandée: toutes les 1 minute (cron/Railway scheduler)
 */

declare(strict_types=1);

// Configuration
$projectRoot = dirname(__DIR__);
$maxItems = 20;
$ionosUrl = 'https://cccomputer.fr/test_compteur.php';
$lockName = 'import_ionos';
$startTime = microtime(true);

// Charger autoload Composer si présent
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (file_exists($autoloadPath)) {
    require_once $autoloadPath;
}

// Charger DatabaseConnection
require_once $projectRoot . '/includes/db_connection.php';

// Fonction de logging
function logMessage(string $message): void {
    $timestamp = date('Y-m-d H:i:s');
    echo "[$timestamp] $message\n";
    error_log("[IMPORT IONOS] $message");
}

// Fonction pour logger dans import_run
function logToImportRun(PDO $pdo, array $data): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), :imported, :skipped, :ok, :msg)
        ");
        $stmt->execute([
            ':imported' => $data['imported'] ?? 0,
            ':skipped' => $data['skipped'] ?? 0,
            ':ok' => $data['ok'] ? 1 : 0,
            ':msg' => json_encode($data['msg'] ?? [], JSON_UNESCAPED_UNICODE)
        ]);
    } catch (Throwable $e) {
        logMessage("ERREUR lors du logging dans import_run: " . $e->getMessage());
    }
}

// Fonction pour normaliser MAC (identique à la colonne générée mac_norm)
function normalizeMac(string $mac): string {
    return strtoupper(str_replace(':', '', $mac));
}

// Fonction pour comparer deux relevés (timestamp + mac)
function compareReleve(array $a, array $b): int {
    // Comparer d'abord par timestamp
    $tsA = strtotime($a['Timestamp'] ?? '');
    $tsB = strtotime($b['Timestamp'] ?? '');
    if ($tsA !== $tsB) {
        return $tsA <=> $tsB;
    }
    // Si timestamp égal, comparer par MAC
    $macA = normalizeMac($a['MacAddress'] ?? '');
    $macB = normalizeMac($b['MacAddress'] ?? '');
    return strcmp($macA, $macB);
}

try {
    logMessage("=== DÉBUT IMPORT IONOS ===");
    
    // Connexion DB
    $pdo = DatabaseConnection::getInstance();
    logMessage("Connexion DB établie");
    
    // Créer la table ionos_cursor si nécessaire
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `ionos_cursor` (
          `id` tinyint NOT NULL DEFAULT 1,
          `last_ts` datetime DEFAULT NULL,
          `last_mac` char(12) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
          PRIMARY KEY (`id`),
          CONSTRAINT `chk_single_row` CHECK (`id` = 1)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    $pdo->exec("INSERT IGNORE INTO `ionos_cursor` (`id`, `last_ts`, `last_mac`) VALUES (1, NULL, NULL)");
    
    // Prendre le lock MySQL
    $lockAcquired = false;
    $lockResult = $pdo->query("SELECT GET_LOCK('$lockName', 0) as lock_acquired")->fetch(PDO::FETCH_ASSOC);
    if ($lockResult && (int)$lockResult['lock_acquired'] === 1) {
        $lockAcquired = true;
        logMessage("Lock MySQL acquis: $lockName");
    } else {
        logMessage("ERREUR: Lock non acquis - un import est déjà en cours");
        logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => false,
            'msg' => [
                'type' => 'ionos',
                'message' => 'Lock non acquis - import déjà en cours',
                'inserted' => 0,
                'skipped' => 0,
                'duration_ms' => 0,
                'error' => 'Lock non acquis'
            ]
        ]);
        exit(1);
    }
    
    try {
        // Lire le curseur
        $cursorStmt = $pdo->query("SELECT last_ts, last_mac FROM ionos_cursor WHERE id = 1");
        $cursor = $cursorStmt->fetch(PDO::FETCH_ASSOC);
        $lastTs = $cursor['last_ts'] ?? null;
        $lastMac = $cursor['last_mac'] ?? null;
        logMessage("Curseur lu: last_ts=" . ($lastTs ?? 'NULL') . ", last_mac=" . ($lastMac ?? 'NULL'));
        
        // Appeler l'endpoint IONOS
        logMessage("Appel de l'endpoint: $ionosUrl");
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 30,
                'header' => 'User-Agent: CCComputer-Import/1.0'
            ]
        ]);
        
        $response = @file_get_contents($ionosUrl, false, $context);
        
        if ($response === false) {
            throw new RuntimeException("Échec de l'appel HTTP à $ionosUrl");
        }
        
        // Déterminer si c'est JSON ou HTML
        $isJson = false;
        $data = null;
        
        // Essayer de parser comme JSON
        $jsonData = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
            $isJson = true;
            $data = $jsonData;
            logMessage("Réponse détectée comme JSON");
        } else {
            // C'est probablement du HTML, essayer avec ?format=json ou ?json=1
            logMessage("Réponse détectée comme HTML, tentative avec paramètre JSON");
            $jsonUrl = $ionosUrl . (strpos($ionosUrl, '?') !== false ? '&' : '?') . 'format=json';
            $jsonResponse = @file_get_contents($jsonUrl, false, $context);
            if ($jsonResponse !== false) {
                $jsonData = json_decode($jsonResponse, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                    $isJson = true;
                    $data = $jsonData;
                    logMessage("Réponse JSON obtenue avec ?format=json");
                }
            }
            
            // Si toujours pas JSON, essayer ?json=1
            if (!$isJson) {
                $jsonUrl = $ionosUrl . (strpos($ionosUrl, '?') !== false ? '&' : '?') . 'json=1';
                $jsonResponse = @file_get_contents($jsonUrl, false, $context);
                if ($jsonResponse !== false) {
                    $jsonData = json_decode($jsonResponse, true);
                    if (json_last_error() === JSON_ERROR_NONE && is_array($jsonData)) {
                        $isJson = true;
                        $data = $jsonData;
                        logMessage("Réponse JSON obtenue avec ?json=1");
                    }
                }
            }
        }
        
        if (!$isJson || !is_array($data)) {
            throw new RuntimeException("Impossible d'obtenir des données JSON depuis l'endpoint. Réponse reçue: " . substr($response, 0, 200));
        }
        
        // Normaliser les données (peut être un objet unique ou un tableau)
        $items = [];
        if (isset($data[0]) && is_array($data[0])) {
            // C'est un tableau d'items
            $items = $data;
        } elseif (isset($data['Timestamp']) || isset($data['MacAddress'])) {
            // C'est un seul item
            $items = [$data];
        } else {
            // Chercher une clé qui contient un tableau
            foreach ($data as $key => $value) {
                if (is_array($value) && isset($value[0]) && is_array($value[0])) {
                    $items = $value;
                    break;
                }
            }
        }
        
        if (empty($items)) {
            logMessage("Aucun item trouvé dans la réponse");
            $items = [];
        } else {
            logMessage("Nombre d'items reçus: " . count($items));
        }
        
        // Filtrer les items > (last_ts, last_mac)
        $filteredItems = [];
        foreach ($items as $item) {
            if (!isset($item['Timestamp']) || !isset($item['MacAddress'])) {
                continue; // Item invalide
            }
            
            $itemTs = $item['Timestamp'];
            $itemMac = normalizeMac($item['MacAddress']);
            
            // Si pas de curseur, prendre tous les items
            if ($lastTs === null || $lastMac === null) {
                $filteredItems[] = $item;
                continue;
            }
            
            // Comparer avec le curseur
            $itemTimestamp = strtotime($itemTs);
            $lastTimestamp = strtotime($lastTs);
            
            if ($itemTimestamp > $lastTimestamp) {
                // Timestamp plus récent, prendre
                $filteredItems[] = $item;
            } elseif ($itemTimestamp === $lastTimestamp && strcmp($itemMac, $lastMac) > 0) {
                // Même timestamp mais MAC plus grande (ordre lexicographique)
                $filteredItems[] = $item;
            }
            // Sinon, ignorer (déjà importé)
        }
        
        logMessage("Items après filtrage: " . count($filteredItems));
        
        // Limiter à maxItems
        $itemsToProcess = array_slice($filteredItems, 0, $maxItems);
        logMessage("Items à traiter (limite $maxItems): " . count($itemsToProcess));
        
        // Trier par timestamp puis MAC pour traitement cohérent
        usort($itemsToProcess, 'compareReleve');
        
        // Préparer la requête d'insertion
        $insertStmt = $pdo->prepare("
            INSERT IGNORE INTO compteur_relevee_ancien (
                Timestamp, IpAddress, Nom, Model, SerialNumber, MacAddress, Status,
                TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
                TotalPages, FaxPages, CopiedPages, PrintedPages,
                BWCopies, ColorCopies, MonoCopies, BichromeCopies,
                BWPrinted, BichromePrinted, MonoPrinted, ColorPrinted,
                TotalColor, TotalBW, DateInsertion
            ) VALUES (
                :Timestamp, :IpAddress, :Nom, :Model, :SerialNumber, :MacAddress, :Status,
                :TonerBlack, :TonerCyan, :TonerMagenta, :TonerYellow,
                :TotalPages, :FaxPages, :CopiedPages, :PrintedPages,
                :BWCopies, :ColorCopies, :MonoCopies, :BichromeCopies,
                :BWPrinted, :BichromePrinted, :MonoPrinted, :ColorPrinted,
                :TotalColor, :TotalBW, NOW()
            )
        ");
        
        $inserted = 0;
        $skipped = 0;
        $lastProcessedTs = $lastTs;
        $lastProcessedMac = $lastMac;
        
        // Insérer chaque item
        foreach ($itemsToProcess as $item) {
            try {
                $itemMac = normalizeMac($item['MacAddress'] ?? '');
                $itemTs = $item['Timestamp'] ?? null;
                
                $insertStmt->execute([
                    ':Timestamp' => $itemTs,
                    ':IpAddress' => $item['IpAddress'] ?? null,
                    ':Nom' => $item['Nom'] ?? null,
                    ':Model' => $item['Model'] ?? null,
                    ':SerialNumber' => $item['SerialNumber'] ?? null,
                    ':MacAddress' => $item['MacAddress'] ?? null,
                    ':Status' => $item['Status'] ?? null,
                    ':TonerBlack' => isset($item['TonerBlack']) ? (int)$item['TonerBlack'] : null,
                    ':TonerCyan' => isset($item['TonerCyan']) ? (int)$item['TonerCyan'] : null,
                    ':TonerMagenta' => isset($item['TonerMagenta']) ? (int)$item['TonerMagenta'] : null,
                    ':TonerYellow' => isset($item['TonerYellow']) ? (int)$item['TonerYellow'] : null,
                    ':TotalPages' => isset($item['TotalPages']) ? (int)$item['TotalPages'] : null,
                    ':FaxPages' => isset($item['FaxPages']) ? (int)$item['FaxPages'] : null,
                    ':CopiedPages' => isset($item['CopiedPages']) ? (int)$item['CopiedPages'] : null,
                    ':PrintedPages' => isset($item['PrintedPages']) ? (int)$item['PrintedPages'] : null,
                    ':BWCopies' => isset($item['BWCopies']) ? (int)$item['BWCopies'] : null,
                    ':ColorCopies' => isset($item['ColorCopies']) ? (int)$item['ColorCopies'] : null,
                    ':MonoCopies' => isset($item['MonoCopies']) ? (int)$item['MonoCopies'] : null,
                    ':BichromeCopies' => isset($item['BichromeCopies']) ? (int)$item['BichromeCopies'] : null,
                    ':BWPrinted' => isset($item['BWPrinted']) ? (int)$item['BWPrinted'] : null,
                    ':BichromePrinted' => isset($item['BichromePrinted']) ? (int)$item['BichromePrinted'] : null,
                    ':MonoPrinted' => isset($item['MonoPrinted']) ? (int)$item['MonoPrinted'] : null,
                    ':ColorPrinted' => isset($item['ColorPrinted']) ? (int)$item['ColorPrinted'] : null,
                    ':TotalColor' => isset($item['TotalColor']) ? (int)$item['TotalColor'] : null,
                    ':TotalBW' => isset($item['TotalBW']) ? (int)$item['TotalBW'] : null,
                ]);
                
                $rowsAffected = $insertStmt->rowCount();
                if ($rowsAffected > 0) {
                    $inserted++;
                    $lastProcessedTs = $itemTs;
                    $lastProcessedMac = $itemMac;
                } else {
                    $skipped++; // Doublon (INSERT IGNORE n'a rien inséré)
                }
            } catch (PDOException $e) {
                // Erreur sur un item, continuer avec les autres
                logMessage("ERREUR lors de l'insertion d'un item: " . $e->getMessage());
                $skipped++;
            }
        }
        
        // Mettre à jour le curseur avec le dernier item traité
        if ($lastProcessedTs !== null && $lastProcessedMac !== null) {
            $updateCursorStmt = $pdo->prepare("UPDATE ionos_cursor SET last_ts = :last_ts, last_mac = :last_mac WHERE id = 1");
            $updateCursorStmt->execute([
                ':last_ts' => $lastProcessedTs,
                ':last_mac' => $lastProcessedMac
            ]);
            logMessage("Curseur mis à jour: last_ts=$lastProcessedTs, last_mac=$lastProcessedMac");
        }
        
        $duration = (int)((microtime(true) - $startTime) * 1000);
        $message = $inserted > 0 
            ? "Import IONOS réussi: $inserted inséré(s), $skipped ignoré(s)"
            : ($skipped > 0 ? "Aucun nouveau relevé (tous déjà importés)" : "Aucun relevé à importer");
        
        logMessage("=== FIN IMPORT IONOS ===");
        logMessage("Résultat: $message (durée: {$duration}ms)");
        
        // Logger dans import_run
        logToImportRun($pdo, [
            'imported' => $inserted,
            'skipped' => $skipped,
            'ok' => true,
            'msg' => [
                'type' => 'ionos',
                'message' => $message,
                'inserted' => $inserted,
                'skipped' => $skipped,
                'duration_ms' => $duration,
                'error' => null
            ]
        ]);
        
        exit(0);
        
    } finally {
        // Toujours release le lock
        if ($lockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')");
            logMessage("Lock MySQL libéré");
        }
    }
    
} catch (Throwable $e) {
    $duration = (int)((microtime(true) - $startTime) * 1000);
    $errorMsg = $e->getMessage();
    logMessage("ERREUR: $errorMsg");
    logMessage("=== FIN IMPORT IONOS (ERREUR) ===");
    
    // Logger l'erreur dans import_run
    if (isset($pdo)) {
        logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => false,
            'msg' => [
                'type' => 'ionos',
                'message' => "Erreur lors de l'import",
                'inserted' => 0,
                'skipped' => 0,
                'duration_ms' => $duration,
                'error' => $errorMsg
            ]
        ]);
        
        // Release lock si acquis
        if (isset($lockAcquired) && $lockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')");
        }
    }
    
    exit(1);
}

