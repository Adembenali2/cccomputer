<?php
// ⚠️ Remplace ces valeurs par celles de ta base TablePlus
$host = "centerbeam.proxy.rlwy.net";       // ex: db-host.tableplus.com
$db   = "railway";
$user = "root";
$pass = "ocvbjxUZXKiYImJyiIulveNRxtKAYWeT";

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
