#!/usr/bin/env php
<?php
declare(strict_types=1);
/**
 * Script cron pour exécuter les envois de factures programmés
 *
 * À exécuter régulièrement (ex: toutes les 5 minutes) :
 *   php cron/send_scheduled_factures.php
 * ou via crontab :
 *   */5 * * * * cd /chemin/vers/projet && php cron/send_scheduled_factures.php >> /var/log/scheduled_factures.log 2>&1
 */

// Bootstrap minimal (sans session)
$baseDir = dirname(__DIR__);
require_once $baseDir . '/vendor/autoload.php';

// Charger .env si présent
if (file_exists($baseDir . '/.env')) {
    $lines = file($baseDir . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([^=]+)=(.*)$/', $line, $m)) {
            $_ENV[trim($m[1])] = trim($m[2], " \t\n\r\0\x0B\"'");
        }
    }
}

require_once $baseDir . '/includes/helpers.php';

use App\Services\InvoiceEmailService;

$logPrefix = '[send_scheduled_factures] ';

try {
    $pdo = getPdo();
} catch (Throwable $e) {
    error_log($logPrefix . 'Erreur connexion DB: ' . $e->getMessage());
    exit(1);
}

$config = require $baseDir . '/config/app.php';
$invoiceEmailService = new InvoiceEmailService($pdo, $config);

$stmt = $pdo->query("
    SELECT id, type_envoi, facture_id, factures_json, email_destination, use_client_email, all_clients, sujet, message
    FROM factures_envois_programmes
    WHERE statut = 'en_attente' AND date_envoi_programmee <= NOW()
    ORDER BY date_envoi_programmee ASC
");

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rows)) {
    exit(0);
}

error_log($logPrefix . count($rows) . ' programmation(s) à exécuter');

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
        $pdo->prepare("UPDATE factures_envois_programmes SET statut = 'echoue', erreur_message = 'Aucune facture associée' WHERE id = :id")
            ->execute([':id' => $id]);
        error_log($logPrefix . "Programmation #{$id} : aucune facture, marquée échouée");
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
            } else {
                $failed++;
                $lastError = $result['message'] ?? 'Erreur';
            }
        } catch (Throwable $e) {
            $failed++;
            $lastError = $e->getMessage();
            error_log($logPrefix . "Facture #{$fid} erreur: " . $e->getMessage());
        }
        usleep(100000);
    }

    $statut = $failed === 0 ? 'envoye' : ($success > 0 ? 'envoye' : 'echoue');
    $errMsg = $failed > 0 ? ($success . ' envoyé(s), ' . $failed . ' échoué(s)' . ($lastError ? ': ' . substr($lastError, 0, 200) : '')) : null;

    $pdo->prepare("
        UPDATE factures_envois_programmes 
        SET statut = :statut, sent_at = NOW(), erreur_message = :err 
        WHERE id = :id
    ")->execute([
        ':statut' => $statut,
        ':err' => $errMsg,
        ':id' => $id
    ]);

    error_log($logPrefix . "Programmation #{$id} : {$success} succès, {$failed} échec(s)");
}

exit(0);
