<?php
/**
 * POST — Fermer une session enregistrée (autre appareil). [Fonctionnalité D]
 * Corps JSON : { "session_token": "..." } + en-tête X-CSRF-Token
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$pdo = getPdoOrFail();
$userId = (int)($_SESSION['user_id'] ?? 0);

$raw = file_get_contents('php://input');
$token = '';
if ($raw !== '' && $raw !== false) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded) && isset($decoded['session_token'])) {
        $token = trim((string)$decoded['session_token']);
    }
}
if ($token === '') {
    $token = trim((string)($_POST['session_token'] ?? ''));
}

if ($token === '' || strlen($token) > 128) {
    jsonResponse(['success' => false, 'error' => 'Token invalide'], 400);
}

try {
    $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE session_token = ? AND user_id = ?');
    $stmt->execute([$token, $userId]);
} catch (PDOException $e) {
    error_log('[Fonctionnalité D] sessions_revoke: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}

if ($stmt->rowCount() === 0) {
    jsonResponse(['success' => false, 'error' => 'Session introuvable'], 404);
}

enregistrerAction($pdo, $userId, 'session_revoquee', 'Session révoquée depuis le profil');

jsonResponse(['success' => true]);
