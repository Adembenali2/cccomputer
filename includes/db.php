<?php
declare(strict_types=1);

/**
 * includes/db.php
 * Configuration de la connexion à la base de données
 * Gère les variables d'environnement (Railway, Docker) et le fallback local (XAMPP)
 */

// Priorité 1: Variables d'environnement (Railway, Docker, etc.)
$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

// Priorité 2: Fallback pour XAMPP/local (fichier de config optionnel)
if (empty($host) || empty($db) || empty($user)) {
    $configFile = __DIR__ . '/db_config.local.php';
    if (file_exists($configFile)) {
        require_once $configFile;
        $host = $host ?? $DB_HOST ?? 'localhost';
        $port = $port ?? $DB_PORT ?? '3306';
        $db   = $db ?? $DB_NAME ?? '';
        $user = $user ?? $DB_USER ?? 'root';
        $pass = $pass ?? $DB_PASS ?? '';
    } else {
        // Fallback par défaut XAMPP
        $host = 'localhost';
        $port = '3306';
        $db   = 'cccomputer';
        $user = 'root';
        $pass = '';
    }
}

$charset = 'utf8mb4';
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Variable pour indiquer que db.php a été chargé
if (!defined('DB_LOADED')) {
    define('DB_LOADED', true);
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    
    // COMPATIBILITÉ TEMPORAIRE : Stocker dans GLOBALS pour garantir la compatibilité
    // pendant la migration progressive. Cette ligne sera retirée à la fin de la migration.
    // DatabaseConnection::getInstance() pourra récupérer cette instance depuis GLOBALS
    $GLOBALS['pdo'] = $pdo;
    
    // COMPATIBILITÉ TEMPORAIRE : Variable globale classique (sera retirée après migration)
    global $pdo;
    
    // Log de succès pour le débogage (sans informations sensibles)
    $safeDsn = preg_replace('/:[^@]+@/', ':****@', $dsn);
    error_log("DB connection successful: DSN=$safeDsn, PDO stored in GLOBALS (compatibilité temporaire)");
    
} catch (PDOException $e) {
    // Ne jamais logger les credentials en clair
    $safeDsn = preg_replace('/:[^@]+@/', ':****@', $dsn);
    error_log("DB connection error: " . $e->getMessage() . " | DSN: $safeDsn");
    
    // S'assurer que $pdo n'est pas défini en cas d'erreur
    unset($GLOBALS['pdo']);
    if (isset($pdo)) {
        unset($pdo);
    }
    
    // Si on est dans un contexte API (jsonResponse existe), lancer une exception
    // pour que les fichiers API puissent la capturer et renvoyer du JSON
    if (function_exists('jsonResponse')) {
        // Stocker l'erreur dans GLOBALS pour le débogage
        $GLOBALS['db_connection_error'] = $e;
        throw $e; // L'exception sera capturée par initApi()
    }
    
    // Sinon, comportement par défaut pour les pages normales
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("Erreur interne du serveur. Impossible de se connecter à la base de données.");
}