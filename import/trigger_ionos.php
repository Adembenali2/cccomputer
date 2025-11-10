<?php
// public/import/trigger_ionos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// POST only
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
  http_response_code(405);
  echo json_encode(['error'=>'POST only']); exit;
}

// (Option) petit token si vous voulez restreindre l’appel
$expected = getenv('IMPORT_TOKEN') ?: null;
if ($expected) {
  $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('~^Bearer\s+(.+)$~i', $auth, $m) || !hash_equals($expected, trim($m[1]))) {
    http_response_code(401);
    echo json_encode(['error'=>'Unauthorized']); exit;
  }
}

// délègue au runner hors webroot
$runner = dirname(__DIR__, 2) . '/import/run_ionos_if_due.php';
if (!is_file($runner)) {
  http_response_code(500);
  echo json_encode(['error'=>'Runner not found','path'=>$runner]); exit;
}

require $runner; // renvoie le JSON du runner
