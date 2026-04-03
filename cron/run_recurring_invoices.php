#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Génération des factures récurrentes dues (prochaine_echeance <= aujourd'hui).
 * Railway : 1×/jour — 30 7 * * * php cron/run_recurring_invoices.php
 */

$logPrefix = '[run_recurring_invoices] ';
$log = static function (string $m) use ($logPrefix): void {
    error_log($logPrefix . $m);
};

$lockFile = sys_get_temp_dir() . '/run_recurring_invoices.lock';
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

use App\Services\RecurringInvoiceService;

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    $log('DB: ' . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

if (empty($_SERVER['DOCUMENT_ROOT'])) {
    $_SERVER['DOCUMENT_ROOT'] = $baseDir;
}

$svc = new RecurringInvoiceService($pdo);
$res = $svc->processDue(null);
$log(sprintf('created=%d skipped=%d errors=%d', $res['created'], $res['skipped'], $res['errors']));

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);
exit(0);
