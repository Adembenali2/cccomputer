<?php
/**
 * API pour récupérer et modifier tous les paramètres de l'application
 * GET : retourne { ok, parametres: { cle: { enabled, label, desc, category } } }
 * POST : body { cle: true|false } ou { cle, enabled } pour un seul
 * Réservé Admin/Dirigeant
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonOut(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/parametres.php';
    $pdo = getPdo();
} catch (Throwable $e) {
    error_log('parametres_app require: ' . $e->getMessage());
    jsonOut(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonOut(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$emploi = $_SESSION['emploi'] ?? '';
if (!in_array($emploi, ['Admin', 'Dirigeant'], true)) {
    jsonOut(['ok' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonOut(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $parametres = getAllParametres($pdo);
        jsonOut(['ok' => true, 'parametres' => $parametres]);
    } catch (Throwable $e) {
        error_log('parametres_app GET: ' . $e->getMessage());
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
        jsonOut(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
    }

    $input = file_get_contents('php://input');
    $data = $input ? (json_decode($input, true) ?: []) : [];

    $updates = [];
    if (isset($data['cle']) && isset($data['enabled'])) {
        $updates[$data['cle']] = filter_var($data['enabled'], FILTER_VALIDATE_BOOLEAN);
    } else {
        foreach ($data as $cle => $val) {
            if (array_key_exists($cle, PARAMETRES_DEF)) {
                $updates[$cle] = filter_var($val, FILTER_VALIDATE_BOOLEAN);
            }
        }
    }

    if (empty($updates)) {
        jsonOut(['ok' => false, 'error' => 'Aucun paramètre valide à mettre à jour'], 400);
    }

    try {
        foreach ($updates as $cle => $enabled) {
            setParametre($pdo, $cle, $enabled);
        }
        if (function_exists('enregistrerAction')) {
            require_once __DIR__ . '/../includes/historique.php';
            enregistrerAction($pdo, (int)($_SESSION['user_id'] ?? 0), 'parametres_modifies', implode(', ', array_keys($updates)));
        }
        $parametres = getAllParametres($pdo);
        jsonOut(['ok' => true, 'parametres' => $parametres, 'message' => 'Paramètres mis à jour']);
    } catch (Throwable $e) {
        error_log('parametres_app POST: ' . $e->getMessage());
        jsonOut(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

jsonOut(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
