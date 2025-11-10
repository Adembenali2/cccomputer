<?php
// /import/run_ionos_if_due.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'FATAL in run_ionos_if_due.php',
            'type'    => $e['type'],
            'message' => $e['message'],
            'file'    => $e['file'],
            'line'    => $e['line'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

try {
    $projectRoot = dirname(__DIR__);

    // --- paramètres ---
    $mode   = strtolower((string)($_GET['mode'] ?? $_POST['mode'] ?? 'latest')); // latest | backfill
    $force  = (int)($_GET['force'] ?? $_POST['force'] ?? 0);                     // 1 pour bypass anti-bouclage
    $limit  = (int)(getenv('IONOS_BATCH_LIMIT') ?: ($_GET['limit'] ?? $_POST['limit'] ?? 10));
    if ($limit <= 0) $limit = 10;
    putenv('IONOS_BATCH_LIMIT=' . (string)$limit);

    // --- worker à utiliser ---
    $workerCandidates = [];
    if ($mode === 'backfill') {
        $workerCandidates = [
            $projectRoot . '/API/SCRIPTS/ionos_backfill_all.php',
            $projectRoot . '/API/Scripts/ionos_backfill_all.php',
            $projectRoot . '/API/scripts/ionos_backfill_all.php',
            $projectRoot . '/import/ionos_backfill_all.php',
        ];
    } else {
        $workerCandidates = [
            $projectRoot . '/API/SCRIPTS/ionos_to_compteur.php', // dernier relevé par MAC
            $projectRoot . '/API/Scripts/ionos_to_compteur.php',
            $projectRoot . '/API/scripts/ionos_to_compteur.php',
            $projectRoot . '/import/ionos_to_compteur.php',
        ];
    }
    $worker = null;
    foreach ($workerCandidates as $cand) { if (is_file($cand)) { $worker = $cand; break; } }

    // --- DB destination ---
    $includesDb = $projectRoot . '/includes/db.php';
    if (!is_file($includesDb)) {
        http_response_code(500);
        echo json_encode(['error'=>'includes/db.php not found','path'=>$includesDb]); exit;
    }
    require_once $includesDb; // $pdo

    if (!$worker) {
        http_response_code(500);
        echo json_encode(['error'=>'worker not found','mode'=>$mode,'tried'=>$workerCandidates]); exit;
    }

    // --- anti-bouclage (désactivable via force=1) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_kv (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 20);
    $key      = $mode === 'backfill' ? 'ionos_backfill_last_run' : 'ionos_last_run';

    $last = $pdo->query("SELECT v FROM app_kv WHERE k='{$key}'")->fetchColumn();
    $due  = $force ? true : ((time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL);

    if (!$due) {
        echo json_encode(['ran'=>false,'reason'=>'not_due','last_run'=>$last,'mode'=>$mode]); exit;
    }
    $pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

    // --- exécuter le worker (il echo un JSON et peut exit) ---
    ob_start();
    try {
        require $worker;
        $out = ob_get_clean();
        if ($out !== '') echo $out;
    } catch (Throwable $we) {
        $out = ob_get_clean();
        http_response_code(500);
        echo json_encode([
            'error'=>'worker exception','mode'=>$mode,'worker'=>$worker,
            'message'=>$we->getMessage(),'bufferedOut'=>$out
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error'=>'run_ionos_if_due.php crash','detail'=>$e->getMessage()]);
}
