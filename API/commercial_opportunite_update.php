<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$role = strtolower((string)($_SESSION['role'] ?? $_SESSION['emploi'] ?? ''));
$allowed = ['admin', 'dirigeant', 'secretaire', 'commercial', 'charge_relation_clients'];
if (!in_array($role, $allowed, true)) {
    jsonResponse(['success' => false, 'error' => 'Non autorisé'], 403);
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

$allowedStatuts = ['nouveau', 'vu', 'converti', 'ignore'];
if ($id <= 0 || !in_array($statut, $allowedStatuts, true)) {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}

try {
    $pdo = getPdoOrFail();
    $stmt = $pdo->prepare('UPDATE commercial_opportunites SET statut = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$statut, $id]);
    if ($stmt->rowCount() === 0) {
        $chk = $pdo->prepare('SELECT id FROM commercial_opportunites WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        if (!$chk->fetch()) {
            jsonResponse(['success' => false, 'error' => 'Opportunité introuvable'], 404);
        }
    }
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('commercial_opportunite_update.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
