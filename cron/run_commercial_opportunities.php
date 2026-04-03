#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Recalcul des opportunités commerciales (upsert par règles métier).
 * Railway : 1×/jour — 45 6 * * * php cron/run_commercial_opportunities.php
 */

$logPrefix = '[run_commercial_opportunities] ';
$log = static function (string $m) use ($logPrefix): void {
    error_log($logPrefix . $m);
};

$lockFile = sys_get_temp_dir() . '/run_commercial_opportunities.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    $log('Autre instance en cours — abandon');
    exit(0);
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

$baseDir = dirname(__DIR__);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';
if (file_exists($baseDir . '/.env')) {
    $lines = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
            $k = trim($m[1]);
            $v = trim($m[2], " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            putenv($k . '=' . $v);
        }
    }
}

require_once $baseDir . '/includes/helpers.php';

use App\Services\CommercialOpportunityService;

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    $log('DB: ' . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

$svc = new CommercialOpportunityService($pdo);
$n = $svc->syncAll(null);
$log('rows_touched=' . $n);

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
exit(0);
