<?php
/**
 * API/import/sftp_status.php
 * Endpoint GET pour récupérer le statut du dernier import SFTP
 * 
 * Retourne la dernière ligne de import_run pour type=sftp
 * et les derniers fichiers en erreur depuis import_run_item
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
    requireApiAuth();
    
    // Récupérer PDO
    $pdo = getPdoOrFail();
    
    // Récupérer le dernier run SFTP depuis import_run
    // Filtrer via msg LIKE '%"type":"sftp"%'
    $stmt = $pdo->prepare("
        SELECT 
            id,
            ran_at,
            imported,
            skipped,
            ok,
            msg
        FROM import_run
        WHERE msg LIKE '%\"type\":\"sftp\"%'
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
        $filesProcessed = $messageData['files_processed'] ?? 0;
        $filesDeleted = $messageData['files_deleted'] ?? 0;
        
        if ($isOk && !$hasError && $filesProcessed > 0 && $filesProcessed === $filesDeleted) {
            $status = 'RUN_OK';
        } elseif ($isOk && !$hasError && $filesProcessed > 0 && $filesDeleted < $filesProcessed) {
            $status = 'PARTIAL';
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
            'files_seen' => $messageData['files_seen'] ?? null,
            'files_processed' => $filesProcessed,
            'files_deleted' => $filesDeleted,
            'inserted_rows' => $messageData['inserted_rows'] ?? (int)$lastRun['imported'],
            'duration_ms' => $durationMs,
            'dry_run' => $messageData['dry_run'] ?? false,
        ];
        
        // Récupérer les derniers fichiers en erreur depuis import_run_item (si table existe)
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
            $errorFiles = $errorStmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($errorFiles)) {
                $response['lastRun']['error_files'] = array_map(function($file) {
                    return [
                        'filename' => $file['filename'],
                        'error' => $file['error'],
                        'processed_at' => $file['processed_at']
                    ];
                }, $errorFiles);
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

