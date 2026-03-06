<?php
// API pour récupérer les clients de la tournée du jour (livraisons + SAV programmés aujourd'hui)
// Utilisée par maps.php pour charger automatiquement la tournée du jour

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) ob_end_clean();
        http_response_code(500);
        if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Erreur fatale du serveur',
            'message' => $error['message'],
            'file' => basename($error['file']),
            'line' => $error['line']
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $data, int $statusCode = 200) {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code($statusCode);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/helpers.php';
    require_once __DIR__ . '/../includes/api_helpers.php';
} catch (Throwable $e) {
    error_log('maps_get_today_route.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation: ' . $e->getMessage()], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

try {
    $pdo = getPdoOrFail();
} catch (Throwable $e) {
    error_log('maps_get_today_route.php getPdoOrFail error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

// Date du jour côté serveur (évite les écarts de fuseau)
$today = date('Y-m-d');

$clientIds = [];

try {
    // Règle métier : livraison du jour = date_prevue = aujourd'hui ET statut non livré / non annulé
    $sqlLiv = "
        SELECT DISTINCT l.id_client
        FROM livraisons l
        WHERE l.id_client IS NOT NULL
          AND l.date_prevue = :today
          AND l.statut NOT IN ('livree', 'annulee')
    ";
    $stmtLiv = $pdo->prepare($sqlLiv);
    $stmtLiv->execute([':today' => $today]);
    while ($row = $stmtLiv->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id_client'];
        if ($id > 0) $clientIds[$id] = true;
    }

    // Règle métier : SAV du jour = date prévue = aujourd'hui ET statut non résolu / non annulé
    // Date prévue : date_intervention_prevue si existe, sinon date_ouverture
    $hasDateIntervention = false;
    if (function_exists('columnExists')) {
        $hasDateIntervention = columnExists($pdo, 'sav', 'date_intervention_prevue');
    }

    $dateCondition = $hasDateIntervention
        ? "(COALESCE(s.date_intervention_prevue, s.date_ouverture) = :today)"
        : "(s.date_ouverture = :today)";

    $sqlSav = "
        SELECT DISTINCT s.id_client
        FROM sav s
        WHERE s.id_client IS NOT NULL
          AND {$dateCondition}
          AND s.statut NOT IN ('resolu', 'annule')
    ";
    $stmtSav = $pdo->prepare($sqlSav);
    $stmtSav->execute([':today' => $today]);
    while ($row = $stmtSav->fetch(PDO::FETCH_ASSOC)) {
        $id = (int)$row['id_client'];
        if ($id > 0) $clientIds[$id] = true;
    }

    $clientIds = array_keys($clientIds);
    sort($clientIds);

} catch (PDOException $e) {
    error_log('maps_get_today_route.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
}

jsonResponse([
    'ok' => true,
    'date' => $today,
    'clientIds' => $clientIds,
    'count' => count($clientIds)
]);
