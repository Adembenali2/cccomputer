<?php
// /import/run_ionos_if_due.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
  $projectRoot = dirname(__DIR__); // racine du projet

  // Connexion DB DEST (Railway) — même que le front
  require_once $projectRoot . '/includes/db.php'; // $pdo

  // Anti-bouclage: 20s par défaut
  $pdo->exec("CREATE TABLE IF NOT EXISTS app_kv (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
  $INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 20);
  $key = 'ionos_last_run';

  $last = $pdo->query("SELECT v FROM app_kv WHERE k='{$key}'")->fetchColumn();
  $due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;
  if (!$due) {
    echo json_encode(['ran'=>false,'reason'=>'not_due','last_run'=>$last]); exit;
  }

  // Marque le run (évite double exécution)
  $pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

  // Batch (priorité env, sinon GET/POST via trigger, sinon 10)
  $limit = (int)(getenv('IONOS_BATCH_LIMIT') ?: ($_GET['limit'] ?? $_POST['limit'] ?? 10));
  if ($limit <= 0) $limit = 10;
  putenv('IONOS_BATCH_LIMIT='.(string)$limit);

  // ⬅️ Worker IONOS depuis la racine
  $worker = $projectRoot . '/API/SCRIPTS/ionos_to_compteur.php';
  if (!is_file($worker)) {
    http_response_code(500);
    echo json_encode(['error'=>'Worker not found','path'=>$worker]); exit;
  }

  // Le worker émet son propre JSON et fait exit
  require $worker;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['error'=>'run_ionos_if_due.php crash','detail'=>$e->getMessage()]);
}
