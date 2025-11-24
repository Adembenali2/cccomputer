<?php
// includes/api_helpers.php
// Fonctions helper communes pour les API

/**
 * Réponse JSON standardisée pour toutes les API
 */
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
    $pdo = $pdo ?? $GLOBALS['pdo'] ?? null;
    
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
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
    
    try {
        require_once __DIR__ . '/session_config.php';
        require_once __DIR__ . '/db.php';
    } catch (Throwable $e) {
        error_log('API init error: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
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

