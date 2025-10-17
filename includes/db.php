<?php
// Connexion PDO à la base de données sur Railway

// On récupère les informations de connexion depuis les variables d'environnement
// que vous configurerez dans le tableau de bord Railway.
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT'); // Railway utilise un port spécifique

// On s'assure que le port est bien inclus dans la chaîne de connexion (DSN)
$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // Enregistre l'erreur dans les logs de Railway, sans l'afficher à l'utilisateur
    error_log('Erreur PDO : ' . $e->getMessage()); 
    // Affiche un message générique à l'utilisateur
    exit('Erreur de connexion à la base de données. Veuillez réessayer plus tard.');
}
?>