<?php
/**
 * Script de monitoring des corrections en production
 * 
 * Vérifie que les corrections fonctionnent correctement et génère un rapport
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/db_connection.php';
require_once __DIR__ . '/../includes/Logger.php';

configureErrorReporting(false); // Production mode

// Configuration
$logFile = __DIR__ . '/../logs/monitoring_' . date('Y-m-d') . '.log';
$reportFile = __DIR__ . '/../logs/monitoring_report_' . date('Y-m-d') . '.json';

// Créer le dossier logs s'il n'existe pas
$logDir = dirname($logFile);
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

/**
 * Logger avec timestamp
 */
function logMessage(string $message, string $level = 'INFO'): void {
    global $logFile;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message\n";
    @file_put_contents($logFile, $logEntry, FILE_APPEND);
}

/**
 * Vérifier la santé de la base de données
 */
function checkDatabaseHealth(PDO $pdo): array {
    $results = [
        'status' => 'ok',
        'checks' => []
    ];
    
    try {
        // Test de connexion
        $stmt = $pdo->prepare('SELECT 1');
        $stmt->execute();
        $results['checks']['connection'] = ['status' => 'ok', 'message' => 'Connexion PDO fonctionnelle'];
        logMessage("Database health: Connexion OK", 'INFO');
    } catch (Throwable $e) {
        $results['status'] = 'error';
        $results['checks']['connection'] = ['status' => 'error', 'message' => $e->getMessage()];
        logMessage("Database health: Erreur de connexion - " . $e->getMessage(), 'ERROR');
    }
    
    try {
        // Vérifier que les tables critiques existent
        $criticalTables = ['clients', 'sav', 'livraisons', 'compteur_relevee', 'historique'];
        $missingTables = [];
        
        foreach ($criticalTables as $table) {
            $stmt = $pdo->prepare("SHOW TABLES LIKE :table");
            $stmt->execute([':table' => $table]);
            if ($stmt->rowCount() === 0) {
                $missingTables[] = $table;
            }
        }
        
        if (empty($missingTables)) {
            $results['checks']['tables'] = ['status' => 'ok', 'message' => 'Toutes les tables critiques existent'];
            logMessage("Database health: Toutes les tables critiques existent", 'INFO');
        } else {
            $results['status'] = 'warning';
            $results['checks']['tables'] = ['status' => 'warning', 'message' => 'Tables manquantes: ' . implode(', ', $missingTables)];
            logMessage("Database health: Tables manquantes - " . implode(', ', $missingTables), 'WARNING');
        }
    } catch (Throwable $e) {
        $results['checks']['tables'] = ['status' => 'error', 'message' => $e->getMessage()];
        logMessage("Database health: Erreur vérification tables - " . $e->getMessage(), 'ERROR');
    }
    
    return $results;
}

/**
 * Vérifier que les corrections sont actives
 */
function checkCorrectionsActive(): array {
    $results = [
        'status' => 'ok',
        'checks' => []
    ];
    
    $filesToCheck = [
        'public/dashboard.php' => [
            'pattern' => 'currentUserId\\(\\)',
            'description' => 'Utilisation de currentUserId() pour $user_id'
        ],
        'import/run_import_if_due.php' => [
            'pattern' => 'SELECT GET_LOCK\\(:lock_name',
            'description' => 'GET_LOCK avec prepare()'
        ],
        'includes/api_helpers.php' => [
            'pattern' => '\\$stmt = \\$pdo->prepare\\(\\\'SELECT 1\\\'\\\\)',
            'description' => 'SELECT 1 avec prepare()'
        ],
        'API/scripts/upload_compteur.php' => [
            'pattern' => '\\$sftp->disconnect\\(\\)',
            'description' => 'Fermeture de la connexion SFTP'
        ]
    ];
    
    foreach ($filesToCheck as $file => $check) {
        $filePath = __DIR__ . '/../' . $file;
        if (!file_exists($filePath)) {
            $results['checks'][$file] = [
                'status' => 'error',
                'message' => 'Fichier introuvable'
            ];
            logMessage("Correction check: Fichier introuvable - $file", 'ERROR');
            continue;
        }
        
        $content = file_get_contents($filePath);
        if (preg_match('/' . $check['pattern'] . '/', $content)) {
            $results['checks'][$file] = [
                'status' => 'ok',
                'message' => $check['description'] . ' - Correction active'
            ];
            logMessage("Correction check: OK - $file (" . $check['description'] . ")", 'INFO');
        } else {
            $results['status'] = 'warning';
            $results['checks'][$file] = [
                'status' => 'warning',
                'message' => $check['description'] . ' - Correction non détectée'
            ];
            logMessage("Correction check: WARNING - $file (" . $check['description'] . " non détectée)", 'WARNING');
        }
    }
    
    return $results;
}

/**
 * Vérifier les erreurs récentes dans les logs
 */
function checkRecentErrors(PDO $pdo): array {
    $results = [
        'status' => 'ok',
        'checks' => []
    ];
    
    try {
        // Vérifier les imports récents avec erreurs
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as error_count
            FROM import_run
            WHERE ran_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            AND ok = 0
        ");
        $stmt->execute();
        $errorCount = (int)$stmt->fetchColumn();
        
        if ($errorCount === 0) {
            $results['checks']['import_errors'] = [
                'status' => 'ok',
                'message' => 'Aucune erreur d\'import dans les dernières 24h'
            ];
            logMessage("Recent errors: Aucune erreur d'import récente", 'INFO');
        } else {
            $results['status'] = 'warning';
            $results['checks']['import_errors'] = [
                'status' => 'warning',
                'message' => "$errorCount erreur(s) d'import dans les dernières 24h"
            ];
            logMessage("Recent errors: $errorCount erreur(s) d'import récente(s)", 'WARNING');
        }
    } catch (Throwable $e) {
        $results['checks']['import_errors'] = [
            'status' => 'error',
            'message' => $e->getMessage()
        ];
        logMessage("Recent errors: Erreur lors de la vérification - " . $e->getMessage(), 'ERROR');
    }
    
    return $results;
}

/**
 * Générer le rapport complet
 */
function generateReport(): array {
    $report = [
        'timestamp' => date('Y-m-d H:i:s'),
        'database_health' => [],
        'corrections_active' => [],
        'recent_errors' => []
    ];
    
    try {
        $pdo = getPdo();
        
        $report['database_health'] = checkDatabaseHealth($pdo);
        $report['corrections_active'] = checkCorrectionsActive();
        $report['recent_errors'] = checkRecentErrors($pdo);
        
        // Déterminer le statut global
        $allStatuses = [
            $report['database_health']['status'],
            $report['corrections_active']['status'],
            $report['recent_errors']['status']
        ];
        
        if (in_array('error', $allStatuses, true)) {
            $report['overall_status'] = 'error';
        } elseif (in_array('warning', $allStatuses, true)) {
            $report['overall_status'] = 'warning';
        } else {
            $report['overall_status'] = 'ok';
        }
        
    } catch (Throwable $e) {
        $report['overall_status'] = 'error';
        $report['error'] = $e->getMessage();
        logMessage("Report generation: Erreur - " . $e->getMessage(), 'ERROR');
    }
    
    return $report;
}

// ====================================================================
// EXÉCUTION
// ====================================================================

logMessage("=== DÉBUT DU MONITORING ===", 'INFO');

$report = generateReport();

// Sauvegarder le rapport JSON
@file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// Afficher un résumé
echo "=== RAPPORT DE MONITORING ===\n";
echo "Date : " . $report['timestamp'] . "\n";
echo "Statut global : " . strtoupper($report['overall_status']) . "\n\n";

echo "Santé de la base de données :\n";
foreach ($report['database_health']['checks'] ?? [] as $check => $result) {
    $status = $result['status'] === 'ok' ? '✓' : ($result['status'] === 'warning' ? '⚠' : '✗');
    echo "  $status $check : " . $result['message'] . "\n";
}

echo "\nCorrections actives :\n";
foreach ($report['corrections_active']['checks'] ?? [] as $file => $result) {
    $status = $result['status'] === 'ok' ? '✓' : ($result['status'] === 'warning' ? '⚠' : '✗');
    echo "  $status $file : " . $result['message'] . "\n";
}

echo "\nErreurs récentes :\n";
foreach ($report['recent_errors']['checks'] ?? [] as $check => $result) {
    $status = $result['status'] === 'ok' ? '✓' : ($result['status'] === 'warning' ? '⚠' : '✗');
    echo "  $status $check : " . $result['message'] . "\n";
}

echo "\nRapport complet sauvegardé dans : $reportFile\n";
logMessage("=== FIN DU MONITORING ===", 'INFO');

exit($report['overall_status'] === 'ok' ? 0 : 1);

