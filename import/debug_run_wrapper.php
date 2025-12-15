<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * import/debug_run_wrapper.php
 * 
 * Debug end-to-end : exécute exactement la même commande que run_import_if_due.php
 * et capture tout pour comprendre pourquoi le dashboard affiche 0
 */

// ====== SÉCURITÉ ======
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || 
           (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
$debugKey = getenv('DEBUG_KEY') ?: '';
$requestKey = $_GET['key'] ?? '';

if (!$isLocal && $debugKey && $requestKey !== $debugKey) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Access denied. DEBUG_KEY required.']);
    exit;
}

// ====== CONFIGURATION ======
$htmlMode = isset($_GET['html']) && $_GET['html'] === '1';
$limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

// ====== INITIALISATION ======
$result = [
    'ok' => true,
    'ts' => date('Y-m-d H:i:s'),
    'command' => '',
    'cwd' => '',
    'env_masked' => [],
    'proc' => [],
    'parsed' => [],
    'db_check' => [],
    'errors' => []
];

// ====== HELPERS ======
function maskPassword(?string $pass): string {
    if (empty($pass)) return '(empty)';
    $len = strlen($pass);
    if ($len <= 4) return str_repeat('*', $len);
    return substr($pass, 0, 2) . str_repeat('*', $len - 4) . substr($pass, -2);
}

function maskEnv(array $env): array {
    $masked = [];
    $sensitive = ['PASS', 'PASSWORD', 'SECRET', 'KEY', 'TOKEN'];
    
    foreach ($env as $k => $v) {
        $isSensitive = false;
        foreach ($sensitive as $s) {
            if (stripos($k, $s) !== false) {
                $isSensitive = true;
                break;
            }
        }
        $masked[$k] = $isSensitive ? maskPassword($v) : $v;
    }
    
    return $masked;
}

function truncate(string $s, int $max = 10000): string {
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max) . "\n... (tronqué à $max caractères)";
}

function addError(array &$result, string $error, array $context = []): void {
    $result['ok'] = false;
    $result['errors'][] = [
        'error' => $error,
        'context' => $context,
        'ts' => date('Y-m-d H:i:s')
    ];
}

// ====== PARSE COMPTEURS ======
function parseCounters(string $stdout, string $stderr): array {
    $parsed = [
        'inserted' => null,
        'updated' => null,
        'skipped' => null,
        'method' => null,
        'parse_error' => null
    ];
    
    $combined = $stdout . "\n" . $stderr;
    
    // Méthode 1 : Chercher JSON {"source":"SFTP", ...}
    if (preg_match('/\{[^}]*"source"\s*:\s*"SFTP"[^}]*\}/i', $combined, $jsonMatch)) {
        $jsonStr = $jsonMatch[0];
        $decoded = json_decode($jsonStr, true);
        if (is_array($decoded)) {
            if (isset($decoded['inserted'])) {
                $parsed['inserted'] = (int)$decoded['inserted'];
                $parsed['method'] = 'json_inserted';
            }
            if (isset($decoded['updated'])) {
                $parsed['updated'] = (int)$decoded['updated'];
                if (!$parsed['method']) $parsed['method'] = 'json_updated';
            }
            if (isset($decoded['skipped'])) {
                $parsed['skipped'] = (int)$decoded['skipped'];
            }
            if ($parsed['method']) {
                return $parsed;
            }
        }
    }
    
    // Méthode 2 : Chercher patterns texte
    $patterns = [
        'inserted' => [
            '/(\d+)\s+insérés?/i',
            '/inserted[:\s=]+(\d+)/i',
            '/insert[:\s=]+(\d+)/i'
        ],
        'updated' => [
            '/(\d+)\s+mis\s+à\s+jour/i',
            '/updated[:\s=]+(\d+)/i',
            '/update[:\s=]+(\d+)/i'
        ],
        'skipped' => [
            '/(\d+)\s+ignorés?/i',
            '/skipped[:\s=]+(\d+)/i',
            '/skip[:\s=]+(\d+)/i'
        ]
    ];
    
    foreach ($patterns as $key => $patternList) {
        foreach ($patternList as $pattern) {
            if (preg_match($pattern, $combined, $m)) {
                $parsed[$key] = (int)$m[1];
                if (!$parsed['method']) {
                    $parsed['method'] = 'text_pattern_' . $key;
                }
                break;
            }
        }
    }
    
    // Si on a trouvé au moins un compteur, c'est bon
    if ($parsed['inserted'] !== null || $parsed['updated'] !== null || $parsed['skipped'] !== null) {
        return $parsed;
    }
    
    // Méthode 3 : Fallback - parse_error
    $parsed['parse_error'] = 'Aucun compteur trouvé dans stdout/stderr';
    $parsed['method'] = 'fallback';
    
    // Dump des 20 dernières lignes
    $stdoutLines = explode("\n", $stdout);
    $stderrLines = explode("\n", $stderr);
    $lastStdout = array_slice($stdoutLines, -20);
    $lastStderr = array_slice($stderrLines, -20);
    
    $parsed['last_20_stdout'] = $lastStdout;
    $parsed['last_20_stderr'] = $lastStderr;
    
    return $parsed;
}

// ====== EXECUTION ======
try {
    $projectRoot = dirname(__DIR__);
    $result['cwd'] = $projectRoot;
    
    // Vérifier que le script existe
    $scriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
    if (!is_file($scriptPath)) {
        addError($result, "Script upload_compteur.php introuvable: $scriptPath");
        $result['command'] = 'N/A';
        $result['proc'] = ['error' => 'script_not_found'];
    } else {
        // Construire la commande exactement comme run_import_if_due.php
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($scriptPath);
        $result['command'] = $cmd;
        
        // Environnement exactement comme run_import_if_due.php
        $env = $_ENV + $_SERVER + ['SFTP_BATCH_LIMIT' => (string)$limit];
        
        // Masquer les mots de passe pour l'affichage
        $result['env_masked'] = maskEnv($env);
        $result['env_keys'] = array_keys($env);
        $result['env_count'] = count($env);
        
        // Informations système
        $result['system'] = [
            'php_binary' => $php,
            'php_binary_exists' => file_exists($php),
            'php_version' => PHP_VERSION,
            'user' => function_exists('get_current_user') ? get_current_user() : 'unknown',
            'cwd' => getcwd() ?: 'unknown',
            'path' => getenv('PATH') ?: 'not_set'
        ];
        
        // Descripteurs pour proc_open
        $desc = [
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w'], // stderr
        ];
        
        $TIMEOUT_SEC = 60;
        $startTime = microtime(true);
        
        // Exécuter proc_open exactement comme run_import_if_due.php
        $proc = proc_open($cmd, $desc, $pipes, $projectRoot, $env);
        $out = $err = '';
        $code = null;
        $timeoutReached = false;
        
        if (is_resource($proc)) {
            // Configurer les pipes en mode non-bloquant
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            
            $read = [$pipes[1], $pipes[2]];
            $write = null;
            $except = null;
            
            // Lire les pipes avec timeout
            while (is_resource($proc)) {
                $status = proc_get_status($proc);
                
                // Vérifier le timeout
                $elapsed = time() - (int)$startTime;
                if ($elapsed > $TIMEOUT_SEC) {
                    $timeoutReached = true;
                    proc_terminate($proc, SIGTERM);
                    sleep(2);
                    if (proc_get_status($proc)['running']) {
                        proc_terminate($proc, SIGKILL);
                    }
                    $err .= "\nTIMEOUT: Le processus a dépassé la limite de {$TIMEOUT_SEC} secondes";
                    $code = -1;
                    break;
                }
                
                // Si le processus est terminé, lire les dernières données
                if (!$status['running']) {
                    $code = $status['exitcode'];
                    break;
                }
                
                // Lire les données disponibles (non-bloquant)
                $changed = @stream_select($read, $write, $except, 1);
                
                if ($changed === false) {
                    usleep(100000);
                    continue;
                }
                
                if ($changed > 0) {
                    foreach ($read as $pipe) {
                        if ($pipe === $pipes[1]) {
                            $data = stream_get_contents($pipes[1]);
                            if ($data !== false && $data !== '') $out .= $data;
                        } elseif ($pipe === $pipes[2]) {
                            $data = stream_get_contents($pipes[2]);
                            if ($data !== false && $data !== '') $err .= $data;
                        }
                    }
                } else {
                    usleep(100000);
                }
            }
            
            // Lire les dernières données restantes
            $remainingOut = stream_get_contents($pipes[1]);
            $remainingErr = stream_get_contents($pipes[2]);
            if ($remainingOut !== false) $out .= $remainingOut;
            if ($remainingErr !== false) $err .= $remainingErr;
            
            // Fermer les pipes
            fclose($pipes[1]);
            fclose($pipes[2]);
            
            // Fermer le processus si pas déjà fait
            if (is_resource($proc)) {
                $code = proc_close($proc);
            }
        } else {
            $err = 'Impossible de créer le processus';
            $code = -1;
            addError($result, $err);
        }
        
        $durationMs = (microtime(true) - $startTime) * 1000;
        
        $result['proc'] = [
            'exit_code' => $code,
            'duration_ms' => round($durationMs, 2),
            'timed_out' => $timeoutReached,
            'stdout' => truncate($out, 10000),
            'stderr' => truncate($err, 10000),
            'stdout_length' => strlen($out),
            'stderr_length' => strlen($err)
        ];
        
        // Parser les compteurs
        $result['parsed'] = parseCounters($out, $err);
    }
    
    // Vérifier la DB après exécution
    try {
        $dbPath = $projectRoot . '/includes/db.php';
        if (file_exists($dbPath)) {
            require_once $dbPath;
            
            if (isset($pdo) && $pdo instanceof PDO) {
                // Compteur des lignes insérées récemment
                try {
                    $stmt = $pdo->query("
                        SELECT COUNT(*) as cnt 
                        FROM compteur_relevee 
                        WHERE DateInsertion > NOW() - INTERVAL 10 MINUTE
                    ");
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $recentInserted = (int)($row['cnt'] ?? 0);
                } catch (Throwable $e) {
                    $recentInserted = null;
                    addError($result, 'DB query failed (recent_inserted): ' . $e->getMessage());
                }
                
                // Derniers imports
                try {
                    $stmt = $pdo->query("
                        SELECT id, ran_at, imported, skipped, ok, msg 
                        FROM import_run 
                        ORDER BY id DESC 
                        LIMIT 5
                    ");
                    $lastImports = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Décoder les msg JSON si possible
                    foreach ($lastImports as &$import) {
                        if (!empty($import['msg'])) {
                            $decoded = json_decode($import['msg'], true);
                            if (is_array($decoded)) {
                                $import['msg_decoded'] = $decoded;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $lastImports = [];
                    addError($result, 'DB query failed (last_imports): ' . $e->getMessage());
                }
                
                $result['db_check'] = [
                    'recent_inserted_rows' => $recentInserted,
                    'last_import_run_rows' => $lastImports
                ];
            } else {
                addError($result, 'PDO not available after db.php load');
                $result['db_check'] = ['error' => 'PDO not available'];
            }
        } else {
            addError($result, 'db.php not found');
            $result['db_check'] = ['error' => 'db.php not found'];
        }
    } catch (Throwable $e) {
        addError($result, 'DB check failed: ' . $e->getMessage());
        $result['db_check'] = ['error' => $e->getMessage()];
    }
    
} catch (Throwable $e) {
    addError($result, 'Fatal exception: ' . $e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);
    $result['ok'] = false;
}

// ====== OUTPUT ======
if ($htmlMode) {
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <title>Debug Run Wrapper</title>
        <style>
            body { font-family: monospace; margin: 20px; background: #f5f5f5; }
            .section { background: white; padding: 15px; margin: 10px 0; border-radius: 5px; }
            .error { color: red; }
            .ok { color: green; }
            pre { background: #f0f0f0; padding: 10px; overflow-x: auto; font-size: 12px; }
            h2 { margin-top: 0; }
            .stdout { background: #e8f5e9; }
            .stderr { background: #ffebee; }
        </style>
    </head>
    <body>
        <h1>Debug Run Wrapper - <?= htmlspecialchars($result['ts']) ?></h1>
        
        <div class="section">
            <h2>Status: <span class="<?= $result['ok'] ? 'ok' : 'error' ?>"><?= $result['ok'] ? 'OK' : 'ERROR' ?></span></h2>
        </div>
        
        <div class="section">
            <h2>Command & Environment</h2>
            <pre><?= htmlspecialchars(json_encode([
                'command' => $result['command'],
                'cwd' => $result['cwd'],
                'system' => $result['system'] ?? [],
                'env_masked' => $result['env_masked'] ?? [],
                'env_keys' => $result['env_keys'] ?? [],
                'env_count' => $result['env_count'] ?? 0
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>Process Execution</h2>
            <pre><?= htmlspecialchars(json_encode($result['proc'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <?php if (!empty($result['proc']['stdout'])): ?>
        <div class="section stdout">
            <h2>STDOUT</h2>
            <pre><?= htmlspecialchars($result['proc']['stdout']) ?></pre>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($result['proc']['stderr'])): ?>
        <div class="section stderr">
            <h2>STDERR</h2>
            <pre><?= htmlspecialchars($result['proc']['stderr']) ?></pre>
        </div>
        <?php endif; ?>
        
        <div class="section">
            <h2>Parsed Counters</h2>
            <pre><?= htmlspecialchars(json_encode($result['parsed'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <div class="section">
            <h2>DB Check</h2>
            <pre><?= htmlspecialchars(json_encode($result['db_check'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        
        <?php if (!empty($result['errors'])): ?>
        <div class="section">
            <h2>Errors</h2>
            <pre><?= htmlspecialchars(json_encode($result['errors'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
        </div>
        <?php endif; ?>
    </body>
    </html>
    <?php
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

