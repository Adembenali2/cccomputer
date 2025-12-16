<?php
/**
 * API/import/ionos_status.php
 * Endpoint GET pour récupérer le statut du dernier import IONOS
 * 
 * Retourne la dernière ligne de import_run pour type=ionos
 * 
 * IMPORTANT: Retourne TOUJOURS du JSON valide, même en cas d'erreur
 */

// Forcer les headers JSON dès le début (avant toute sortie)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
}

// Nettoyer toute sortie bufferisée
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    // Charger les dépendances
    require_once __DIR__ . '/../../includes/session_config.php';
    require_once __DIR__ . '/../../includes/api_helpers.php';
    
    // Vérifier l'authentification (sans utiliser auth.php qui fait des redirections HTML)
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Authentification requise',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Récupérer PDO
    $pdo = getPdoOrFail();
    
    // Récupérer le dernier run IONOS depuis import_run
    // Filtrer via msg LIKE '%"type":"ionos"%'
    $stmt = $pdo->prepare("
        SELECT 
            id,
            ran_at,
            imported,
            skipped,
            ok,
            msg
        FROM import_run
        WHERE msg LIKE '%\"type\":\"ionos\"%'
        ORDER BY ran_at DESC
        LIMIT 1
    ");
    
    $stmt->execute();
    $lastRun = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Parser le message JSON
    $messageData = null;
    if ($lastRun && !empty($lastRun['msg'])) {
        $messageData = json_decode($lastRun['msg'], true);
    }
    
    // Construire la réponse
    $response = [
        'ok' => true,
        'server_time' => date('Y-m-d H:i:s'),
        'has_run' => $lastRun !== false,
    ];
    
    if ($lastRun) {
        $response['last_run'] = [
            'id' => (int)$lastRun['id'],
            'ran_at' => $lastRun['ran_at'],
            'imported' => (int)$lastRun['imported'],
            'skipped' => (int)$lastRun['skipped'],
            'ok' => (int)$lastRun['ok'] === 1,
            'message' => $messageData['message'] ?? null,
            'error' => $messageData['error'] ?? null,
            'inserted' => $messageData['inserted'] ?? (int)$lastRun['imported'],
            'duration_ms' => $messageData['duration_ms'] ?? null,
        ];
    } else {
        $response['last_run'] = null;
    }
    
    // Retourner la réponse JSON
    echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
    
} catch (Throwable $e) {
    // En cas d'erreur, retourner du JSON valide
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'server_time' => date('Y-m-d H:i:s'),
        'has_run' => false,
        'last_run' => null
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}

