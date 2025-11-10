<?php
// /import/run_ionos_if_due.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

// Attrape les fatales
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
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
    $projectRoot = dirname(__DIR__); // racine du projet

    // ——— Détection robuste du worker ———
    $workerCandidates = [
        $projectRoot . '/API/SCRIPTS/ionos_to_compteur.php', // chemin attendu
        $projectRoot . '/API/Scripts/ionos_to_compteur.php', // variations de casse
        $projectRoot . '/API/scripts/ionos_to_compteur.php',
        $projectRoot . '/import/ionos_to_compteur.php',      // si tu le places dans /import
    ];

    $worker = null;
    foreach ($workerCandidates as $cand) {
        if (is_file($cand)) { $worker = $cand; break; }
    }

    // DB (passe via includes → config/db.php)
    $includesDb = $projectRoot . '/includes/db.php';
    if (!is_file($includesDb)) {
        http_response_code(500);
        echo json_encode([
            'error'       => 'includes/db.php not found',
            'projectRoot' => $projectRoot,
            'path'        => $includesDb
        ]);
        exit;
    }
    if (!$worker) {
        http_response_code(500);
        echo json_encode([
            'error'       => 'ionos_to_compteur.php not found',
            'tried'       => $workerCandidates,
            'projectRoot' => $projectRoot
        ]);
        exit;
    }

    require_once $includesDb; // crée $pdo

    // Anti-bouclage (20s par défaut)
    $pdo->exec("CREATE TABLE IF NOT EXISTS app_kv (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 20);
    $key      = 'ionos_last_run';

    $last = $pdo->query("SELECT v FROM app_kv WHERE k='{$key}'")->fetchColumn();
    $due  = (time() - ($last ? strtotime((string)$last) : 0)) >= $INTERVAL;

    if (!$due) {
        echo json_encode([
            'ran'      => false,
            'reason'   => 'not_due',
            'last_run' => $last
        ]);
        exit;
    }

    // Marquer le run
    $pdo->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$key]);

    // Limite batch (priorité à env, sinon GET/POST, sinon 10)
    $limit = (int)(getenv('IONOS_BATCH_LIMIT') ?: ($_GET['limit'] ?? $_POST['limit'] ?? 10));
    if ($limit <= 0) $limit = 10;
    putenv('IONOS_BATCH_LIMIT=' . (string)$limit);

    // Lancer le worker : il doit echo un JSON puis exit
    require $worker;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error'  => 'run_ionos_if_due.php crash',
        'detail' => $e->getMessage()
    ]);
}
