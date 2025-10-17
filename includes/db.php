<?php
// Connexion PDO adaptée pour Railway
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT'); // Railway fournit aussi un port

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    // La chaîne de connexion est mise à jour pour inclure le port
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // En production, évitez d'afficher les messages d'erreur détaillés
    error_log('Erreur PDO : ' . $e->getMessage()); // Enregistre l'erreur dans le journal serveur
    exit('Erreur de connexion à la base de données.');
}
?>