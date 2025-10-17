<?php
// Connexion PDO à la base Copiercamson
$host = 'localhost';
$db   = 'camsoncccomputer'; // Nom de la base de données
$user = 'root'; // à adapter si nécessaire
$pass = '';     // à ne jamais laisser vide en production

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, $options);
} catch (PDOException $e) {
    // En production, évitez d'afficher les messages d'erreur détaillés
    error_log('Erreur PDO : ' . $e->getMessage()); // Enregistre l'erreur dans le journal serveur
    exit('Erreur de connexion à la base de données.');
}
?>
