#!/usr/bin/env php
<?php
/**
 * Script pour créer la table factures_envois_programmes si elle n'existe pas
 * Usage : php scripts/run_programmer_migration.php
 */

$baseDir = dirname(__DIR__);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';

// Charger .env si présent (comme le cron)
if (file_exists($baseDir . '/.env')) {
    $lines = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
            $k = trim($m[1]);
            $v = trim($m[2], " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

require_once $baseDir . '/includes/helpers.php';

try {
    $pdo = getPdo();
    $sql = file_get_contents($baseDir . '/sql/migrations/create_factures_envois_programmes.sql');
    $pdo->exec($sql);
    echo "Table factures_envois_programmes créée ou déjà existante.\n";
} catch (Throwable $e) {
    echo "Erreur : " . $e->getMessage() . "\n";
    exit(1);
}
