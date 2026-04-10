<?php
declare(strict_types=1);

// [Fonctionnalité A]
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$role = (string)($_SESSION['emploi'] ?? '');
if (!in_array($role, ['Admin', 'Dirigeant'], true)) {
    jsonResponse(['success' => false, 'error' => 'Accès refusé'], 403);
}

$idClient = (int)($_POST['id_client'] ?? 0);
$action = (string)($_POST['action'] ?? '');
if ($idClient <= 0 || !in_array($action, ['archiver', 'reactiver'], true)) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

$nouveauStatut = $action === 'archiver' ? 'archive' : 'actif';
$logAction = $action === 'archiver' ? 'client_archive' : 'client_reactive';

try {
    $pdo = getPdo();

    $stmt = $pdo->prepare('UPDATE clients SET statut = ? WHERE id = ?');
    $stmt->execute([$nouveauStatut, $idClient]);
    if ($stmt->rowCount() <= 0) {
        jsonResponse(['success' => false, 'error' => 'Client introuvable'], 404);
    }

    $details = sprintf('Client #%d => statut %s', $idClient, $nouveauStatut);
    enregistrerAction($pdo, currentUserId(), $logAction, $details);

    jsonResponse(['success' => true, 'nouveau_statut' => $nouveauStatut]);
} catch (Throwable $e) {
    error_log('toggle_statut.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
