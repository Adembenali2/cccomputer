<?php
declare(strict_types=1);
/**
 * API pour forcer l'envoi immédiat d'une programmation en attente
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\InvoiceEmailService;

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data) || empty($data['id'])) {
        jsonResponse(['ok' => false, 'error' => 'ID requis'], 400);
    }

    $id = (int)$data['id'];
    if ($id <= 0) {
        jsonResponse(['ok' => false, 'error' => 'ID invalide'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT id, type_envoi, facture_id, factures_json, email_destination, use_client_email, all_clients, sujet, message, statut
        FROM factures_envois_programmes WHERE id = :id
    ");
    $stmt->execute([':id' => $id]);
    $prog = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$prog) {
        jsonResponse(['ok' => false, 'error' => 'Programmation introuvable'], 404);
    }

    if ($prog['statut'] !== 'en_attente') {
        jsonResponse(['ok' => false, 'error' => 'Seule une programmation en attente peut être envoyée maintenant'], 400);
    }

    $config = require __DIR__ . '/../config/app.php';
    $invoiceEmailService = new InvoiceEmailService($pdo, $config);

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
        jsonResponse(['ok' => false, 'error' => 'Aucune facture associée à cette programmation'], 400);
    }

    $success = 0;
    $failed = 0;
    $results = [];

    $emailToUse = $emailOverride;
    if ($prog['use_client_email'] || $prog['all_clients']) {
        $emailToUse = null;
    }

    // Un seul destinataire + plusieurs factures = un seul email avec toutes les pièces jointes
    if ($emailToUse && count($factureIds) > 1) {
        $result = $invoiceEmailService->sendMultipleInvoicesToEmail($factureIds, $emailToUse, $sujetOverride, $messageOverride);
        if ($result['success']) {
            $success = count($factureIds);
            foreach ($factureIds as $fid) {
                $results[] = ['facture_id' => $fid, 'success' => true, 'email' => $emailToUse];
            }
        } else {
            $failed = count($factureIds);
            foreach ($factureIds as $fid) {
                $results[] = ['facture_id' => $fid, 'success' => false, 'error' => $result['message'] ?? 'Erreur'];
            }
        }
    } else {
        foreach ($factureIds as $fid) {
            $result = $invoiceEmailService->sendInvoiceToEmail($fid, $emailToUse, $sujetOverride, $messageOverride);
            if ($result['success']) {
                $success++;
                $results[] = ['facture_id' => $fid, 'success' => true, 'email' => $result['email'] ?? null];
            } else {
                $failed++;
                $results[] = ['facture_id' => $fid, 'success' => false, 'error' => $result['message'] ?? 'Erreur'];
            }
            usleep(100000);
        }
    }

    $pdo->prepare("
        UPDATE factures_envois_programmes 
        SET statut = :statut, sent_at = NOW(), erreur_message = :err 
        WHERE id = :id
    ")->execute([
        ':statut' => $failed === 0 ? 'envoye' : ($success > 0 ? 'envoye' : 'echoue'),
        ':err' => $failed > 0 ? ($success . ' envoyé(s), ' . $failed . ' échoué(s)') : null,
        ':id' => $id
    ]);

    if (function_exists('enregistrerAction')) {
        enregistrerAction($pdo, $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null, 'programmation_envoyee', "Programmation #{$id} envoyée manuellement: {$success} succès, {$failed} échec(s)");
    }

    jsonResponse([
        'ok' => true,
        'message' => $success . ' facture(s) envoyée(s)' . ($failed > 0 ? ', ' . $failed . ' échec(s)' : ''),
        'success' => $success,
        'failed' => $failed,
        'results' => $results
    ]);
} catch (PDOException $e) {
    error_log('[factures_programmation_envoyer_maintenant] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
} catch (Throwable $e) {
    error_log('[factures_programmation_envoyer_maintenant] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
