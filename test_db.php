<?php
// test_db.php - Placé à la racine du projet pour un test isolé.

// Affiche le texte en format brut pour une meilleure lisibilité
header('Content-Type: text/plain; charset=utf-8');

echo "--- Début du test de connexion à la base de données ---\n\n";

// On récupère les variables comme le fait votre fichier db.php
$host = getenv('MYSQLHOST');
$db   = getenv('MYSQLDATABASE');
$user = getenv('MYSQLUSER');
$pass = getenv('MYSQLPASSWORD');
$port = getenv('MYSQLPORT');

// On affiche ce que le script a reçu
echo "Variables lues depuis l'environnement :\n";
echo "HOST: " . ($host ?: 'NON DÉFINI') . "\n";
echo "PORT: " . ($port ?: 'NON DÉFINI') . "\n";
echo "USER: " . ($user ?: 'NON DÉFINI') . "\n";
echo "DATABASE: " . ($db ?: 'NON DÉFINI') . "\n";
echo "PASSWORD PRÉSENT: " . ($pass ? 'Oui' : 'Non') . "\n\n";

// On arrête si une variable est manquante
if (!$host || !$port || !$user || !$pass || !$db) {
    die("ERREUR: Au moins une variable d'environnement est manquante. Arrêt du script.");
}

echo "Tentative de connexion PDO...\n";

try {
    // On tente la connexion
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    echo "\n>>> SUCCÈS ! La connexion à la base de données est établie. <<<\n";

} catch (PDOException $e) {
    // Si ça échoue, on affiche l'erreur technique complète
    echo "\n--- ÉCHEC DE LA CONNEXION ---\n";
    echo "Code d'erreur PDO: " . $e->getCode() . "\n";
    echo "Message d'erreur PDO: " . $e->getMessage() . "\n";
}

echo "\n--- Fin du test ---";
?>