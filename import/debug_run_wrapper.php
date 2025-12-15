<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
ob_start();

/**
 * import/debug_run_wrapper.php
 * 
 * Debug end-to-end : exécute exactement la même commande que run_import_if_due.php
 * et capture tout pour comprendre pourquoi le dashboard affiche 0
 * 
 * IMPORTANT: Ce script ne doit JAMAIS crasher et doit toujours renvoyer un JSON propre
 */

// ====== INITIALISATION ======
$result = [
    'ok' => true,
    'ts' => date('Y-m-d H:i:s'),
    'command' => '',
    'cwd' => '',
    'system' => [],
    'env_masked' => [],
    'env_keys' => [],
    'env_count' => 0,
    'proc' => [],
    'parsed' => [],
    'db_check' => [],
    'warnings' => [],
    'errors' => []
];

// ====== ERROR HANDLER ======
set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$result) {
    $result['warnings'][] = [
        'type' => $errno,
        'message' => $errstr,
        'file' => $errfile,
        'line' => $errline,
        'ts' => date('Y-m-d H:i:s')
    ];
    return true; // Ne pas interrompre l'exécution
});

// ====== SHUTDOWN FUNCTION ======
register_shutdown_function(function() use (&$result) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        ob_clean();
        $result['ok'] = false;
        $result['errors'][] = [
            'fatal_error' => true,
            'message' => $error['message'],
            'file' => $error['file'],
            'line' => $error['line'],
            'ts' => date('Y-m-d H:i:s')
        ];
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

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
        // S'assurer que la clé est un string
        $key = safe_scalar($k);
        
        $isSensitive = false;
        foreach ($sensitive as $s) {
            if (stripos($key, $s) !== false) {
                $isSensitive = true;
                break;
            }
        }
        // S'assurer que la valeur est un string avant maskPassword
        $value = safe_scalar($v);
        $masked[$key] = $isSensitive ? maskPassword($value) : $value;
    }
    
    return $masked;
}

function truncate(string $s, int $max = 10000): string {
    if (strlen($s) <= $max) return $s;
    return substr($s, 0, $max) . "\n... (tronqué à $max caractères)";
}

function getTail(string $s, int $lines = 20): array {
    $allLines = explode("\n", $s);
    return array_slice($allLines, -$lines);
}

function safe_scalar($v): string {
    if (is_array($v)) {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if (is_object($v)) {
        return '[object ' . get_class($v) . ']';
    }
    if (is_resource($v)) {
        return '[resource]';
    }
    return (string)$v;
}

function addError(array &$result, string $error, array $context = []): void {
    $result['ok'] = false;
    $result['errors'][] = [
        'error' => $error,
        'context' => $context,
        'ts' => date('Y-m-d H:i:s')
    ];
}

// ====== SÉCURITÉ ======
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost']) || 
           (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
$debugKey = getenv('DEBUG_KEY') ?: '';
$requestKey = $_GET['key'] ?? '';

if (!$isLocal && $debugKey && $requestKey !== $debugKey) {
    ob_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Access denied. DEBUG_KEY required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ====== CONFIGURATION ======
$limit = (int)($_GET['limit'] ?? $_POST['limit'] ?? 10);
if ($limit <= 0) $limit = 10;

// ====== EXECUTION ======
try {
    // Chemins absolus
    $projectRoot = '/var/www/html';
    $scriptPath = $projectRoot . '/API/scripts/upload_compteur.php';
    $dbPath = $projectRoot . '/includes/db.php';
    
    $result['cwd'] = $projectRoot;
    
    // Vérifier que le script existe
    if (!is_file($scriptPath)) {
        $errorMsg = safe_scalar("Script upload_compteur.php introuvable: {$scriptPath}");
        addError($result, $errorMsg);
        $result['command'] = 'N/A';
    } else {
        // Construire la commande
        $php = PHP_BINARY ?: 'php';
        $cmd = escapeshellcmd($php) . ' ' . escapeshellarg($scriptPath);
        $result['command'] = $cmd;
        
            // Informations système
        $result['system'] = [
            'php_binary' => safe_scalar($php),
            'php_binary_exists' => file_exists($php),
            'php_version' => PHP_VERSION,
            'user' => function_exists('get_current_user') ? safe_scalar(get_current_user()) : 'unknown',
            'cwd' => safe_scalar(getcwd() ?: 'unknown'),
            'path' => safe_scalar(getenv('PATH') ?: 'not_set')
        ];
        
        // Environnement - s'assurer que toutes les valeurs sont des strings
        $env = [];
        foreach ($_ENV as $k => $v) {
            $env[$k] = safe_scalar($v);
        }
        foreach ($_SERVER as $k => $v) {
            if (!isset($env[$k])) {
                $env[$k] = safe_scalar($v);
            }
        }
        $env['SFTP_BATCH_LIMIT'] = (string)$limit;
        
        $result['env_masked'] = maskEnv($env);
        $result['env_keys'] = array_keys($env);
        $result['env_count'] = count($env);
        
        // Descripteurs
        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['pipe', 'w'], // stdout
            2 => ['pipe', 'w']  // stderr
        ];
        
        $TIMEOUT_SEC = 60;
        $startTime = microtime(true);
        
        // Exécuter proc_open
        $proc = @proc_open($cmd, $descriptors, $pipes, $projectRoot, $env);
        
        if (!is_resource($proc)) {
            addError($result, 'proc_open() a échoué - impossible de créer le processus');
            $result['proc'] = [
                'exit_code' => -1,
                'duration_ms' => 0,
                'timed_out' => false,
                'stdout' => '',
                'stderr' => 'proc_open() failed',
                'stdout_len' => 0,
                'stderr_len' => 0
            ];
        } else {
            // Fermer stdin immédiatement
            if (isset($pipes[0]) && is_resource($pipes[0])) {
                fclose($pipes[0]);
            }
            
            // Configurer stdout et stderr en non-bloquant
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                stream_set_blocking($pipes[1], false);
            }
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                stream_set_blocking($pipes[2], false);
            }
            
            $out = '';
            $err = '';
            $code = null;
            $timeoutReached = false;
            
            // Boucle de lecture
            while (is_resource($proc)) {
                $status = @proc_get_status($proc);
                
                if ($status === false) {
                    break;
                }
                
                // Vérifier le timeout
                $elapsed = time() - (int)$startTime;
                if ($elapsed > $TIMEOUT_SEC) {
                    $timeoutReached = true;
                    @proc_terminate($proc, SIGTERM);
                    sleep(2);
                    $status = @proc_get_status($proc);
                    if ($status && isset($status['running']) && $status['running']) {
                        @proc_terminate($proc, SIGKILL);
                    }
                    $timeoutMsg = safe_scalar("TIMEOUT: Le processus a dépassé la limite de {$TIMEOUT_SEC} secondes");
                    $err .= "\n" . $timeoutMsg;
                    $code = -1;
                    break;
                }
                
                // Si le processus est terminé
                if (!isset($status['running']) || !$status['running']) {
                    $code = isset($status['exitcode']) ? $status['exitcode'] : -1;
                    break;
                }
                
                // Construire $read avec seulement les pipes valides
                $read = [];
                if (isset($pipes[1]) && is_resource($pipes[1])) {
                    $read[] = $pipes[1];
                }
                if (isset($pipes[2]) && is_resource($pipes[2])) {
                    $read[] = $pipes[2];
                }
                
                // Ne jamais appeler stream_select avec un array vide
                if (empty($read)) {
                    usleep(100000); // 100ms
                    continue;
                }
                
                // Lire les données disponibles
                // PHP 8.x : doit passer des variables par référence, pas null directement
                $write = null;
                $except = null;
                
                $changed = @stream_select($read, $write, $except, 1);
                
                if ($changed === false) {
                    // stream_select a échoué, arrêter la boucle
                    addError($result, 'stream_select failed', [
                        'read_count' => count($read),
                        'pipes_1_resource' => isset($pipes[1]) && is_resource($pipes[1]),
                        'pipes_2_resource' => isset($pipes[2]) && is_resource($pipes[2])
                    ]);
                    break;
                }
                
                if ($changed > 0) {
                    foreach ($read as $stream) {
                        if ($stream === $pipes[1] && is_resource($pipes[1])) {
                            $data = @fread($pipes[1], 8192);
                            if ($data !== false && $data !== '') {
                                $out .= $data;
                            }
                        } elseif ($stream === $pipes[2] && is_resource($pipes[2])) {
                            $data = @fread($pipes[2], 8192);
                            if ($data !== false && $data !== '') {
                                $err .= $data;
                            }
                        }
                    }
                } else {
                    usleep(100000);
                }
            }
            
            // Lire les dernières données restantes
            if (isset($pipes[1]) && is_resource($pipes[1])) {
                $remaining = @stream_get_contents($pipes[1]);
                if ($remaining !== false) {
                    $out .= $remaining;
                }
                @fclose($pipes[1]);
            }
            
            if (isset($pipes[2]) && is_resource($pipes[2])) {
                $remaining = @stream_get_contents($pipes[2]);
                if ($remaining !== false) {
                    $err .= $remaining;
                }
                @fclose($pipes[2]);
            }
            
            // Fermer le processus
            if (is_resource($proc)) {
                $code = @proc_close($proc);
            }
            
            $durationMs = (microtime(true) - $startTime) * 1000;
            
            $result['proc'] = [
                'exit_code' => $code ?? -1,
                'duration_ms' => round($durationMs, 2),
                'timed_out' => $timeoutReached,
                'stdout' => truncate($out, 10000),
                'stderr' => truncate($err, 10000),
                'stdout_len' => strlen($out),
                'stderr_len' => strlen($err)
            ];
            
            // Parser les compteurs
            $parsed = [
                'inserted' => null,
                'updated' => null,
                'skipped' => null,
                'method' => null,
                'parse_error' => null
            ];
            
            $combined = $out . "\n" . $err;
            
            // Méthode 1 : Chercher JSON
            $jsonFound = false;
            if (preg_match_all('/\{[^{}]*"source"\s*:\s*"SFTP"[^{}]*(?:\{[^{}]*\}[^{}]*)*\}/i', $combined, $jsonMatches)) {
                // Prendre le dernier JSON trouvé
                $lastJson = end($jsonMatches[0]);
                $decoded = @json_decode($lastJson, true);
                if (is_array($decoded)) {
                    if (isset($decoded['inserted'])) {
                        $parsed['inserted'] = (int)$decoded['inserted'];
                        $parsed['method'] = 'json_inserted';
                        $jsonFound = true;
                    }
                    if (isset($decoded['updated'])) {
                        $parsed['updated'] = (int)$decoded['updated'];
                        if (!$jsonFound) $parsed['method'] = 'json_updated';
                        $jsonFound = true;
                    }
                    if (isset($decoded['skipped'])) {
                        $parsed['skipped'] = (int)$decoded['skipped'];
                    }
                }
            }
            
            // Méthode 2 : Patterns texte (si JSON n'a pas fonctionné)
            if (!$jsonFound) {
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
            }
            
            // Si toujours rien trouvé
            if ($parsed['inserted'] === null && $parsed['updated'] === null && $parsed['skipped'] === null) {
                $parsed['parse_error'] = 'Aucun compteur trouvé dans stdout/stderr';
                $parsed['method'] = 'fallback';
                $parsed['tail_stdout'] = getTail($out, 20);
                $parsed['tail_stderr'] = getTail($err, 20);
            }
            
            $result['parsed'] = $parsed;
        }
    }
    
    // Vérification DB après exécution
    try {
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
                    $errorMsg = safe_scalar('DB query failed (recent_inserted): ' . $e->getMessage());
                    addError($result, $errorMsg);
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
                            $decoded = @json_decode($import['msg'], true);
                            if (is_array($decoded)) {
                                $import['msg_decoded'] = $decoded;
                            } else {
                                $import['msg_raw'] = substr($import['msg'], 0, 500);
                            }
                        }
                    }
                } catch (Throwable $e) {
                    $lastImports = [];
                    $errorMsg = safe_scalar('DB query failed (last_imports): ' . $e->getMessage());
                    addError($result, $errorMsg);
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
            $errorMsg = safe_scalar("db.php not found: {$dbPath}");
            addError($result, $errorMsg);
            $result['db_check'] = ['error' => 'db.php not found'];
        }
    } catch (Throwable $e) {
        $errorMsg = safe_scalar('DB check failed: ' . $e->getMessage());
        addError($result, $errorMsg);
        $result['db_check'] = ['error' => safe_scalar($e->getMessage())];
    }
    
} catch (Throwable $e) {
    $errorMsg = safe_scalar('Fatal exception: ' . $e->getMessage());
    addError($result, $errorMsg, [
        'file' => safe_scalar($e->getFile()),
        'line' => $e->getLine(),
        'trace' => safe_scalar($e->getTraceAsString())
    ]);
    $result['ok'] = false;
}

// ====== OUTPUT ======
ob_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
