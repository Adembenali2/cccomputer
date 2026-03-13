#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Script cron pour exécuter les envois de factures programmés
 *
 * Conçu pour Railway (UTC) - exécution toutes les 5 minutes.
 * Protection contre exécutions simultanées via lock file.
 *
 * Commande : php cron/send_scheduled_factures.php
 * Ou : composer scheduled-factures
 *
 * Railway Cron : */5 * * * * php cron/send_scheduled_factures.php
 */

$logPrefix = '[send_scheduled_factures] ';

function logMsg(string $msg): void {
    global $logPrefix;
    error_log($logPrefix . $msg);
}

// === 1. Lock file : empêcher exécutions simultanées ===
$lockFile = sys_get_temp_dir() . '/send_scheduled_factures.lock';
$lockFp = @fopen($lockFile, 'c');
if (!$lockFp) {
    logMsg('Impossible de créer le fichier de verrou');
    exit(1);
}
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    logMsg('Une autre instance est déjà en cours - abandon');
    fclose($lockFp);
    exit(0);
}
// Vérifier lock stale (> 15 min)
$lockMtime = filemtime($lockFile);
if ($lockMtime && (time() - $lockMtime) > 900) {
    logMsg('Lock stale détecté (>15 min), reprise');
}
ftruncate($lockFp, 0);
fwrite($lockFp, (string)getmypid());
fflush($lockFp);

logMsg('Script started');

// === 2. Bootstrap (sans session) ===
$baseDir = dirname(__DIR__);
chdir($baseDir);

require_once $baseDir . '/vendor/autoload.php';

// Charger .env si présent (local) - Railway injecte les vars directement
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

use App\Services\InvoiceEmailService;

// === 3. Connexion DB ===
try {
    $pdo = getPdo();
} catch (Throwable $e) {
    logMsg('Erreur connexion DB: ' . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

$config = require $baseDir . '/config/app.php';
$invoiceEmailService = new InvoiceEmailService($pdo, $config);

// Forcer UTC pour cohérence Railway
date_default_timezone_set('UTC');
logMsg('UTC now: ' . gmdate('Y-m-d H:i:s'));

// === 4. Récupérer les programmations à exécuter ===
// date_envoi_programmee stockée en UTC. Comparaison explicite en UTC.
try {
    $stmt = $pdo->query("
        SELECT id, type_envoi, facture_id, factures_json, email_destination, use_client_email, all_clients, sujet, message, date_envoi_programmee
        FROM factures_envois_programmes
        WHERE statut = 'en_attente' AND date_envoi_programmee <= UTC_TIMESTAMP()
        ORDER BY date_envoi_programmee ASC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    logMsg('Erreur SQL: ' . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

if (empty($rows)) {
    logMsg('Aucune programmation à exécuter');
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

logMsg(count($rows) . ' programmation(s) trouvée(s)');
foreach ($rows as $prog) {
    logMsg("Programmation #{$prog['id']}: date_envoi_programmee={$prog['date_envoi_programmee']}");
}

$totalSent = 0;
$totalFailed = 0;

foreach ($rows as $prog) {
    $id = (int)$prog['id'];
    $emailOverride = null;
    if (!$prog['use_client_email'] && !$prog['all_clients'] && !empty($prog['email_destination'])) {
        $emailOverride = trim($prog['email_destination']);
    }
    $sujetOverride = !empty($prog['sujet']) ? trim($prog['sujet']) : null;
    $messageOverride = !empty($prog['message']) ? trim($prog['message']) : null;

    $factureIds = [];
    if ($prog['facture_id']) {
        $factureIds[] = (int)$prog['facture_id'];
    }
    if (!empty($prog['factures_json'])) {
        $decoded = json_decode($prog['factures_json'], true);
        if (is_array($decoded)) {
            $factureIds = array_merge($factureIds, array_map('intval', $decoded));
        }
    }
    $factureIds = array_unique(array_filter($factureIds, fn($x) => $x > 0));

    if (empty($factureIds)) {
        try {
            $pdo->prepare("UPDATE factures_envois_programmes SET statut = 'echoue', erreur_message = 'Aucune facture associée' WHERE id = :id")
                ->execute([':id' => $id]);
        } catch (PDOException $e) {
            logMsg("Programmation #{$id} : erreur UPDATE - " . $e->getMessage());
        }
        logMsg("Programmation #{$id} : aucune facture, marquée échouée");
        continue;
    }

    $success = 0;
    $failed = 0;
    $lastError = null;

    foreach ($factureIds as $fid) {
        $emailToUse = $emailOverride;
        if ($prog['use_client_email'] || $prog['all_clients']) {
            $emailToUse = null;
        }
        try {
            $result = $invoiceEmailService->sendInvoiceToEmail($fid, $emailToUse, $sujetOverride, $messageOverride);
            if ($result['success']) {
                $success++;
                $totalSent++;
                logMsg("Facture #{$fid} envoyée");
            } else {
                $failed++;
                $totalFailed++;
                $lastError = $result['message'] ?? 'Erreur';
                logMsg("Facture #{$fid} échec: " . $lastError);
            }
        } catch (Throwable $e) {
            $failed++;
            $totalFailed++;
            $lastError = $e->getMessage();
            logMsg("Facture #{$fid} erreur: " . $e->getMessage());
        }
        usleep(100000);
    }

    $statut = $failed === 0 ? 'envoye' : ($success > 0 ? 'envoye' : 'echoue');
    $errMsg = $failed > 0 ? ($success . ' envoyé(s), ' . $failed . ' échoué(s)' . ($lastError ? ': ' . substr($lastError, 0, 200) : '')) : null;

    try {
        $pdo->prepare("
            UPDATE factures_envois_programmes 
            SET statut = :statut, sent_at = UTC_TIMESTAMP(), erreur_message = :err 
            WHERE id = :id
        ")->execute([
            ':statut' => $statut,
            ':err' => $errMsg,
            ':id' => $id
        ]);
    } catch (PDOException $e) {
        logMsg("Programmation #{$id} : erreur UPDATE statut - " . $e->getMessage());
    }

    logMsg("Programmation #{$id} : {$success} succès, {$failed} échec(s)");
}

logMsg("Script finished - {$totalSent} facture(s) envoyée(s), {$totalFailed} échec(s)");

flock($lockFp, LOCK_UN);
fclose($lockFp);
@unlink($lockFile);

exit(0);
