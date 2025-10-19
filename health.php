<?php
echo "PDO drivers: ";
var_dump(PDO::getAvailableDrivers());

require __DIR__ . '/config/db.php';  // ton pdo

$stmt = $pdo->query('SELECT 1');
echo " DB OK\n";
