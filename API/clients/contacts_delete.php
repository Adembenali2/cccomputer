<?php
declare(strict_types=1);

// [Fonctionnalité F]
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$id = (int)($_POST['id'] ?? 0);
$idClient = (int)($_POST['id_client'] ?? 0);
if ($id <= 0 || $idClient <= 0) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getPdo();
    $checkStmt = $pdo->prepare('SELECT id FROM client_contacts WHERE id = ? AND id_client = ? LIMIT 1');
    $checkStmt->execute([$id, $idClient]);
    if (!$checkStmt->fetch(PDO::FETCH_ASSOC)) {
        jsonResponse(['success' => false, 'error' => 'Contact introuvable'], 404);
    }

    $delStmt = $pdo->prepare('DELETE FROM client_contacts WHERE id = ? AND id_client = ?');
    $delStmt->execute([$id, $idClient]);
    enregistrerAction($pdo, currentUserId(), 'contact_supprime', 'Contact #' . $id . ' supprimé pour client #' . $idClient);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('contacts_delete.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
