<?php
/**
 * API/import/ionos_status.php
 * Endpoint GET pour récupérer le statut du dernier import IONOS
 * 
 * Retourne la dernière ligne de import_run pour type=ionos
 * et les dernières lignes en erreur depuis import_run_item
 * 
 * IMPORTANT: Retourne TOUJOURS du JSON valide, même en cas d'erreur
 */

// Forcer les headers JSON dès le début (avant toute sortie)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Nettoyer toute sortie bufferisée
while (ob_get_level() > 0) {
    ob_end_clean();
}

try {
    // Charger les dépendances
    require_once __DIR__ . '/../../includes/api_helpers.php';
    
    initApi();
    
    // Pour ce endpoint, on retourne une réponse gracieuse si non authentifié
    // pour éviter le spam dans les logs Railway (appelé depuis dashboard même si non connecté)
    if (empty($_SESSION['user_id'])) {
        echo json_encode([
            'ok' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'has_run' => false,
            'lastRun' => null
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    }
    
    // Récupérer PDO (gérer gracieusement les erreurs)
    try {
        $pdo = getPdo();
    } catch (RuntimeException $e) {
        error_log('ionos_status.php: getPdo() failed - ' . $e->getMessage());
        echo json_encode([
            'ok' => true,
            'server_time' => date('Y-m-d H:i:s'),
            'has_run' => false,
            'lastRun' => null
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
        exit;
    }
    
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
        // Calculer le statut
        $status = 'UNKNOWN';
        $isOk = (int)$lastRun['ok'] === 1;
        $hasError = !empty($messageData['error'] ?? null);
        $rowsProcessed = $messageData['rows_processed'] ?? 0;
        $rowsInserted = $messageData['rows_inserted'] ?? 0;
        
        if ($isOk && !$hasError && $rowsProcessed > 0 && $rowsInserted > 0) {
            $status = 'RUN_OK';
        } elseif ($isOk && !$hasError && $rowsProcessed > 0 && $rowsInserted === 0) {
            $status = 'PARTIAL'; // Toutes les lignes étaient des doublons
        } elseif (!$isOk || $hasError) {
            $status = 'RUN_FAILED';
        }
        
        // Calculer started_at et ended_at depuis ran_at et duration_ms
        $ranAt = new DateTime($lastRun['ran_at']);
        $durationMs = $messageData['duration_ms'] ?? 0;
        $startedAt = clone $ranAt;
        $endedAt = clone $ranAt;
        if ($durationMs > 0) {
            $startedAt->modify('-' . round($durationMs / 1000) . ' seconds');
        }
        
        $response['lastRun'] = [
            'id' => (int)$lastRun['id'],
            'status' => $status,
            'ran_at' => $lastRun['ran_at'],
            'started_at' => $startedAt->format('Y-m-d H:i:s'),
            'ended_at' => $endedAt->format('Y-m-d H:i:s'),
            'imported' => (int)$lastRun['imported'],
            'skipped' => (int)$lastRun['skipped'],
            'ok' => $isOk,
            'message' => $messageData['message'] ?? null,
            'error' => $messageData['error'] ?? null,
            'rows_seen' => $messageData['rows_seen'] ?? null,
            'rows_processed' => $rowsProcessed,
            'rows_inserted' => $rowsInserted,
            'rows_skipped' => $messageData['rows_skipped'] ?? 0,
            'duration_ms' => $durationMs,
            'dry_run' => $messageData['dry_run'] ?? false,
        ];
        
        // Récupérer les dernières lignes en erreur depuis import_run_item (si table existe)
        try {
            $errorStmt = $pdo->prepare("
                SELECT 
                    filename,
                    status,
                    inserted_rows,
                    error,
                    duration_ms,
                    processed_at
                FROM import_run_item
                WHERE run_id = :run_id AND status = 'error'
                ORDER BY processed_at DESC
                LIMIT 10
            ");
            $errorStmt->execute([':run_id' => $lastRun['id']]);
            $errorRows = $errorStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($errorRows)) {
                $response['lastRun']['error_rows'] = array_map(function($row) {
                    return [
                        'id' => $row['filename'],
                        'error' => $row['error'],
                        'processed_at' => $row['processed_at']
                    ];
                }, $errorRows);
            }
        } catch (Throwable $e) {
            // Table import_run_item peut ne pas exister encore, ignorer
        }
    } else {
        $response['lastRun'] = null;
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
        'lastRun' => null
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
    exit;
}
