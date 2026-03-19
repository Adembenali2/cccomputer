<?php
/**
 * API pour changer le statut d'un paiement (En attente ↔ Validé)
 * Lors de la validation (recu), envoie automatiquement le reçu par email au client
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

$statutValide = ['en_cours', 'recu'];
$newStatut = trim($inputData['statut'] ?? '');
if (!in_array($newStatut, $statutValide, true)) {
    jsonResponse(['ok' => false, 'error' => 'Statut invalide (en_cours ou recu)'], 400);
}

try {
    $pdo = getPdo();
    $userId = currentUserId();
    $paiementId = !empty($inputData['paiement_id']) ? (int)$inputData['paiement_id'] : 0;

    if ($paiementId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id invalide'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT p.id, p.id_facture, p.statut, p.reference, p.montant
        FROM paiements p
        WHERE p.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $paiementId]);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        jsonResponse(['ok' => false, 'error' => 'Paiement introuvable'], 404);
    }

    if ($paiement['statut'] === $newStatut) {
        jsonResponse(['ok' => true, 'message' => 'Statut inchangé', 'recu_envoye' => false]);
    }

    $factureId = (int)$paiement['id_facture'];

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE paiements SET statut = :statut WHERE id = :id");
        $stmt->execute([':statut' => $newStatut, ':id' => $paiementId]);

        if ($factureId > 0) {
            $factureStatut = ($newStatut === 'recu') ? 'payee' : 'brouillon';
            $stmt = $pdo->prepare("UPDATE factures SET statut = :statut WHERE id = :id");
            $stmt->execute([':statut' => $factureStatut, ':id' => $factureId]);
        }

        $pdo->commit();

        $action = $newStatut === 'recu' ? 'paiement_valide' : 'paiement_invalide';
        $details = sprintf('Paiement #%s - Ref: %s - %.2f € → %s', $paiementId, $paiement['reference'], $paiement['montant'], $newStatut === 'recu' ? 'Validé' : 'En attente');
        enregistrerAction($pdo, $userId, $action, $details);

        $receiptResult = null;
        if ($newStatut === 'recu') {
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                $config = require __DIR__ . '/../config/app.php';
                $receiptService = new \App\Services\PaymentReceiptEmailService($pdo, $config);
                $receiptResult = $receiptService->sendReceipt($paiementId);
                if (!($receiptResult['success'] ?? false)) {
                    error_log('[paiements_changer_statut] Envoi reçu échoué: ' . ($receiptResult['message'] ?? ''));
                }
            } catch (Throwable $e) {
                error_log('[paiements_changer_statut] Erreur envoi reçu: ' . $e->getMessage());
                $receiptResult = ['success' => false, 'message' => $e->getMessage()];
            }
        }

        $msg = $newStatut === 'recu'
            ? (($receiptResult['success'] ?? false) ? 'Paiement validé. Reçu envoyé au client.' : 'Paiement validé. Reçu non envoyé: ' . ($receiptResult['message'] ?? 'Erreur inconnue'))
            : 'Paiement mis en attente.';

        jsonResponse([
            'ok' => true,
            'message' => $msg,
            'paiement_id' => $paiementId,
            'statut' => $newStatut,
            'recu_envoye' => $receiptResult['success'] ?? false,
            'recu_error' => ($receiptResult['success'] ?? true) ? null : ($receiptResult['message'] ?? null),
        ]);

    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

} catch (PDOException $e) {
    error_log('paiements_changer_statut.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_changer_statut.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
