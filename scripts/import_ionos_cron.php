#!/usr/bin/env php
<?php
/**
 * scripts/import_ionos_cron.php
 * Script CLI pour importer les relevés depuis IONOS (via test_compteur.php)
 * 
 * Usage: php scripts/import_ionos_cron.php
 * 
 * Fonctionnalités:
 * - Récupération HTML depuis URL configurable (IONOS_URL)
 * - Parsing du tableau HTML pour extraire les données
 * - Insertion dans compteur_relevee avec anti-doublon
 * - Lock MySQL anti-concurrence
 * - Transactions par ligne
 * - Logs détaillés dans import_run et import_run_item
 * - Mode dry-run disponible (IONOS_IMPORT_DRY_RUN=1)
 * 
 * Fréquence recommandée: toutes les 1 minute (cron)
 */

declare(strict_types=1);

// Configuration
$projectRoot = dirname(__DIR__);
$lockName = 'import_ionos';
$startTime = microtime(true);
$dryRun = !empty(getenv('IONOS_IMPORT_DRY_RUN')) && getenv('IONOS_IMPORT_DRY_RUN') === '1';
$ionosUrl = getenv('IONOS_URL') ?: 'https://cccomputer.fr/test_compteur.php';

// Charger autoload Composer
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "ERREUR: vendor/autoload.php introuvable. Exécutez 'composer install'.\n");
    exit(1);
}
require_once $autoloadPath;

// Charger DatabaseConnection
require_once $projectRoot . '/includes/db_connection.php';

// Fonction de logging
function logMessage(string $message, string $level = 'INFO'): void {
    $timestamp = date('Y-m-d H:i:s');
    $levelPrefix = str_pad($level, 5);
    $output = "[$timestamp] [$levelPrefix] $message\n";
    echo $output;
    error_log("[IMPORT IONOS] $message");
}

// Fonction pour normaliser MAC (identique à la colonne générée mac_norm)
// La colonne mac_norm est CHAR(12), donc on doit limiter à 12 caractères hexadécimaux
function normalizeMac(string $mac): string {
    // Supprimer tous les séparateurs possibles (:, -, espaces, points)
    $normalized = strtoupper(str_replace([':', '-', ' ', '.'], '', trim($mac)));
    
    // Extraire uniquement les caractères hexadécimaux (0-9, A-F)
    $normalized = preg_replace('/[^0-9A-F]/', '', $normalized);
    
    // Tronquer strictement à 12 caractères maximum (CHAR(12) en base)
    if (strlen($normalized) > 12) {
        $normalized = substr($normalized, 0, 12);
    }
    
    // Si moins de 12 caractères, on peut laisser tel quel (la base acceptera)
    // mais idéalement une MAC fait 12 caractères
    
    return $normalized;
}

// Fonction pour convertir valeur en int (null si vide/non numérique)
function toIntOrNull(?string $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim($value);
    if ($value === '' || $value === '-') {
        return null;
    }
    if (!is_numeric($value)) {
        return null;
    }
    return (int)$value;
}

// Fonction pour convertir en datetime
function toDateTime(?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    // Essayer de parser la date
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

// Fonction pour extraire le pourcentage d'un toner (ex: "85%" ou "85%<div>...</div>")
function extractTonerPercent(?string $html): ?int {
    if ($html === null || $html === '' || $html === '-') {
        return null;
    }
    // Extraire le premier nombre suivi de %
    if (preg_match('/(\d+)\s*%/', $html, $matches)) {
        $percent = (int)$matches[1];
        // Clamper entre -100 et 100
        if ($percent > 100) $percent = 100;
        if ($percent < -100) $percent = -100;
        return $percent;
    }
    return null;
}

// Fonction pour parser le HTML et extraire les lignes du tableau
function parseIonosHtml(string $html): array {
    $rows = [];
    
    // Charger le HTML avec DOMDocument
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    
    // Trouver le tableau
    $xpath = new DOMXPath($dom);
    $tableRows = $xpath->query("//table[@id='tableReleves']//tbody//tr");
    
    if ($tableRows === false || $tableRows->length === 0) {
        // Essayer sans ID spécifique
        $tableRows = $xpath->query("//table//tbody//tr");
    }
    
    foreach ($tableRows as $row) {
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 14) {
            continue; // Pas assez de colonnes
        }
        
        // Extraire les données selon l'ordre du tableau dans test_compteur.php
        // 0: ID Relevé, 1: Date relevé, 2: Ref Client, 3: Marque, 4: Modèle,
        // 5: MAC, 6: N° de série, 7: Total NB, 8: Total Couleur, 9: Compteur du mois,
        // 10: Toner K, 11: Toner C, 12: Toner M, 13: Toner Y
        $data = [
            'compteur_id' => trim($cells->item(0)->textContent ?? ''),
            'date_releve' => trim($cells->item(1)->textContent ?? ''),
            'ref_client' => trim($cells->item(2)->textContent ?? ''),
            'marque' => trim($cells->item(3)->textContent ?? ''),
            'modele' => trim($cells->item(4)->textContent ?? ''),
            'mac' => trim($cells->item(5)->textContent ?? ''),
            'serial' => trim($cells->item(6)->textContent ?? ''),
            'total_nb' => trim($cells->item(7)->textContent ?? ''),
            'total_couleur' => trim($cells->item(8)->textContent ?? ''),
            'compteur_mois' => trim($cells->item(9)->textContent ?? ''),
            'toner_k_html' => $cells->item(10)->textContent ?? '',
            'toner_c_html' => $cells->item(11)->textContent ?? '',
            'toner_m_html' => $cells->item(12)->textContent ?? '',
            'toner_y_html' => $cells->item(13)->textContent ?? '',
        ];
        
        // Extraire les pourcentages des toners
        $data['toner_k'] = extractTonerPercent($data['toner_k_html']);
        $data['toner_c'] = extractTonerPercent($data['toner_c_html']);
        $data['toner_m'] = extractTonerPercent($data['toner_m_html']);
        $data['toner_y'] = extractTonerPercent($data['toner_y_html']);
        
        if (!empty($data['date_releve']) && !empty($data['mac'])) {
            $rows[] = $data;
        }
    }
    
    return $rows;
}

// Fonction pour logger dans import_run
function logToImportRun(PDO $pdo, array $data): int {
    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    
    $msgJson = json_encode($data['msg'] ?? [], JSON_UNESCAPED_UNICODE);
    
    $stmt->execute([
        ':imported' => $data['imported'] ?? 0,
        ':skipped' => $data['skipped'] ?? 0,
        ':ok' => ($data['ok'] ?? false) ? 1 : 0,
        ':msg' => $msgJson
    ]);
    
    return (int)$pdo->lastInsertId();
}

// Fonction pour logger dans import_run_item (si table existe)
function logToImportRunItem(PDO $pdo, int $runId, string $itemId, string $status, int $inserted, ?string $error, float $durationMs): void {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO import_run_item (run_id, filename, status, inserted_rows, error, duration_ms, processed_at)
            VALUES (:run_id, :filename, :status, :inserted_rows, :error, :duration_ms, NOW())
        ");
        
        $stmt->execute([
            ':run_id' => $runId,
            ':filename' => $itemId,
            ':status' => $status,
            ':inserted_rows' => $inserted,
            ':error' => $error,
            ':duration_ms' => (int)round($durationMs)
        ]);
    } catch (Throwable $e) {
        // Table peut ne pas exister, ignorer
        error_log("Erreur lors du log dans import_run_item: " . $e->getMessage());
    }
}

// Main
try {
    logMessage("=== DÉBUT IMPORT IONOS ===");
    
    if ($dryRun) {
        logMessage("Mode DRY-RUN activé - aucune modification en base");
    }
    
    // Connexion DB
    $pdo = DatabaseConnection::getInstance();
    logMessage("Connexion DB établie");
    
    // Prendre le lock MySQL
    $lockAcquired = false;
    $lockResult = $pdo->query("SELECT GET_LOCK('$lockName', 0) as lock_acquired")->fetch(PDO::FETCH_ASSOC);
    if ($lockResult && (int)$lockResult['lock_acquired'] === 1) {
        $lockAcquired = true;
        logMessage("Lock MySQL acquis: $lockName");
    } else {
        logMessage("ERREUR: Lock non acquis - un import est déjà en cours", 'ERROR');
        logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => false,
            'msg' => [
                'type' => 'ionos',
                'message' => 'Lock non acquis - import déjà en cours',
                'rows_seen' => 0,
                'rows_processed' => 0,
                'rows_inserted' => 0,
                'error' => 'Lock non acquis'
            ]
        ]);
        exit(1);
    }
    
    try {
        // Récupérer le HTML depuis l'URL
        logMessage("Récupération depuis URL: $ionosUrl");
        
        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'CCComputer-Import/1.0',
                'follow_location' => true,
                'max_redirects' => 3
            ]
        ]);
        
        $html = @file_get_contents($ionosUrl, false, $context);
        
        if ($html === false) {
            throw new RuntimeException("Impossible de récupérer le contenu depuis $ionosUrl");
        }
        
        if (empty($html)) {
            throw new RuntimeException("Contenu vide reçu depuis $ionosUrl");
        }
        
        logMessage("HTML récupéré (" . strlen($html) . " octets)");
        
        // Parser le HTML
        logMessage("Parsing du HTML...");
        $allRows = parseIonosHtml($html);
        $totalRows = count($allRows);
        logMessage("Lignes trouvées dans le tableau: $totalRows");
        
        // Récupérer le dernier compteur_id traité depuis le dernier import réussi
        $lastProcessedId = null;
        try {
            $lastRunStmt = $pdo->prepare("
                SELECT msg 
                FROM import_run 
                WHERE msg LIKE '%\"type\":\"ionos\"%' 
                  AND ok = 1
                ORDER BY ran_at DESC 
                LIMIT 1
            ");
            $lastRunStmt->execute();
            $lastRun = $lastRunStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastRun && !empty($lastRun['msg'])) {
                $lastRunData = json_decode($lastRun['msg'], true);
                $lastProcessedId = $lastRunData['last_processed_id'] ?? null;
            }
        } catch (Throwable $e) {
            logMessage("Impossible de récupérer le dernier ID traité: " . $e->getMessage(), 'WARN');
        }
        
        // Filtrer les lignes : ne traiter que celles avec un compteur_id supérieur au dernier traité
        $rowsToProcess = [];
        if ($lastProcessedId !== null) {
            logMessage("Dernier compteur_id traité: $lastProcessedId - Filtrage des lignes à traiter");
            foreach ($allRows as $row) {
                $compteurId = (int)($row['compteur_id'] ?? 0);
                if ($compteurId > $lastProcessedId) {
                    $rowsToProcess[] = $row;
                }
            }
        } else {
            // Première exécution ou pas de dernier ID trouvé : prendre toutes les lignes
            $rowsToProcess = $allRows;
        }
        
        // Trier par compteur_id (croissant) pour traiter dans l'ordre
        usort($rowsToProcess, function($a, $b) {
            $idA = (int)($a['compteur_id'] ?? 0);
            $idB = (int)($b['compteur_id'] ?? 0);
            return $idA <=> $idB;
        });
        
        // Limiter à maxRowsPerRun lignes
        $rows = array_slice($rowsToProcess, 0, $maxRowsPerRun);
        $filteredCount = count($rows);
        logMessage("Lignes à traiter (après filtrage): $filteredCount (limite: $maxRowsPerRun)");
        
        if ($filteredCount === 0) {
            logMessage("Aucune nouvelle ligne à traiter");
            logToImportRun($pdo, [
                'imported' => 0,
                'skipped' => 0,
                'ok' => true,
                'msg' => [
                    'type' => 'ionos',
                    'message' => 'Aucune nouvelle ligne à traiter',
                    'rows_seen' => $totalRows,
                    'rows_processed' => 0,
                    'rows_inserted' => 0,
                    'last_processed_id' => $lastProcessedId,
                    'error' => null
                ]
            ]);
            exit(0);
        }
        
        // Statistiques globales
        $stats = [
            'rows_seen' => $totalRows,
            'rows_processed' => 0,
            'rows_inserted' => 0,
            'rows_skipped' => 0,
            'errors' => [],
            'last_processed_id' => $lastProcessedId
        ];
        
        // Préparer les requêtes SQL
        $checkExistsStmt = $pdo->prepare("
            SELECT 1 FROM compteur_relevee 
            WHERE mac_norm = :mac_norm AND Timestamp = :timestamp 
            LIMIT 1
        ");
        
        $insertStmt = $pdo->prepare("
            INSERT INTO compteur_relevee (
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
        
        // Logger le run principal
        $runId = logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => true,
            'msg' => [
                'type' => 'ionos',
                'message' => 'Import en cours',
                'rows_seen' => $totalRows,
                'rows_processed' => 0,
                'rows_inserted' => 0
            ]
        ]);
        
        // Traiter chaque ligne
        foreach ($rows as $rowIndex => $row) {
            $rowStartTime = microtime(true);
            $rowInserted = 0;
            $rowError = null;
            $rowStatus = 'error';
            $rowId = $row['compteur_id'] ?: "row_$rowIndex";
            
            try {
                // Normaliser et valider
                $timestamp = toDateTime($row['date_releve']);
                $macAddress = trim($row['mac'] ?? '');
                $macNorm = normalizeMac($macAddress);
                
                if ($timestamp === null) {
                    throw new RuntimeException("Format date invalide: " . ($row['date_releve'] ?? 'NULL'));
                }
                
                if (empty($macNorm)) {
                    throw new RuntimeException("MacAddress invalide ou vide");
                }
                
                // Si mac_norm fait plus de 12 caractères, tronquer strictement (CHAR(12) en base)
                if (strlen($macNorm) > 12) {
                    $macNorm = substr($macNorm, 0, 12);
                    logMessage("  ⚠ MAC tronquée à 12 caractères: $macNorm (original: $macAddress)", 'WARN');
                }
                
                // Si mac_norm fait moins de 12 caractères, on l'accepte quand même (certaines MAC peuvent être partielles)
                // La base de données acceptera, mais on log un avertissement si c'est très court (< 6 caractères)
                if (strlen($macNorm) < 6) {
                    logMessage("  ⚠ MAC très courte ($macNorm), possiblement invalide (original: $macAddress)", 'WARN');
                }
                
                // Vérifier si déjà présent (anti-doublon)
                $checkExistsStmt->execute([
                    ':mac_norm' => $macNorm,
                    ':timestamp' => $timestamp
                ]);
                $exists = $checkExistsStmt->fetchColumn() !== false;
                
                if ($exists) {
                    logMessage("  → Déjà présent en base (mac_norm=$macNorm, timestamp=$timestamp), ignoré");
                    $rowStatus = 'skipped';
                    $rowInserted = 0;
                    $stats['rows_skipped']++;
                } else {
                    // Pré-normaliser MacAddress pour éviter que mac_norm généré par MySQL dépasse 12 caractères
                    // La colonne mac_norm est générée avec: REPLACE(UPPER(MacAddress), ':', '')
                    // Donc on doit s'assurer que MacAddress normalisé (sans :) fait max 12 caractères hex
                    // On utilise la version normalisée mais on la formate en format MAC standard si possible
                    $macAddressToInsert = $macAddress; // Par défaut, utiliser l'original
                    
                    // Si la MAC normalisée fait 12 caractères hex, reformater en format standard XX:XX:XX:XX:XX:XX
                    if (strlen($macNorm) === 12 && preg_match('/^[0-9A-F]{12}$/', $macNorm)) {
                        $macAddressToInsert = implode(':', str_split($macNorm, 2));
                    } elseif (strlen($macNorm) <= 12) {
                        // Si moins de 12 caractères, utiliser la version normalisée telle quelle
                        // Mais on doit s'assurer que MySQL pourra générer mac_norm correctement
                        // On utilise directement macNorm comme MacAddress (sans séparateurs)
                        $macAddressToInsert = $macNorm;
                    } else {
                        // Si plus de 12 caractères (ne devrait pas arriver après troncature), utiliser la version tronquée
                        $macAddressToInsert = $macNorm;
                    }
                    
                    // Insertion dans une transaction
                    if (!$dryRun) {
                        $pdo->beginTransaction();
                    }
                    
                    try {
                        if (!$dryRun) {
                            $insertStmt->execute([
                                ':Timestamp' => $timestamp,
                                ':IpAddress' => null, // Non disponible dans IONOS
                                ':Nom' => !empty($row['marque']) ? $row['marque'] : null,
                                ':Model' => !empty($row['modele']) ? $row['modele'] : null,
                                ':SerialNumber' => !empty($row['serial']) ? $row['serial'] : null,
                                ':MacAddress' => $macAddressToInsert,
                                ':Status' => null, // Non disponible dans IONOS
                                ':TonerBlack' => $row['toner_k'],
                                ':TonerCyan' => $row['toner_c'],
                                ':TonerMagenta' => $row['toner_m'],
                                ':TonerYellow' => $row['toner_y'],
                                ':TotalPages' => null, // Non disponible dans IONOS
                                ':FaxPages' => null,
                                ':CopiedPages' => null,
                                ':PrintedPages' => null,
                                ':BWCopies' => null,
                                ':ColorCopies' => null,
                                ':MonoCopies' => null,
                                ':BichromeCopies' => null,
                                ':BWPrinted' => null,
                                ':BichromePrinted' => null,
                                ':MonoPrinted' => null,
                                ':ColorPrinted' => null,
                                ':TotalColor' => toIntOrNull($row['total_couleur']),
                                ':TotalBW' => toIntOrNull($row['total_nb']),
                            ]);
                            
                            $pdo->commit();
                        }
                        
                        $rowInserted = 1;
                        $rowStatus = 'success';
                        logMessage("  → Insertion réussie (mac_norm=$macNorm, timestamp=$timestamp)");
                        
                    } catch (Throwable $e) {
                        if (!$dryRun) {
                            $pdo->rollBack();
                        }
                        throw $e;
                    }
                }
                
                $stats['rows_processed']++;
                $stats['rows_inserted'] += $rowInserted;
                
                // Mettre à jour le dernier ID traité
                $compteurId = (int)($row['compteur_id'] ?? 0);
                if ($compteurId > 0 && ($stats['last_processed_id'] === null || $compteurId > $stats['last_processed_id'])) {
                    $stats['last_processed_id'] = $compteurId;
                }
                
            } catch (Throwable $e) {
                $rowError = $e->getMessage();
                logMessage("  ✗ ERREUR sur ligne $rowId: $rowError", 'ERROR');
                $stats['errors'][] = "$rowId: $rowError";
            }
            
            // Logger la ligne
            $rowDuration = (microtime(true) - $rowStartTime) * 1000;
            logToImportRunItem($pdo, $runId, $rowId, $rowStatus, $rowInserted, $rowError, $rowDuration);
        }
        
        // Mettre à jour le log principal
        $duration = (microtime(true) - $startTime) * 1000;
        $hasError = !empty($stats['errors']);
        $finalOk = !$hasError && $stats['rows_processed'] > 0;
        
        // Le dernier ID traité est le plus grand compteur_id traité avec succès ou skip
        $finalLastProcessedId = $stats['last_processed_id'] ?? $lastProcessedId;
        
        logToImportRun($pdo, [
            'imported' => $stats['rows_inserted'],
            'skipped' => $stats['rows_skipped'],
            'ok' => $finalOk,
            'msg' => [
                'type' => 'ionos',
                'message' => $hasError ? 'Import terminé avec erreurs' : 'Import terminé avec succès',
                'rows_seen' => $stats['rows_seen'],
                'rows_processed' => $stats['rows_processed'],
                'rows_inserted' => $stats['rows_inserted'],
                'rows_skipped' => $stats['rows_skipped'],
                'last_processed_id' => $finalLastProcessedId,
                'duration_ms' => (int)round($duration),
                'error' => $hasError ? implode('; ', array_slice($stats['errors'], 0, 5)) : null,
                'error_count' => count($stats['errors']),
                'dry_run' => $dryRun,
                'url' => $ionosUrl
            ]
        ]);
        
        logMessage("=== FIN IMPORT IONOS ===");
        logMessage("Durée totale: " . number_format($duration / 1000, 2) . "s");
        logMessage("Lignes vues: {$stats['rows_seen']}");
        logMessage("Lignes traitées: {$stats['rows_processed']}");
        logMessage("Lignes insérées: {$stats['rows_inserted']}");
        logMessage("Lignes ignorées (doublons): {$stats['rows_skipped']}");
        
        if ($hasError) {
            logMessage("Erreurs: " . count($stats['errors']), 'WARN');
            exit(1);
        } else {
            exit(0);
        }
        
    } finally {
        // Libérer le lock MySQL
        if ($lockAcquired) {
            $pdo->query("SELECT RELEASE_LOCK('$lockName')");
            logMessage("Lock MySQL libéré");
        }
    }
    
} catch (Throwable $e) {
    logMessage("ERREUR FATALE: " . $e->getMessage(), 'ERROR');
    logMessage("Stack trace: " . $e->getTraceAsString(), 'ERROR');
    
    // Logger l'erreur dans import_run
    try {
        $pdo = DatabaseConnection::getInstance();
        logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => false,
            'msg' => [
                'type' => 'ionos',
                'message' => 'Erreur fatale',
                'rows_seen' => 0,
                'rows_processed' => 0,
                'rows_inserted' => 0,
                'error' => $e->getMessage()
            ]
        ]);
    } catch (Throwable $logError) {
        // Ignorer les erreurs de log
    }
    
    exit(1);
}

