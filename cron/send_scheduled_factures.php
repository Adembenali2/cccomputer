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

// Forcer la session MySQL en UTC (évite toute ambiguïté)
try {
    $pdo->exec("SET time_zone = '+00:00'");
} catch (PDOException $e) {
    logMsg('Warning: SET time_zone failed - ' . $e->getMessage());
}

$utcNow = gmdate('Y-m-d H:i:s');
logMsg('UTC now (PHP): ' . $utcNow);

// Diagnostic: récupérer UTC_TIMESTAMP() de MySQL pour comparaison
try {
    $tzRow = $pdo->query("SELECT UTC_TIMESTAMP() as mysql_utc, NOW() as mysql_now")->fetch(PDO::FETCH_ASSOC);
    logMsg('UTC now (MySQL): ' . ($tzRow['mysql_utc'] ?? 'N/A') . ' | NOW(): ' . ($tzRow['mysql_now'] ?? 'N/A'));
} catch (PDOException $e) {
    logMsg('Warning: timezone check failed');
}

// Diagnostic: lister TOUTES les programmations en_attente (même celles non éligibles)
try {
    $allPending = $pdo->query("SELECT id, date_envoi_programmee FROM factures_envois_programmes WHERE statut = 'en_attente' ORDER BY date_envoi_programmee ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($allPending as $p) {
        $eligible = (strcmp($p['date_envoi_programmee'], $utcNow) <= 0);
        logMsg("En attente #{$p['id']}: date={$p['date_envoi_programmee']} eligible=" . ($eligible ? 'OUI' : 'NON'));
    }
} catch (PDOException $e) {
    logMsg('Warning: diagnostic pending failed');
}

// === 4. Récupérer les programmations à exécuter ===
// Utilisation de la date UTC PHP (gmdate) en paramètre pour éviter toute ambiguïté MySQL
try {
    $stmt = $pdo->prepare("
        SELECT id, type_envoi, facture_id, factures_json, email_destination, use_client_email, all_clients, sujet, message, date_envoi_programmee
        FROM factures_envois_programmes
        WHERE statut = 'en_attente' AND date_envoi_programmee <= :utc_now
        ORDER BY date_envoi_programmee ASC
    ");
    $stmt->execute([':utc_now' => $utcNow]);
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

logMsg(count($rows) . ' programmation(s) éligible(s) à exécuter');

$totalSent = 0;
$totalFailed = 0;

foreach ($rows as $prog) {
    $id = (int)$prog['id'];
    logMsg("Programmation #{$id} date={$prog['date_envoi_programmee']} statut=en_attente → exécution");
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

    $emailToUse = $emailOverride;
    if ($prog['use_client_email'] || $prog['all_clients']) {
        $emailToUse = null;
    }

    // Un seul destinataire + plusieurs factures = un seul email avec toutes les pièces jointes
    if ($emailToUse && count($factureIds) > 1) {
        try {
            $result = $invoiceEmailService->sendMultipleInvoicesToEmail($factureIds, $emailToUse, $sujetOverride, $messageOverride);
            if ($result['success']) {
                $success = count($factureIds);
                $totalSent += $success;
                logMsg(count($factureIds) . " facture(s) envoyée(s) en un seul email à {$emailToUse}");
            } else {
                $failed = count($factureIds);
                $totalFailed += $failed;
                $lastError = $result['message'] ?? 'Erreur';
                logMsg("Envoi groupé échec: " . $lastError);
            }
        } catch (Throwable $e) {
            $failed = count($factureIds);
            $totalFailed += $failed;
            $lastError = $e->getMessage();
            logMsg("Envoi groupé erreur: " . $e->getMessage());
        }
    } else {
        foreach ($factureIds as $fid) {
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
