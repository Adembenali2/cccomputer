<?php
// On récupère les identifiants depuis les variables d'environnement de Railway
$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');

// La chaîne de connexion (DSN) doit inclure le port, ce qui est crucial pour PDO sur Railway
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // On utilise la nouvelle chaîne de connexion et les identifiants de Railway
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // En production, évitez d'afficher les messages d'erreur détaillés
    error_log('Erreur PDO : ' . $e->getMessage()); // Enregistre l'erreur dans le journal serveur
    exit('Erreur de connexion à la base de données.');
}
?>