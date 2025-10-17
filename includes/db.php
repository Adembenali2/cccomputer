<?php
// db.php

// Charger les variables d'environnement (local ou Railway)
$host = getenv('MYSQLHOST') ?: '127.0.0.1';
$db   = getenv('MYSQLDATABASE') ?: 'camsoncccomputer';
$user = getenv('MYSQLUSER') ?: 'root';
$pass = getenv('MYSQLPASSWORD') ?: '';
$port = getenv('MYSQLPORT') ?: '3306';

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Affichage des variables pour debug (retirer en production)
echo "<pre>";
echo "Host: $host\n";
echo "DB: $db\n";
echo "User: $user\n";
echo "Port: $port\n";
echo "</pre>";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
    echo "<p style='color:green;'>Connexion réussie !</p>";
} catch (PDOException $e) {
    echo "<p style='color:red;'>Erreur de connexion à la base de données :</p>";
    echo "<pre>" . $e->getMessage() . "</pre>";
    exit;
}
?>
