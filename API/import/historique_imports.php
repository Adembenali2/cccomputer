<?php
/**
 * API/import/historique_imports.php
 * Retourne les derniers enregistrements d'import depuis l'historique
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    require_once __DIR__ . '/../../includes/session_config.php';
    require_once __DIR__ . '/../../includes/api_helpers.php';
    require_once __DIR__ . '/../../includes/historique.php';
    
    initApi();
    
    if (empty($_SESSION['user_id'])) {
        echo json_encode(['ok' => true, 'items' => [], 'has_error' => false]);
        exit;
    }
    
    $pdo = getPdo();
    $stmt = $pdo->query("
        SELECT id, action, details, date_action 
        FROM historique 
        WHERE action LIKE 'import_%' 
        ORDER BY date_action DESC 
        LIMIT 15
    ");
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hasError = false;
    if (!empty($items) && in_array($items[0]['action'], ['import_sftp_error', 'import_ionos_error'], true)) {
        $hasError = true;
    }
    
    $formatted = array_map(function ($h) {
        $isError = in_array($h['action'], ['import_sftp_error', 'import_ionos_error'], true);
        return [
            'id' => (int)$h['id'],
            'action' => $h['action'],
            'label' => formatActionLabel($h['action']),
            'details' => $h['details'] ?? '',
            'date_action' => $h['date_action'],
            'date_formatted' => date('d/m H:i', strtotime($h['date_action'])),
            'is_error' => $isError,
        ];
    }, $items);
    
    echo json_encode([
        'ok' => true,
        'items' => $formatted,
        'has_error' => $hasError,
        'last_error' => $hasError ? $items[0] : null,
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'items' => [],
        'has_error' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}
