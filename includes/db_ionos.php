<?php
// /includes/db_ionos.php
$IONOS_DB_HOST = getenv('IONOS_DB_HOST') ?: 'db550618985.db.1and1.com';
$IONOS_DB_PORT = (int)(getenv('IONOS_DB_PORT') ?: 3306);
$IONOS_DB_NAME = getenv('IONOS_DB_NAME') ?: 'db550618985';
$IONOS_DB_USER = getenv('IONOS_DB_USER') ?: 'dbo550618985';
$IONOS_DB_PASS = getenv('IONOS_DB_PASS') ?: '';

$dsnIonos = "mysql:host={$IONOS_DB_HOST};port={$IONOS_DB_PORT};dbname={$IONOS_DB_NAME};charset=utf8mb4";

$pdoIonos = null;
try {
    $pdoIonos = new PDO($dsnIonos, $IONOS_DB_USER, $IONOS_DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT            => 5,         // évite de bloquer l’UI
        PDO::ATTR_PERSISTENT         => false,
        // Si IONOS impose TLS (avec un CA), décommente et fournis le chemin :
        // PDO::MYSQL_ATTR_SSL_CA => '/app/certs/ionos-ca.pem',
        // PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ]);
} catch (Throwable $e) {
    // On log, mais on NE crash PAS le site
    error_log('db_ionos: connection failed: ' . $e->getMessage());
}

/** Utils */
function ionos_mac_norm(?string $mac): ?string {
    if (!$mac) return null;
    $m = strtoupper(trim($mac));
    $m = str_replace(['-', '.'], ':', $m);
    if (strpos($m, ':') === false && strlen($m) === 12) $m = implode(':', str_split($m, 2));
    return preg_replace('/[^0-9A-F]/', '', $m);
}
function ionos_status_from_etat($etat): string {
    if ($etat === null) return 'Unknown';
    return ((int)$etat === 1) ? 'Online' : 'Offline';
}
