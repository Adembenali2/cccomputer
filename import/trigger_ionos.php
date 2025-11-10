<?php
// /public/import/trigger_ionos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'POST only']); exit;
  }

  // (Option) Sécurité par token
  $expected = getenv('IMPORT_TOKEN') ?: null;
  if ($expected) {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('~^Bearer\s+(.+)$~i', $auth, $m) || !hash_equals($expected, trim($m[1]))) {
      http_response_code(401);
      echo json_encode(['error'=>'Unauthorized']); exit;
    }
  }

  // Délègue au runner hors public
  $runner = dirname(__DIR__, 2) . '/import/run_ionos_if_due.php';
  if (!is_file($runner)) {
    http_response_code(500);
    echo json_encode(['error'=>'Runner not found','path'=>$runner]); exit;
  }

  require $runner; // renvoie un JSON
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'trigger_ionos.php crash','detail'=>$e->getMessage()]);
}
