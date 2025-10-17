<?php
// db.php - Version Production

// Récupération des variables d'environnement
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT') ?: '3306'; // Par défaut 3306 si non défini

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // Journalisation de l'erreur côté serveur
    error_log('Erreur PDO : ' . $e->getMessage());
    // Message générique pour l'utilisateur
    exit('Erreur de connexion à la base de données.');
}
?>
