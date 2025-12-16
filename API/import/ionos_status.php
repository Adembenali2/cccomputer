<?php
/**
 * API/import/ionos_status.php
 * Endpoint GET pour récupérer le statut du dernier import IONOS
 * 
 * Retourne la dernière ligne de import_run pour type=ionos
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

// Vérifier l'authentification
if (!isLoggedIn()) {
    apiFail('Authentification requise', 401);
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

jsonResponse($response);

