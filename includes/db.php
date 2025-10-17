<?php
// db.php - Version stable pour Railway (production + debug léger)

// Récupération des variables d'environnement
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT') ?: '3306'; // défaut 3306 si non défini

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Connexion PDO
$pdo = null;
try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // Écrire l'erreur dans les logs Railway (ne pas afficher à l'utilisateur)
    error_log('Erreur PDO : ' . $e->getMessage());
    // Pour éviter de crasher le container, on continue sans exit
    $pdo = null;
}
