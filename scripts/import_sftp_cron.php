#!/usr/bin/env php
<?php
/**
 * scripts/import_sftp_cron.php
 * Script CLI pour importer les relevés depuis un serveur SFTP
 * 
 * Usage: php scripts/import_sftp_cron.php
 * 
 * Fonctionnalités:
 * - Connexion SFTP via variables d'environnement
 * - Téléchargement et parsing de fichiers CSV (format clé/valeur)
 * - Insertion dans compteur_relevee avec anti-doublon
 * - Déplacement fichiers vers processed/ après succès (ou suppression si SFTP_DELETE_AFTER_SUCCESS=1)
 * - Déplacement fichiers en erreur vers errors/
 * - Lock MySQL anti-concurrence
 * - Transactions par fichier
 * - Logs détaillés dans import_run et import_run_item
 * - Mode dry-run disponible (SFTP_IMPORT_DRY_RUN=1)
 * 
 * Fréquence recommandée: toutes les 1 minute (cron)
 */

declare(strict_types=1);

// Configuration
$projectRoot = dirname(__DIR__);
$maxFiles = 20;
$lockName = 'import_sftp';
$startTime = microtime(true);
$dryRun = !empty(getenv('SFTP_IMPORT_DRY_RUN')) && getenv('SFTP_IMPORT_DRY_RUN') === '1';

// Charger autoload Composer
$autoloadPath = $projectRoot . '/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    fwrite(STDERR, "ERREUR: vendor/autoload.php introuvable. Exécutez 'composer install'.\n");
    exit(1);
}
require_once $autoloadPath;

// Charger DatabaseConnection
require_once $projectRoot . '/includes/db_connection.php';

use phpseclib3\Net\SFTP;

// Fonction de logging
function logMessage(string $message, string $level = 'INFO'): void {
    $timestamp = date('Y-m-d H:i:s');
    $levelPrefix = str_pad($level, 5);
    $output = "[$timestamp] [$levelPrefix] $message\n";
    echo $output;
    error_log("[IMPORT SFTP] $message");
}

// Fonction pour normaliser MAC (identique à la colonne générée mac_norm)
function normalizeMac(string $mac): string {
    return strtoupper(str_replace(':', '', $mac));
}

// Fonction pour parser CSV clé/valeur
function parseKeyValueCsv(string $content): array {
    $data = [];
    $lines = explode("\n", $content);
    
    foreach ($lines as $lineNum => $line) {
        // Ignorer lignes vides
        $line = trim($line);
        if (empty($line)) {
            continue;
        }
        
        // Ignorer la première ligne "Champ,Valeur" si présente
        if ($lineNum === 0 && (stripos($line, 'Champ') !== false || stripos($line, 'Valeur') !== false)) {
            continue;
        }
        
        // Parser "Clé,Valeur"
        $parts = explode(',', $line, 2);
        if (count($parts) !== 2) {
            continue;
        }
        
        $key = trim($parts[0]);
        $value = trim($parts[1]);
        
        // Valeur vide -> NULL
        if ($value === '') {
            $value = null;
        }
        
        $data[$key] = $value;
    }
    
    return $data;
}

// Fonction pour convertir valeur en int (null si vide/non numérique)
function toIntOrNull(?string $value): ?int {
    if ($value === null || $value === '') {
        return null;
    }
    // Supprimer espaces et vérifier si numérique
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    return (int)$value;
}

// Fonction pour convertir datetime (validation stricte)
function toDateTime(?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $value = trim($value);
    // Validation format datetime MySQL (YYYY-MM-DD HH:MM:SS)
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value)) {
        // Vérifier que c'est une date valide
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
    }
    return null;
}

// Fonction pour logger dans import_run
function logToImportRun(PDO $pdo, array $data): int {
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
        return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        logMessage("ERREUR lors du logging dans import_run: " . $e->getMessage(), 'ERROR');
        return 0;
    }
}

// Fonction pour logger un fichier dans import_run_item
function logToImportRunItem(PDO $pdo, int $runId, string $filename, string $status, int $inserted, ?string $error, float $durationMs): void {
    try {
        // Vérifier si la table existe (création optionnelle)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `import_run_item` (
                `id` int NOT NULL AUTO_INCREMENT,
                `run_id` int NOT NULL,
                `filename` varchar(255) NOT NULL,
                `status` enum('success','error','skipped') NOT NULL,
                `inserted_rows` int NOT NULL DEFAULT 0,
                `error` text,
                `duration_ms` decimal(10,2) DEFAULT NULL,
                `processed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_run_id` (`run_id`),
                KEY `idx_status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        $stmt = $pdo->prepare("
            INSERT INTO import_run_item (run_id, filename, status, inserted_rows, error, duration_ms)
            VALUES (:run_id, :filename, :status, :inserted_rows, :error, :duration_ms)
        ");
        $stmt->execute([
            ':run_id' => $runId,
            ':filename' => $filename,
            ':status' => $status,
            ':inserted_rows' => $inserted,
            ':error' => $error,
            ':duration_ms' => round($durationMs, 2)
        ]);
    } catch (Throwable $e) {
        // Ne pas bloquer si la table n'existe pas ou erreur
        logMessage("ERREUR lors du logging dans import_run_item: " . $e->getMessage(), 'WARN');
    }
}

try {
    logMessage("=== DÉBUT IMPORT SFTP " . ($dryRun ? "(DRY-RUN)" : "") . " ===");
    
    // Vérifier les variables d'environnement requises
    $sftpHost = getenv('SFTP_HOST');
    $sftpUser = getenv('SFTP_USER');
    $sftpPass = getenv('SFTP_PASS');
    $sftpPort = (int)(getenv('SFTP_PORT') ?: '22');
    $sftpDir = getenv('SFTP_DIR') ?: '.'; // Défaut: répertoire racine du compte SFTP
    $deleteAfterSuccess = !empty(getenv('SFTP_DELETE_AFTER_SUCCESS')) && getenv('SFTP_DELETE_AFTER_SUCCESS') === '1';
    
    if (empty($sftpHost) || empty($sftpUser)) {
        logMessage("ERREUR: Variables d'environnement SFTP_HOST et SFTP_USER requises", 'ERROR');
        exit(1);
    }
    
    if (empty($sftpPass)) {
        logMessage("ERREUR: Variable d'environnement SFTP_PASS requise (ne peut pas être vide)", 'ERROR');
        exit(1);
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
                'type' => 'sftp',
                'message' => 'Lock non acquis - import déjà en cours',
                'files_seen' => 0,
                'files_processed' => 0,
                'files_deleted' => 0,
                'inserted_rows' => 0,
                'error' => 'Lock non acquis'
            ]
        ]);
        exit(1);
    }
    
    try {
        // Connexion SFTP
        logMessage("Connexion SFTP à $sftpHost:$sftpPort...");
        $sftp = new SFTP($sftpHost, $sftpPort);
        
        if (!$sftp->login($sftpUser, $sftpPass)) {
            throw new RuntimeException("Échec de l'authentification SFTP");
        }
        logMessage("Connexion SFTP réussie");
        
        // Déterminer le répertoire de travail (robuste)
        $workingDir = null;
        $dirsToTry = [$sftpDir, '.', '/'];
        
        foreach ($dirsToTry as $dir) {
            if ($sftp->chdir($dir)) {
                // Vérifier qu'on peut lister (test d'accès réel)
                $testList = $sftp->nlist('.');
                if ($testList !== false) {
                    $workingDir = $dir;
                    logMessage("Répertoire SFTP accessible: $dir" . ($dir !== $sftpDir ? " (fallback depuis $sftpDir)" : ""));
                    break;
                }
            }
        }
        
        if ($workingDir === null) {
            throw new RuntimeException("Impossible d'accéder à un répertoire SFTP valide (tenté: " . implode(', ', $dirsToTry) . ")");
        }
        
        // Lister les fichiers CSV (uniquement dans le répertoire courant, pas récursif)
        $files = $sftp->nlist('.');
        if ($files === false) {
            throw new RuntimeException("Impossible de lister les fichiers");
        }
        
        // Filtrer les fichiers .csv et exclure les dossiers processed/ et errors/
        $csvFiles = array_filter($files, function($file) {
            // Ignorer les entrées spéciales
            if ($file === '.' || $file === '..') {
                return false;
            }
            // Ignorer les dossiers processed et errors
            if (strtolower($file) === 'processed' || strtolower($file) === 'errors') {
                return false;
            }
            // Ne garder que les fichiers .csv (pas les dossiers)
            return strtolower(substr($file, -4)) === '.csv';
        });
        
        // Trier par nom (ordre stable)
        sort($csvFiles, SORT_STRING);
        
        // Limiter à maxFiles
        $filesToProcess = array_slice($csvFiles, 0, $maxFiles);
        $totalFiles = count($csvFiles);
        logMessage("Fichiers CSV trouvés: $totalFiles (limite: $maxFiles)");
        
        if (empty($filesToProcess)) {
            logMessage("Aucun fichier CSV à traiter");
            logToImportRun($pdo, [
                'imported' => 0,
                'skipped' => 0,
                'ok' => true,
                'msg' => [
                    'type' => 'sftp',
                    'message' => 'Aucun fichier à traiter',
                    'files_seen' => $totalFiles,
                    'files_processed' => 0,
                    'files_deleted' => 0,
                    'inserted_rows' => 0,
                    'error' => null
                ]
            ]);
            exit(0);
        }
        
        // Statistiques globales
        $stats = [
            'files_seen' => $totalFiles,
            'files_processed' => 0,
            'files_deleted' => 0,
            'inserted_rows' => 0,
            'errors' => []
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
                'type' => 'sftp',
                'message' => 'Import en cours',
                'files_seen' => $totalFiles,
                'files_processed' => 0,
                'files_deleted' => 0,
                'inserted_rows' => 0
            ]
        ]);
        
        // Traiter chaque fichier
        foreach ($filesToProcess as $filename) {
            $fileStartTime = microtime(true);
            $fileInserted = 0;
            $fileError = null;
            $fileStatus = 'error';
            
            try {
                logMessage("Traitement du fichier: $filename");
                
                // Télécharger le contenu
                $content = $sftp->get($filename);
                if ($content === false) {
                    throw new RuntimeException("Impossible de télécharger le fichier");
                }
                
                // Parser le CSV
                $csvData = parseKeyValueCsv($content);
                
                // Valider champs minimum
                if (empty($csvData['Timestamp']) || empty($csvData['MacAddress'])) {
                    throw new RuntimeException("Champs Timestamp ou MacAddress manquants");
                }
                
                // Normaliser et valider
                $timestamp = toDateTime($csvData['Timestamp']);
                $macAddress = trim($csvData['MacAddress'] ?? '');
                $macNorm = normalizeMac($macAddress);
                
                if ($timestamp === null) {
                    throw new RuntimeException("Format Timestamp invalide: " . ($csvData['Timestamp'] ?? 'NULL'));
                }
                
                if (empty($macNorm)) {
                    throw new RuntimeException("MacAddress invalide ou vide");
                }
                
                // Vérifier si déjà présent (anti-doublon)
                $checkExistsStmt->execute([
                    ':mac_norm' => $macNorm,
                    ':timestamp' => $timestamp
                ]);
                $exists = $checkExistsStmt->fetchColumn() !== false;
                
                if ($exists) {
                    logMessage("  → Déjà présent en base (mac_norm=$macNorm, timestamp=$timestamp), ignoré");
                    $fileStatus = 'skipped';
                    $fileInserted = 0;
                } else {
                    // Insertion dans une transaction
                    $pdo->beginTransaction();
                    
                    try {
                        $insertStmt->execute([
                            ':Timestamp' => $timestamp,
                            ':IpAddress' => $csvData['IpAddress'] ?? null,
                            ':Nom' => $csvData['Nom'] ?? null,
                            ':Model' => $csvData['Model'] ?? null,
                            ':SerialNumber' => $csvData['SerialNumber'] ?? null,
                            ':MacAddress' => $macAddress,
                            ':Status' => $csvData['Status'] ?? null,
                            ':TonerBlack' => toIntOrNull($csvData['TonerBlack'] ?? null),
                            ':TonerCyan' => toIntOrNull($csvData['TonerCyan'] ?? null),
                            ':TonerMagenta' => toIntOrNull($csvData['TonerMagenta'] ?? null),
                            ':TonerYellow' => toIntOrNull($csvData['TonerYellow'] ?? null),
                            ':TotalPages' => toIntOrNull($csvData['TotalPages'] ?? null),
                            ':FaxPages' => toIntOrNull($csvData['FaxPages'] ?? null),
                            ':CopiedPages' => toIntOrNull($csvData['CopiedPages'] ?? null),
                            ':PrintedPages' => toIntOrNull($csvData['PrintedPages'] ?? null),
                            ':BWCopies' => toIntOrNull($csvData['BWCopies'] ?? null),
                            ':ColorCopies' => toIntOrNull($csvData['ColorCopies'] ?? null),
                            ':MonoCopies' => toIntOrNull($csvData['MonoCopies'] ?? null),
                            ':BichromeCopies' => toIntOrNull($csvData['BichromeCopies'] ?? null),
                            ':BWPrinted' => toIntOrNull($csvData['BWPrinted'] ?? null),
                            ':BichromePrinted' => toIntOrNull($csvData['BichromePrinted'] ?? null),
                            ':MonoPrinted' => toIntOrNull($csvData['MonoPrinted'] ?? null),
                            ':ColorPrinted' => toIntOrNull($csvData['ColorPrinted'] ?? null),
                            ':TotalColor' => toIntOrNull($csvData['TotalColor'] ?? null),
                            ':TotalBW' => toIntOrNull($csvData['TotalBW'] ?? null),
                        ]);
                        
                        $pdo->commit();
                        $fileInserted = 1;
                        $fileStatus = 'success';
                        logMessage("  → Insertion réussie (mac_norm=$macNorm, timestamp=$timestamp)");
                        
                        // Traitement post-import : déplacer vers processed/ ou supprimer
                        if (!$dryRun) {
                            if ($deleteAfterSuccess) {
                                // Mode suppression (priorité)
                                if (!$sftp->delete($filename)) {
                                    logMessage("  ⚠ ATTENTION: Impossible de supprimer le fichier sur SFTP", 'WARN');
                                } else {
                                    logMessage("  → Fichier supprimé sur SFTP");
                                    $stats['files_deleted']++;
                                }
                            } else {
                                // Mode déplacement vers processed/
                                $targetDir = 'processed';
                                $targetPath = $targetDir . '/' . $filename;
                                
                                // Créer le dossier processed/ s'il n'existe pas
                                if (!$sftp->file_exists($targetDir)) {
                                    if (!$sftp->mkdir($targetDir, -1, true)) {
                                        logMessage("  ⚠ ATTENTION: Impossible de créer le dossier $targetDir, suppression du fichier", 'WARN');
                                        if ($sftp->delete($filename)) {
                                            logMessage("  → Fichier supprimé (fallback)");
                                            $stats['files_deleted']++;
                                        }
                                        continue; // Passer au fichier suivant
                                    }
                                }
                                
                                // Déplacer le fichier
                                if ($sftp->file_exists($targetDir)) {
                                    if (!$sftp->rename($filename, $targetPath)) {
                                        logMessage("  ⚠ ATTENTION: Impossible de déplacer vers $targetPath, suppression du fichier", 'WARN');
                                        if ($sftp->delete($filename)) {
                                            logMessage("  → Fichier supprimé (fallback)");
                                            $stats['files_deleted']++;
                                        }
                                    } else {
                                        logMessage("  → Fichier déplacé vers $targetPath");
                                        $stats['files_deleted']++; // Comptabilisé comme "traité"
                                    }
                                }
                            }
                        } else {
                            if ($deleteAfterSuccess) {
                                logMessage("  → [DRY-RUN] Fichier serait supprimé sur SFTP");
                            } else {
                                logMessage("  → [DRY-RUN] Fichier serait déplacé vers processed/$filename");
                            }
                        }
                        
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                }
                
                $stats['files_processed']++;
                $stats['inserted_rows'] += $fileInserted;
                
            } catch (Throwable $e) {
                $fileError = $e->getMessage();
                logMessage("  ✗ ERREUR sur fichier $filename: $fileError", 'ERROR');
                $stats['errors'][] = "$filename: $fileError";
                
                // Déplacer le fichier en erreur vers errors/ (sauf en dry-run)
                if (!$dryRun) {
                    $targetDir = 'errors';
                    $targetPath = $targetDir . '/' . $filename;
                    
                    // Créer le dossier errors/ s'il n'existe pas
                    if (!$sftp->file_exists($targetDir)) {
                        if (!$sftp->mkdir($targetDir, -1, true)) {
                            logMessage("  ⚠ ATTENTION: Impossible de créer le dossier $targetDir, fichier conservé", 'WARN');
                        }
                    }
                    
                    // Déplacer le fichier en erreur
                    if ($sftp->file_exists($targetDir)) {
                        if (!$sftp->rename($filename, $targetPath)) {
                            logMessage("  ⚠ ATTENTION: Impossible de déplacer vers $targetPath, fichier conservé", 'WARN');
                        } else {
                            logMessage("  → Fichier déplacé vers $targetPath (erreur)");
                        }
                    }
                } else {
                    logMessage("  → [DRY-RUN] Fichier serait déplacé vers errors/$filename");
                }
            }
            
            // Logger le fichier
            $fileDuration = (microtime(true) - $fileStartTime) * 1000;
            logToImportRunItem($pdo, $runId, $filename, $fileStatus, $fileInserted, $fileError, $fileDuration);
        }
        
        // Fermer connexion SFTP
        $sftp->disconnect();
        logMessage("Connexion SFTP fermée");
        
        // Mettre à jour le log principal
        $duration = (int)((microtime(true) - $startTime) * 1000);
        $message = sprintf(
            "Import SFTP terminé: %d fichier(s) traité(s), %d ligne(s) insérée(s), %d fichier(s) supprimé(s)",
            $stats['files_processed'],
            $stats['inserted_rows'],
            $stats['files_deleted']
        );
        
        logMessage("=== FIN IMPORT SFTP ===");
        logMessage("Résultat: $message (durée: {$duration}ms)");
        
        // Logger dans import_run
        $runOk = empty($stats['errors']);
        logToImportRun($pdo, [
            'imported' => $stats['inserted_rows'],
            'skipped' => $stats['files_processed'] - $stats['inserted_rows'],
            'ok' => $runOk,
            'msg' => [
                'type' => 'sftp',
                'message' => $message,
                'files_seen' => $stats['files_seen'],
                'files_processed' => $stats['files_processed'],
                'files_deleted' => $stats['files_deleted'],
                'inserted_rows' => $stats['inserted_rows'],
                'duration_ms' => $duration,
                'error' => empty($stats['errors']) ? null : implode('; ', $stats['errors']),
                'dry_run' => $dryRun
            ]
        ]);
        
        exit($runOk ? 0 : 1);
        
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
    logMessage("ERREUR: $errorMsg", 'ERROR');
    logMessage("=== FIN IMPORT SFTP (ERREUR) ===");
    
    // Logger l'erreur dans import_run
    if (isset($pdo)) {
        logToImportRun($pdo, [
            'imported' => 0,
            'skipped' => 0,
            'ok' => false,
            'msg' => [
                'type' => 'sftp',
                'message' => "Erreur lors de l'import",
                'files_seen' => 0,
                'files_processed' => 0,
                'files_deleted' => 0,
                'inserted_rows' => 0,
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

