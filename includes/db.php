<?php
// --- DÉBOGAGE ---
// Affiche les variables d'environnement pour vérifier ce que PHP reçoit vraiment.
// Copiez et collez ce code pour remplacer tout le contenu de db.php

header('Content-Type: text/plain; charset=utf-8');

echo "--- Débogage des variables d'environnement --- \n\n";

$host = getenv('MYSQLHOST');
$port = getenv('MYSQLPORT');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$db   = getenv('MYSQLDATABASE');

echo "Valeur de MYSQLHOST: ";
var_dump($host);

echo "\nValeur de MYSQLPORT: ";
var_dump($port);

echo "\nValeur de MYSQLUSER: ";
var_dump($user);

echo "\nValeur de MYSQLPASSWORD (est-elle présente ?): ";
var_dump($pass !== false && $pass !== '');

echo "\nValeur de MYSQLDATABASE: ";
var_dump($db);

exit; // On arrête le script ici pour ne pas tenter la connexion.
?>