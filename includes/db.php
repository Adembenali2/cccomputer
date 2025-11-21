<?php
// includes/db.php - Configuration base de données

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

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    error_log("DB connection error: " . $e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    die("Erreur interne du serveur. Impossible de se connecter à la base de données.");
}