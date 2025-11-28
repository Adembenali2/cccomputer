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
    
    $projectRoot = dirname(__DIR__);
    require_once $projectRoot . '/includes/db.php';
    
    if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
        throw new Exception('PDO non initialisé');
    }
    
    $pdo = $GLOBALS['pdo'];
    
    /**
     * Table KV pour anti-bouclage
     */
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS app_kv (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT NULL
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
    
    $INTERVAL = 60; // 1 minute en secondes (comme demandé)
    $key      = 'ancien_last_run';
    
    $stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
    $stmt->execute([$key]);
    $last = $stmt->fetchColumn();
    $due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;
    
    if (!$due) {
      echo json_encode(['ran' => false, 'reason' => 'not_due', 'last_run' => $last], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    
    $pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);
    
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellcmd($php) . ' ' . escapeshellarg(__DIR__ . '/import_ancien_http.php');
    $desc = [
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];
    
    $env = $_ENV + $_SERVER;
    
    $proc = proc_open($cmd, $desc, $pipes, __DIR__, $env);
    $out = $err = '';
    $code = null;
    
    if (is_resource($proc)) {
      $out  = stream_get_contents($pipes[1]); fclose($pipes[1]);
      $err  = stream_get_contents($pipes[2]); fclose($pipes[2]);
      $code = proc_close($proc);
    }
    
    echo json_encode([
      'ran'      => true,
      'stdout'   => trim($out),
      'stderr'   => trim($err),
      'last_run' => date('Y-m-d H:i:s'),
      'code'     => $code
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Erreur: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}



