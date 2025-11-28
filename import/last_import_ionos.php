<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
    // Démarrer la session si elle n'est pas déjà démarrée
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Vérifier l'authentification sans redirection
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Non authentifié']);
        exit;
    }
    
    require_once dirname(__DIR__) . '/includes/db.php';
    
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new Exception('PDO non initialisé');
    }
    
    $pdo = $GLOBALS['pdo'];
    
    // Récupérer le dernier import avec source = 'ionos_import'
    $stmt = $pdo->prepare("
        SELECT id, ran_at, imported, skipped, ok, msg 
        FROM import_run 
        WHERE JSON_EXTRACT(msg, '$.source') = 'ionos_import'
        ORDER BY id DESC 
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) { 
        echo json_encode(['has_run'=>false]); 
        exit; 
    }
    
    $summary = null;
    if (!empty($row['msg'])) {
        $decoded = json_decode((string)$row['msg'], true);
        if (is_array($decoded)) $summary = $decoded;
    }
    $recent = (time() - strtotime((string)$row['ran_at'])) < 180; // < 3 min
    echo json_encode([
        'has_run'  => true,
        'id'       => (int)$row['id'],
        'ran_at'   => (string)$row['ran_at'],
        'imported' => (int)$row['imported'],
        'skipped'  => (int)$row['skipped'],
        'ok'       => (int)$row['ok'],
        'recent'   => $recent ? 1 : 0,
        'summary'  => $summary
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erreur: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

