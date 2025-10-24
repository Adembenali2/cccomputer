<?php
// public/run-import.php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors','1');

// --- sécurité basique : POST + CSRF en session ---
require_once __DIR__ . '/../includes/auth.php'; // garde ta protection d'accès
session_start();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  header('Allow: POST');
  exit("Method Not Allowed\n");
}
if (empty($_POST['csrf']) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $_SESSION['csrf'] = $_SESSION['csrf'])) {
  // NB: on compare à la valeur en session, set plus bas dans dashboard.php
  if (!hash_equals($_POST['csrf'] ?? '', $_SESSION['csrf'] ?? '')) {
    http_response_code(403);
    exit("forbidden\n");
  }
}

// — pour forcer l’affichage des logs en direct
@ini_set('output_buffering', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
while (ob_get_level()) { ob_end_flush(); }
ob_implicit_flush(true);
ignore_user_abort(true);
set_time_limit(300);

header('Content-Type: text/plain; charset=utf-8');

echo "=== run-import.php start ===\n";

// ➜ ADAPTE LE CHEMIN VERS TON SCRIPT DEBUG (celui avec M1..M6)
$scriptPath = __DIR__ . '/../api/scripts/upload_compteur.php';
if (!is_file($scriptPath)) {
  echo "❌ Script introuvable: $scriptPath\n";
  exit("=== end ===\n");
}

// on capture la sortie du script et on la renvoie
ob_start();
try {
  require $scriptPath; // ce script echo déjà les milestones M1..M6
} catch (Throwable $e) {
  echo "❌ Exception: " . $e->getMessage() . "\n";
}
$out = ob_get_clean();
echo $out;

echo "=== run-import.php end ===\n";
