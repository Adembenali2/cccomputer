<?php
// /import/diag_ionos.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

try {
    $projectRoot = dirname(__DIR__);
    $includesDb  = $projectRoot . '/includes/db.php';

    // Candidats worker (tous testés)
    $workerCandidates = [
        $projectRoot . '/API/SCRIPTS/ionos_to_compteur.php',
        $projectRoot . '/API/Scripts/ionos_to_compteur.php',
        $projectRoot . '/API/scripts/ionos_to_compteur.php',
        $projectRoot . '/import/ionos_to_compteur.php',
    ];

    $checks = [
        'php_version' => PHP_VERSION,
        'sapi'        => PHP_SAPI,
        'cwd'         => getcwd(),
        'projectRoot' => $projectRoot,
        'includes_db' => [
            'path'       => $includesDb,
            'exists'     => is_file($includesDb),
            'readable'   => is_readable($includesDb),
        ],
        'workers' => [],
        'env' => [
            'MYSQLHOST'     => getenv('MYSQLHOST') ?: null,
            'MYSQLPORT'     => getenv('MYSQLPORT') ?: null,
            'MYSQLDATABASE' => getenv('MYSQLDATABASE') ?: null,
            'MYSQLUSER'     => getenv('MYSQLUSER') ?: null,
            'MYSQLPASSWORD' => (getenv('MYSQLPASSWORD') !== false),
            'IONOS_HOST' => getenv('IONOS_HOST') ?: null,
            'IONOS_PORT' => getenv('IONOS_PORT') ?: null,
            'IONOS_DB'   => getenv('IONOS_DB') ?: null,
            'IONOS_USER' => getenv('IONOS_USER') ?: null,
            'IONOS_PASS' => (getenv('IONOS_PASS') !== false),
        ],
    ];

    foreach ($workerCandidates as $cand) {
        $checks['workers'][] = [
            'path'     => $cand,
            'exists'   => is_file($cand),
            'readable' => is_readable($cand),
        ];
    }

    // Test connexion DB Railway (si includes présent)
    $db_ok = null; $db_err = null;
    if (is_file($includesDb)) {
        try {
            require_once $includesDb; // doit exposer $pdo
            if (isset($pdo) && $pdo instanceof PDO) {
                $pdo->query('SELECT 1');
                $db_ok = true;
            } else {
                $db_ok = false;
                $db_err = 'includes/db.php ne définit pas $pdo';
            }
        } catch (Throwable $e) {
            $db_ok = false;
            $db_err = $e->getMessage();
        }
    }

    echo json_encode([
        'ok'     => true,
        'checks' => $checks,
        'db_ok'  => $db_ok,
        'db_err' => $db_err,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'    => false,
        'error' => $e->getMessage()
    ]);
}
