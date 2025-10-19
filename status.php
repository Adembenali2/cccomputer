<?php
header('Content-Type: text/plain');
echo "=== ENV vars utiles ===\n";
$keys = ['MYSQLHOST','MYSQLPORT','MYSQLDATABASE','MYSQLUSER','MYSQLPASSWORD','MYSQL_URL','PORT','APP_DEBUG'];
foreach ($keys as $k) {
    echo "$k = " . (getenv($k) === false ? '<not set>' : getenv($k)) . "\n";
}
echo "\n=== Test PHP & PDO ===\n";
echo extension_loaded('pdo') ? "PDO OK\n" : "PDO missing\n";
echo extension_loaded('pdo_mysql') ? "pdo_mysql OK\n" : "pdo_mysql missing\n";

$host = getenv('MYSQLHOST') ?: null;
$port = getenv('MYSQLPORT') ?: '3306';
$db   = getenv('MYSQLDATABASE') ?: null;
$user = getenv('MYSQLUSER') ?: null;
$pass = getenv('MYSQLPASSWORD') ?: null;

if ($host && $db && $user) {
    $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    echo "Tentative de connexion à $host:$port/$db ...\n";
    try {
        $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        echo "Connexion DB réussie.\n";
    } catch (PDOException $e) {
        echo "Échec connexion DB: " . $e->getMessage() . "\n";
    }
} else {
    echo "Info DB incomplète (host/db/user manquants)\n";
}
