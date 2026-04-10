<?php
declare(strict_types=1);

/**
 * POST JSON — Met à jour le statut d'un SAV (vue Kanban).
 * Corps : { id, statut, csrf_token? } (+ en-tête X-CSRF-Token accepté)
 */
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input');
$data = [];
if (is_string($raw) && $raw !== '') {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        $data = $decoded;
    }
}

$csrf = (string) ($data['csrf_token'] ?? '');
if ($csrf === '') {
    $csrf = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
}
requireCsrfToken($csrf !== '' ? $csrf : null);

$id = isset($data['id']) ? (int) $data['id'] : 0;
$statut = isset($data['statut']) ? (string) $data['statut'] : '';

$allowed = ['ouvert', 'en_cours', 'resolu', 'annule'];
if ($id <= 0 || !in_array($statut, $allowed, true)) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getPdoOrFail();
    $stmt = $pdo->prepare('SELECT id_technicien FROM sav WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        jsonResponse(['success' => false, 'error' => 'SAV introuvable'], 404);
    }

    $role = (string) ($_SESSION['emploi'] ?? '');
    $uid = (int) ($_SESSION['user_id'] ?? 0);
    $isAdminOrDirigeant = in_array($role, ['Admin', 'Dirigeant'], true);
    $isOwnTechnicien = $role === 'Technicien'
        && (int) ($row['id_technicien'] ?? 0) > 0
        && (int) ($row['id_technicien'] ?? 0) === $uid;

    if (!$isAdminOrDirigeant && !$isOwnTechnicien) {
        jsonResponse(['success' => false, 'error' => 'Non autorisé'], 403);
    }

    $closeDate = $statut === 'resolu' ? date('Y-m-d') : null;
    $upd = $pdo->prepare(
        'UPDATE sav SET statut = :statut, date_fermeture = :df, updated_at = NOW() WHERE id = :id'
    );
    $upd->execute([
        ':statut' => $statut,
        ':df' => $closeDate,
        ':id' => $id,
    ]);

    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('update_statut.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
