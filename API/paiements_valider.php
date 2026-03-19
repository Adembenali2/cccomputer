<?php
/**
 * API pour valider un paiement (virement/chèque) : en_cours → recu
 * Envoie automatiquement le reçu par email au client
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$inputData = $_POST;
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (str_contains($contentType, 'application/json')) {
    $raw = file_get_contents('php://input') ?: '{}';
    $decoded = json_decode($raw, true);
    $inputData = is_array($decoded) ? $decoded : [];
}

$csrfToken = (string)($inputData['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
$csrfSession = (string)($_SESSION['csrf_token'] ?? '');
if ($csrfSession === '' || $csrfToken === '' || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

try {
    $pdo = getPdo();
    $userId = currentUserId();
    $paiementId = !empty($inputData['paiement_id']) ? (int)$inputData['paiement_id'] : 0;

    if ($paiementId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id invalide'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.id_facture, p.id_client, p.statut, p.reference, p.montant, p.mode_paiement
        FROM paiements p
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $paiementId]);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        jsonResponse(['ok' => false, 'error' => 'Paiement introuvable'], 404);
    }

    if ($paiement['statut'] !== 'en_cours') {
        jsonResponse(['ok' => false, 'error' => 'Ce paiement n\'est pas en attente de validation'], 400);
    }

    $factureId = (int)$paiement['id_facture'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE paiements SET statut = 'recu' WHERE id = :id");
        $stmt->execute([':id' => $paiementId]);

        if ($factureId > 0) {
            require_once __DIR__ . '/../vendor/autoload.php';
            $statutService = new \App\Services\FactureStatutService($pdo);
            $statutService->updateFactureStatutAfterPayment($factureId);
        }

        $pdo->commit();

        $details = sprintf('Paiement validé #%s - Ref: %s - %.2f €', $paiementId, $paiement['reference'], $paiement['montant']);
        enregistrerAction($pdo, $userId, 'paiement_valide', $details);

        // Envoi du reçu ET de la facture par email au client (si activé dans les paramètres)
        $receiptResult = null;
        $invoiceResult = null;
        require_once __DIR__ . '/../includes/parametres.php';
        if (getAutoSendEmailsEnabled($pdo)) {
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                $config = require __DIR__ . '/../config/app.php';
                $receiptService = new \App\Services\PaymentReceiptEmailService($pdo, $config);
                $receiptResult = $receiptService->sendReceipt($paiementId);
                if (!($receiptResult['success'] ?? false)) {
                    error_log('[paiements_valider] Envoi reçu échoué: ' . ($receiptResult['message'] ?? ''));
                }
                if ($factureId > 0) {
                    $invoiceService = new \App\Services\InvoiceEmailService($pdo, $config);
                    $invoiceResult = $invoiceService->sendInvoiceToEmail($factureId, null, null, 'Suite à la validation de votre paiement, veuillez trouver ci-joint votre facture.');
                    if (!($invoiceResult['success'] ?? false)) {
                        error_log('[paiements_valider] Envoi facture échoué: ' . ($invoiceResult['message'] ?? ''));
                    }
                }
            } catch (Throwable $e) {
                error_log('[paiements_valider] Erreur envoi: ' . $e->getMessage());
            }
        }

        $recuOk = $receiptResult['success'] ?? false;
        $factureOk = $invoiceResult['success'] ?? false;
        $msg = $recuOk && $factureOk ? 'Reçu et facture envoyés au client.'
            : ($recuOk ? 'Reçu envoyé.' . (!$factureOk ? ' Facture non envoyée.' : '')
            : 'Reçu non envoyé: ' . ($receiptResult['message'] ?? 'Erreur'));

        jsonResponse([
            'ok' => true,
            'message' => 'Paiement validé avec succès. ' . $msg,
            'paiement_id' => $paiementId,
            'facture_id' => $factureId,
            'recu_envoye' => $recuOk,
            'facture_envoyee' => $factureOk,
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('paiements_valider.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_valider.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
