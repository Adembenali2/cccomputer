<?php
declare(strict_types=1);
/**
 * API pour exécuter manuellement les envois de factures programmés
 * 
 * Utilise la même logique que cron/send_scheduled_factures.php
 * Permet d'exécuter les envois sans configurer un cron (local, hébergement sans cron)
 * 
 * Peut aussi être appelé par un service externe (cron-job.org, etc.) :
 * GET/POST /API/cron_execute_scheduled.php
 * Avec authentification session ou token CRON_SECRET_TOKEN en query
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\InvoiceEmailService;

initApi();

// Autorisation : session utilisateur OU token secret (pour cron externe)
$cronToken = $_GET['token'] ?? $_POST['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
$config = require __DIR__ . '/../config/app.php';
$secretToken = $config['import']['cron_secret_token'] ?? $_ENV['CRON_SECRET_TOKEN'] ?? '';

if (!empty($secretToken) && !empty($cronToken) && hash_equals($secretToken, $cronToken)) {
    // Token cron valide - pas besoin de session
} else {
    requireApiAuth();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
    }
}

try {
    $pdo = getPdo();
    
    // Forcer UTC pour cohérence avec le cron
    date_default_timezone_set('UTC');
    try {
        $pdo->exec("SET time_zone = '+00:00'");
    } catch (PDOException $e) {
        // Ignorer
    }
    
    $utcNow = gmdate('Y-m-d H:i:s');
    $config = require __DIR__ . '/../config/app.php';
    $invoiceEmailService = new InvoiceEmailService($pdo, $config);
    
    $stmt = $pdo->prepare("
        SELECT id, type_envoi, facture_id, factures_json, email_destination, use_client_email, all_clients, sujet, message, date_envoi_programmee
        FROM factures_envois_programmes
        WHERE statut = 'en_attente' AND date_envoi_programmee <= :utc_now
        ORDER BY date_envoi_programmee ASC
    ");
    $stmt->execute([':utc_now' => $utcNow]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($rows)) {
        jsonResponse([
            'ok' => true,
            'message' => 'Aucune programmation à exécuter',
            'executed' => 0,
            'sent' => 0,
            'failed' => 0
        ]);
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
            $pdo->prepare("UPDATE factures_envois_programmes SET statut = 'echoue', erreur_message = 'Aucune facture associée' WHERE id = :id")
                ->execute([':id' => $id]);
            $totalFailed++;
            continue;
        }
        
        $success = 0;
        $failed = 0;
        $lastError = null;
        
        $emailToUse = $emailOverride;
        if ($prog['use_client_email'] || $prog['all_clients']) {
            $emailToUse = null;
        }
        
        if ($emailToUse && count($factureIds) > 1) {
            try {
                $result = $invoiceEmailService->sendMultipleInvoicesToEmail($factureIds, $emailToUse, $sujetOverride, $messageOverride);
                if ($result['success']) {
                    $success = count($factureIds);
                    $totalSent += $success;
                } else {
                    $failed = count($factureIds);
                    $totalFailed += $failed;
                    $lastError = $result['message'] ?? 'Erreur';
                }
            } catch (Throwable $e) {
                $failed = count($factureIds);
                $totalFailed += $failed;
                $lastError = $e->getMessage();
            }
        } else {
            foreach ($factureIds as $fid) {
                try {
                    $result = $invoiceEmailService->sendInvoiceToEmail($fid, $emailToUse, $sujetOverride, $messageOverride);
                    if ($result['success']) {
                        $success++;
                        $totalSent++;
                    } else {
                        $failed++;
                        $totalFailed++;
                        $lastError = $result['message'] ?? 'Erreur';
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $totalFailed++;
                    $lastError = $e->getMessage();
                }
                usleep(100000);
            }
        }
        
        $statut = $failed === 0 ? 'envoye' : ($success > 0 ? 'envoye' : 'echoue');
        $errMsg = $failed > 0 ? ($success . ' envoyé(s), ' . $failed . ' échoué(s)' . ($lastError ? ': ' . substr($lastError, 0, 200) : '')) : null;
        
        $pdo->prepare("
            UPDATE factures_envois_programmes 
            SET statut = :statut, sent_at = UTC_TIMESTAMP(), erreur_message = :err 
            WHERE id = :id
        ")->execute([
            ':statut' => $statut,
            ':err' => $errMsg,
            ':id' => $id
        ]);
    }
    
    jsonResponse([
        'ok' => true,
        'message' => count($rows) . ' programmation(s) traitée(s) - ' . $totalSent . ' facture(s) envoyée(s), ' . $totalFailed . ' échec(s)',
        'executed' => count($rows),
        'sent' => $totalSent,
        'failed' => $totalFailed
    ]);
} catch (Throwable $e) {
    error_log('[cron_execute_scheduled] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
