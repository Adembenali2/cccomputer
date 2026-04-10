<?php
/**
 * API/import/check_log.php
 * Enregistre une vérification manuelle des imports dans l'historique
 */

require_once __DIR__ . '/../../includes/session_config.php';
require_once __DIR__ . '/../../includes/api_helpers.php';

// Vérifier l'authentification (sans utiliser auth.php qui fait des redirections HTML)
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Authentification requise',
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Accepter seulement POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Méthode non autorisée'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Récupérer PDO
$pdo = getPdoOrFail();

// Récupérer les données POST (JSON)
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || !isset($data['checks'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Données invalides'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$checks = $data['checks']; // Tableau de vérifications [{type: 'sftp', ...}, {type: 'ionos', ...}]

try {
    foreach ($checks as $check) {
        $type = $check['type'] ?? 'unknown'; // 'sftp', 'ionos', etc.
        $hasRun = $check['has_run'] ?? false;
        $status = $check['status'] ?? 'UNKNOWN';
        $lastRun = $check['last_run'] ?? null;
        $error = $check['error'] ?? null;
        
        // Préparer les données pour le msg JSON
        $msgData = [
            'type' => $type . '_check', // 'sftp_check' ou 'ionos_check' pour distinguer des vrais imports
            'source' => 'manual_check',
            'has_run' => $hasRun,
            'status' => $status,
            'checked_at' => date('Y-m-d H:i:s')
        ];
        
        // Ajouter les détails du dernier run si disponible
        if ($hasRun && $lastRun) {
            $msgData['last_run_id'] = $lastRun['id'] ?? null;
            $msgData['last_run_status'] = $lastRun['status'] ?? 'UNKNOWN';
            $msgData['last_run_at'] = $lastRun['ended_at'] ?? $lastRun['ran_at'] ?? null;
            
            if ($type === 'sftp') {
                $msgData['files_seen'] = $lastRun['files_seen'] ?? 0;
                $msgData['files_processed'] = $lastRun['files_processed'] ?? 0;
                $msgData['files_deleted'] = $lastRun['files_deleted'] ?? 0;
                $msgData['inserted_rows'] = $lastRun['inserted_rows'] ?? 0;
            } else if ($type === 'ionos') {
                $msgData['rows_seen'] = $lastRun['rows_seen'] ?? 0;
                $msgData['rows_processed'] = $lastRun['rows_processed'] ?? 0;
                $msgData['rows_inserted'] = $lastRun['rows_inserted'] ?? 0;
                $msgData['rows_skipped'] = $lastRun['rows_skipped'] ?? 0;
            }
            
            $msgData['duration_ms'] = $lastRun['duration_ms'] ?? 0;
        }
        
        if ($error) {
            $msgData['error'] = $error;
        }
        
        // Déterminer imported et skipped
        $imported = 0;
        $skipped = 0;
        if ($hasRun && $lastRun) {
            if ($type === 'sftp') {
                $imported = $lastRun['inserted_rows'] ?? 0;
            } else if ($type === 'ionos') {
                $imported = $lastRun['rows_inserted'] ?? 0;
                $skipped = $lastRun['rows_skipped'] ?? 0;
            }
        }
        
        // Déterminer ok
        $ok = ($status === 'RUN_OK' || $status === 'PARTIAL') && empty($error);
        
        // Insérer dans import_run
        $stmt = $pdo->prepare("
            INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
            VALUES (NOW(), :imported, :skipped, :ok, :msg)
        ");
        $stmt->execute([
            ':imported' => $imported,
            ':skipped' => $skipped,
            ':ok' => $ok ? 1 : 0,
            ':msg' => json_encode($msgData, JSON_UNESCAPED_UNICODE)
        ]);
    }
    
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Vérification(s) enregistrée(s) dans l\'historique'
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    error_log('check_log.php error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur lors de l\'enregistrement: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

