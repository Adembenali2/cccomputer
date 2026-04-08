<?php
declare(strict_types=1);

// [Fonctionnalité B]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$idPiece = (int)($_POST['id_piece'] ?? 0);
$idSav = (int)($_POST['id_sav'] ?? 0);
if ($idPiece <= 0 || $idSav <= 0) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getPdo();
    $savStmt = $pdo->prepare('SELECT id_technicien FROM sav WHERE id = ? LIMIT 1');
    $savStmt->execute([$idSav]);
    $sav = $savStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sav) {
        jsonResponse(['success' => false, 'error' => 'SAV introuvable'], 404);
    }

    $role = (string)($_SESSION['emploi'] ?? '');
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $isAllowed = in_array($role, ['Admin', 'Dirigeant'], true) || ((int)($sav['id_technicien'] ?? 0) === $uid);
    if (!$isAllowed) {
        jsonResponse(['success' => false, 'error' => 'Accès refusé'], 403);
    }

    $del = $pdo->prepare('DELETE FROM sav_pieces_utilisees WHERE id = ? AND id_sav = ?');
    $del->execute([$idPiece, $idSav]);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('pieces_delete.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
