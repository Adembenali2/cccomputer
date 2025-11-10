<?php
// /import/trigger_ionos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error'=>'POST only']); exit;
  }

  // Limite batch transmise au runner
  $limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 10);
  if ($limit <= 0) $limit = 10;
  putenv('IONOS_BATCH_LIMIT='.(string)$limit);

  // ⬅️ Runner est dans le MÊME dossier /import
  $runner = __DIR__ . '/run_ionos_if_due.php';
  if (!is_file($runner)) {
    http_response_code(500);
    echo json_encode(['error'=>'Runner not found','path'=>$runner]); exit;
  }

  require $runner; // renvoie un JSON
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'trigger_ionos.php crash','detail'=>$e->getMessage()]);
}
