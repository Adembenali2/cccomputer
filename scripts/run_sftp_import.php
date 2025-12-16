<?php
/**
 * Script wrapper PHP pour l'import SFTP en CLI
 * Ce script charge les variables d'environnement et exécute upload_compteur.php
 * 
 * Usage: php scripts/run_sftp_import.php
 * 
 * Ce script peut être appelé directement ou via cron/systemd.
 * Il charge automatiquement les variables d'environnement depuis .env si présent.
 */

declare(strict_types=1);

// Configuration
$projectRoot = dirname(__DIR__);
$importScript = $projectRoot . '/API/scripts/upload_compteur.php';
$logDir = $projectRoot . '/logs';
$logFile = $logDir . '/sftp_import.log';
$errorLogFile = $logDir . '/sftp_import_error.log';
$envFile = $projectRoot . '/.env';

// Créer le répertoire de logs s'il n'existe pas
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}

// Fonction de logging
function logMessage(string $message, string $logFile): void {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] $message\n";
    @file_put_contents($logFile, $logLine, FILE_APPEND);
    echo $logLine;
}

function logError(string $message, string $logFile, string $errorLogFile): void {
    $timestamp = date('Y-m-d H:i:s');
    $logLine = "[$timestamp] ERROR: $message\n";
    @file_put_contents($errorLogFile, $logLine, FILE_APPEND);
    @file_put_contents($logFile, $logLine, FILE_APPEND);
    fwrite(STDERR, $logLine);
}

// Vérifier que le script d'import existe
if (!is_file($importScript)) {
    logError("Script d'import introuvable: $importScript", $logFile, $errorLogFile);
    exit(1);
}

// Charger les variables d'environnement depuis .env si présent
if (is_file($envFile)) {
    logMessage("Chargement des variables d'environnement depuis .env", $logFile);
    
    $lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            // Ignorer les commentaires
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parser les lignes KEY=VALUE
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $matches)) {
                $key = $matches[1];
                $value = $matches[2];
                
                // Supprimer les guillemets si présents
                $value = trim($value, '"\'');
                
                // Définir la variable d'environnement
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
    }
} else {
    logMessage("Fichier .env non trouvé, utilisation des variables d'environnement système", $logFile);
}

// Vérifier que les variables SFTP essentielles sont présentes
$requiredVars = ['SFTP_HOST', 'SFTP_USER', 'SFTP_PASS'];
$missingVars = [];
foreach ($requiredVars as $var) {
    if (empty(getenv($var))) {
        $missingVars[] = $var;
    }
}

if (!empty($missingVars)) {
    logError(
        "Variables d'environnement SFTP manquantes: " . implode(', ', $missingVars),
        $logFile,
        $errorLogFile
    );
    logError(
        "Assurez-vous que ces variables sont définies dans .env ou dans l'environnement système",
        $logFile,
        $errorLogFile
    );
    exit(1);
}

// Log de démarrage
logMessage("=== Démarrage de l'import SFTP ===", $logFile);
logMessage("PHP: " . PHP_BINARY, $logFile);
logMessage("Script: $importScript", $logFile);
logMessage("PID: " . getmypid(), $logFile);

// Changer vers le répertoire du projet
chdir($projectRoot);

// Exécuter le script d'import
// On capture la sortie pour la logger
ob_start();
$exitCode = 0;
try {
    // Inclure le script d'import (il s'exécutera directement)
    require $importScript;
} catch (Throwable $e) {
    $exitCode = 1;
    $errorMsg = "Exception: " . $e->getMessage() . "\n" . $e->getTraceAsString();
    logError($errorMsg, $logFile, $errorLogFile);
    echo $errorMsg;
}
$output = ob_get_clean();

// Logger la sortie
if (!empty($output)) {
    @file_put_contents($logFile, $output, FILE_APPEND);
    echo $output;
}

if ($exitCode === 0) {
    logMessage("=== Import SFTP terminé avec succès ===", $logFile);
} else {
    logError("=== Import SFTP terminé avec erreur (code: $exitCode) ===", $logFile, $errorLogFile);
}

exit($exitCode);

