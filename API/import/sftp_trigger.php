<?php
/**
 * API/import/sftp_trigger.php
 * Endpoint POST pour déclencher manuellement l'import SFTP
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
    // Vérifier que c'est une requête POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode([
            'ok' => false,
            'error' => 'Méthode non autorisée. Utilisez POST.',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Charger les dépendances
    require_once __DIR__ . '/../../includes/session_config.php';
    require_once __DIR__ . '/../../includes/api_helpers.php';
    
    // Vérifier l'authentification
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'ok' => false,
            'error' => 'Authentification requise',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Vérifier le CSRF token
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!verifyCsrfToken($csrfToken)) {
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'Token CSRF invalide',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Récupérer PDO
    $pdo = getPdoOrFail();
    
    // Vérifier le lock MySQL (éviter exécutions parallèles)
    $lockName = 'import_sftp';
    $lockStmt = $pdo->prepare("SELECT GET_LOCK(:lock_name, 0) as lock_acquired");
    $lockStmt->execute([':lock_name' => $lockName]);
    $lockResult = $lockStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$lockResult || (int)$lockResult['lock_acquired'] !== 1) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'error' => 'Un import est déjà en cours. Veuillez patienter.',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Libérer le lock (le script va le réacquérir)
    $pdo->query("SELECT RELEASE_LOCK('$lockName')");
    
    // Chemin vers le script
    $scriptPath = __DIR__ . '/../../scripts/import_sftp_cron.php';
    
    if (!file_exists($scriptPath)) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Script d\'import introuvable',
            'server_time' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Exécuter le script via CLI avec le flag --all pour importer tous les fichiers
    $startTime = microtime(true);
    $output = [];
    $returnCode = 0;
    
    // Exécuter le script PHP en CLI avec --all pour importer tous les fichiers
    $command = 'php ' . escapeshellarg($scriptPath) . ' --all 2>&1';
    exec($command, $output, $returnCode);
    
    $duration = round((microtime(true) - $startTime) * 1000); // ms
    
    // Attendre un peu pour que les logs soient écrits en base
    usleep(500000); // 500ms
    
    // Récupérer le dernier run depuis la base pour avoir les détails
    $lastRunStmt = $pdo->prepare("
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
    $lastRunStmt->execute();
    $lastRun = $lastRunStmt->fetch(PDO::FETCH_ASSOC);
    
    $messageData = null;
    if ($lastRun && !empty($lastRun['msg'])) {
        $messageData = json_decode($lastRun['msg'], true);
    }
    
    // Réponse avec les résultats
    echo json_encode([
        'ok' => $returnCode === 0,
        'message' => $returnCode === 0 ? 'Import terminé avec succès' : 'Erreur lors de l\'import',
        'server_time' => date('Y-m-d H:i:s'),
        'duration_ms' => $duration,
        'last_run' => $lastRun ? [
            'id' => (int)$lastRun['id'],
            'ran_at' => $lastRun['ran_at'],
            'files_processed' => $messageData['files_processed'] ?? 0,
            'files_deleted' => $messageData['files_deleted'] ?? 0,
            'inserted_rows' => $messageData['inserted_rows'] ?? (int)$lastRun['imported'],
            'ok' => (int)$lastRun['ok'] === 1,
            'error' => $messageData['error'] ?? null
        ] : null
    ], JSON_UNESCAPED_UNICODE);
    exit;
    
} catch (Throwable $e) {
    // En cas d'erreur, retourner du JSON valide
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'server_time' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

