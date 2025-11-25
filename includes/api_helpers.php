<?php
// includes/api_helpers.php
// Fonctions helper communes pour les API

/**
 * Réponse JSON standardisée pour toutes les API
 */
if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($statusCode);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            // Headers de sécurité
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
        }
        echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

/**
 * Vérifie l'authentification pour les API
 */
function requireApiAuth(): void {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
    }
}

/**
 * Vérifie le token CSRF pour les requêtes POST/PUT/DELETE
 */
function requireCsrfToken(?string $token = null): void {
    $token = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
        jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
    }
}

/**
 * Vérifie que la connexion PDO existe
 */
function requirePdoConnection(?PDO $pdo = null): PDO {
    global $pdo;
    
    // Essayer plusieurs méthodes pour obtenir la connexion PDO
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    
    // Si toujours pas de PDO, essayer de le charger depuis db.php
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        try {
            // Vérifier si db.php a été chargé
            if (!isset($GLOBALS['pdo'])) {
                // Essayer de charger db.php si pas déjà fait
                if (!defined('DB_LOADED')) {
                    require_once __DIR__ . '/db.php';
                }
            }
            
            $pdo = $GLOBALS['pdo'] ?? null;
            
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                throw new RuntimeException('La connexion PDO n\'est pas disponible. Vérifiez la configuration de la base de données.');
            }
        } catch (Throwable $e) {
            error_log('requirePdoConnection error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            jsonResponse([
                'ok' => false, 
                'error' => 'Erreur de connexion à la base de données',
                'debug' => [
                    'message' => $e->getMessage(),
                    'type' => get_class($e),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => explode("\n", $e->getTraceAsString())
                ]
            ], 500);
        }
    }
    
    return $pdo;
}

/**
 * Initialise l'environnement API (session, DB, headers)
 */
function initApi(): void {
    ob_start();
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('html_errors', 0);
    ini_set('log_errors', 1);
    
    // Définir les headers JSON en premier
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    
    try {
        require_once __DIR__ . '/session_config.php';
    } catch (Throwable $e) {
        error_log('API init error (session_config): ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        jsonResponse([
            'ok' => false, 
            'error' => 'Erreur d\'initialisation de la session',
            'debug' => [
                'message' => $e->getMessage(),
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ]
        ], 500);
    }
    
    try {
        require_once __DIR__ . '/db.php';
        
        // Vérifier que $pdo a été créé
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            // Vérifier dans GLOBALS aussi
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                $pdo = $GLOBALS['pdo'];
            } else {
                throw new RuntimeException('La connexion PDO n\'a pas été initialisée par db.php. Vérifiez la configuration de la base de données.');
            }
        }
        
        // Stocker dans GLOBALS pour être sûr
        $GLOBALS['pdo'] = $pdo;
        
        // Tester la connexion
        try {
            $pdo->query('SELECT 1');
        } catch (PDOException $e) {
            error_log('API init error (db test): ' . $e->getMessage());
            throw new RuntimeException('La connexion PDO existe mais ne fonctionne pas: ' . $e->getMessage(), 0, $e);
        }
        
    } catch (Throwable $e) {
        $errorInfo = [];
        if ($e instanceof PDOException && isset($e->errorInfo)) {
            $errorInfo = [
                'sql_state' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'driver_message' => $e->errorInfo[2] ?? null
            ];
        }
        
        error_log('API init error (db): ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        jsonResponse([
            'ok' => false, 
            'error' => 'Erreur de connexion à la base de données',
            'debug' => array_merge([
                'message' => $e->getMessage(),
                'type' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => explode("\n", $e->getTraceAsString())
            ], $errorInfo)
        ], 500);
    }
    
    // Générer CSRF token si manquant
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
}

/**
 * Valide un ID numérique (version API qui retourne JSON)
 */
if (!function_exists('validateId')) {
    function validateId($id, string $name = 'ID'): int {
        $id = (int)$id;
        if ($id <= 0) {
            jsonResponse(['ok' => false, 'error' => "{$name} invalide"], 400);
        }
        return $id;
    }
}

/**
 * Valide une chaîne non vide (version API qui retourne JSON)
 */
if (!function_exists('validateString')) {
    function validateString(string $value, string $name, int $minLength = 1, int $maxLength = 1000): string {
        $value = trim($value);
        if (strlen($value) < $minLength) {
            jsonResponse(['ok' => false, 'error' => "{$name} trop court (min {$minLength} caractères)"], 400);
        }
        if (strlen($value) > $maxLength) {
            jsonResponse(['ok' => false, 'error' => "{$name} trop long (max {$maxLength} caractères)"], 400);
        }
        return $value;
    }
}

/**
 * Cache simple basé sur fichiers
 */
function getCache(string $key, int $ttl = 3600): ?array {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
        $data = json_decode(file_get_contents($cacheFile), true);
        if (is_array($data)) {
            return $data;
        }
    }
    return null;
}

function setCache(string $key, array $data): bool {
    $cacheDir = __DIR__ . '/../cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.json';
    return file_put_contents($cacheFile, json_encode($data)) !== false;
}

/**
 * Vérifie si une colonne existe dans une table (avec cache pour éviter les requêtes répétées)
 */
function columnExists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $cacheKey = "{$table}.{$column}";
    
    if (isset($cache[$cacheKey])) {
        return $cache[$cacheKey];
    }
    
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table 
            AND COLUMN_NAME = :column
        ");
        $stmt->execute([':table' => $table, ':column' => $column]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $exists = ($result['cnt'] ?? 0) > 0;
        $cache[$cacheKey] = $exists;
        return $exists;
    } catch (PDOException $e) {
        error_log("columnExists error for {$table}.{$column}: " . $e->getMessage());
        return false;
    }
}

