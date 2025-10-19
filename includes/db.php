<?php
// config/db.php

// On utilise les variables d'environnement fournies par Railway
$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (PDOException $e) {
    // En production, il est préférable de ne pas afficher l'erreur directement
    // mais de la logger pour le débogage.
    error_log("DB connection error: " . $e->getMessage());
    // On peut renvoyer une réponse HTTP 500 pour indiquer une erreur serveur
    http_response_code(500);
    die("Erreur interne du serveur. Impossible de se connecter à la base de données.");
}