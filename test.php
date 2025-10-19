<?php
// ⚠️ Remplace ces valeurs par celles de ta base TablePlus
$host = "TON_HOST";       // ex: db-host.tableplus.com
$db   = "NOM_DE_LA_BASE";
$user = "TON_UTILISATEUR";
$pass = "TON_MOT_DE_PASSE";

// Connexion à MySQL
$mysqli = new mysqli($host, $user, $pass, $db);

// Vérifie la connexion
if ($mysqli->connect_error) {
    die("❌ Connexion échouée : " . $mysqli->connect_error);
}

echo "✅ Connexion réussie !";

// Optionnel : liste des tables pour tester que la base est accessible
$result = $mysqli->query("SHOW TABLES");
if ($result) {
    echo "<br>Tables dans la base :<br>";
    while ($row = $result->fetch_array()) {
        echo "- " . $row[0] . "<br>";
    }
} else {
    echo "<br>Impossible de lister les tables : " . $mysqli->error;
}

$mysqli->close();
?>
