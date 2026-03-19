<?php
/**
 * API pour récupérer et modifier le paramètre d'envoi automatique des emails
 * GET : retourne { ok, enabled }
 * POST : toggle ou set enabled (body: { enabled: true|false })
 * Réservé Admin/Dirigeant
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/parametres.php';

initApi();
requireApiAuth();

$emploi = $_SESSION['emploi'] ?? '';
if (!in_array($emploi, ['Admin', 'Dirigeant'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
}

try {
    $pdo = getPdo();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $enabled = getAutoSendEmailsEnabled($pdo);
        jsonResponse(['ok' => true, 'enabled' => $enabled]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        requireCsrfToken();
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $enabled = isset($data['enabled']) ? filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN) : null;
        if ($enabled === null && !isset($data['enabled'])) {
            $enabled = !getAutoSendEmailsEnabled($pdo);
        }
        setAutoSendEmailsEnabled($pdo, (bool)$enabled);
        if (function_exists('enregistrerAction')) {
            require_once __DIR__ . '/../includes/historique.php';
            enregistrerAction($pdo, currentUserId(), 'parametre_auto_send', $enabled ? 'Activé' : 'Désactivé');
        }
        jsonResponse(['ok' => true, 'enabled' => (bool)$enabled, 'message' => $enabled ? 'Envoi automatique activé' : 'Envoi automatique désactivé']);
    }

    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
} catch (Throwable $e) {
    error_log('parametres_auto_send: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
