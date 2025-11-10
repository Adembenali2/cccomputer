<?php
// /import/trigger_ionos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Attrape les fatales (parse error, require manquant, etc.)
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'FATAL in trigger_ionos.php',
            'type'    => $e['type'],
            'message' => $e['message'],
            'file'    => $e['file'],
            'line'    => $e['line'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

try {
    // POST only
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => 'POST only'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Limite batch
    $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 10);
    if ($limit <= 0) { $limit = 10; }
    putenv('IONOS_BATCH_LIMIT=' . (string)$limit);

    // Runner
    $runner = __DIR__ . '/run_ionos_if_due.php';
    if (!is_file($runner)) {
        http_response_code(500);
        echo json_encode([
            'error' => 'Runner not found',
            'path'  => $runner,
            'cwd'   => getcwd(),
            'dir'   => __DIR__,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    // Délègue au runner (il doit echo un JSON puis exit)
    require $runner;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'trigger_ionos.php crash',
        'detail' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
