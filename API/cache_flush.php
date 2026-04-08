<?php
/**
 * POST — Vide le cache APCu (réservé aux administrateurs).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/CacheHelper.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

if (($_SESSION['emploi'] ?? '') !== 'Admin') {
    jsonResponse(['success' => false, 'error' => 'Accès réservé aux administrateurs'], 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

if (!function_exists('apcu_clear_cache')) {
    jsonResponse(['success' => false, 'message' => 'APCu non disponible sur ce serveur'], 503);
}

$cleared = CacheHelper::clearApcuUserCache();

try {
    $pdo = getPdoOrFail();
    $userId = (int) ($_SESSION['user_id'] ?? 0);
    enregistrerAction($pdo, $userId, 'cache_apcu_vide', 'Vidage du cache APCu (action administrateur)');
} catch (Throwable $e) {
    error_log('cache_flush.php historique: ' . $e->getMessage());
}

if (!$cleared) {
    jsonResponse(['success' => false, 'message' => 'Échec du vidage du cache APCu'], 500);
}

jsonResponse(['success' => true, 'message' => 'Cache vidé']);
