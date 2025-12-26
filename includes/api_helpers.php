<?php
// includes/api_helpers.php
// Fonctions helper communes pour les API

// Charger le logger si disponible
if (file_exists(__DIR__ . '/Logger.php')) {
    require_once __DIR__ . '/Logger.php';
}

// Charger DatabaseConnection depuis son fichier isolé
require_once __DIR__ . '/db_connection.php';

// Charger helpers.php pour avoir accès à getPdo() (si pas déjà chargé)
if (!function_exists('getPdo')) {
    require_once __DIR__ . '/helpers.php';
}

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
 * Helper pour renvoyer une réponse d'erreur JSON standardisée
 * 
 * @param string $message Message d'erreur
 * @param int $code Code HTTP (défaut: 500)
 * @param array $extra Données supplémentaires à inclure dans la réponse
 * @return void (termine l'exécution avec jsonResponse)
 */
function apiFail(string $message, int $code = 500, array $extra = []): void {
    $response = [
        'ok' => false,
        'error' => $message
    ];
    
    if (!empty($extra)) {
        $response = array_merge($response, $extra);
    }
    
    jsonResponse($response, $code);
}

/**
 * Récupère PDO via getPdo() et renvoie une erreur JSON en cas d'échec
 * Helper pour les endpoints API qui utilisent getPdo()
 * 
 * @return PDO Instance PDO (jamais null, car exit en cas d'erreur)
 */
function getPdoOrFail(): PDO {
    try {
        return getPdo();
    } catch (RuntimeException $e) {
        error_log('getPdoOrFail: Erreur de connexion PDO - ' . $e->getMessage());
        apiFail('Erreur de connexion à la base de données', 500);
        exit; // Redondant mais explicite
    }
}

/**
 * Vérifie l'authentification pour les API
 * Log minimal pour debug prod (une fois par endpoint)
 */
function requireApiAuth(): void {
    if (empty($_SESSION['user_id'])) {
        // Logging minimal pour debug (une fois par endpoint, pas de spam)
        static $logged = false;
        if (!$logged) {
            $endpoint = $_SERVER['REQUEST_URI'] ?? 'unknown';
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
            $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? 'unknown';
            $sessionId = session_id() ?: 'no-session';
            error_log(sprintf(
                '[API Auth] Session manquante - Endpoint: %s | Session ID: %s | Origin: %s | UA: %s',
                $endpoint,
                $sessionId,
                $origin,
                substr($userAgent, 0, 100)
            ));
            $logged = true;
        }
        jsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
    }
}

/**
 * Alias pour requireApiAuth() - helper standardisé pour sécuriser les endpoints
 * @see requireApiAuth()
 */
function require_login(): void {
    requireApiAuth();
}


/**
 * Vérifie le token CSRF pour les requêtes POST/PUT/DELETE
 * Middleware CSRF pour toutes les API modifiantes
 */
function requireCsrfToken(?string $token = null): void {
    $token = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    
    if (empty($token) || empty($sessionToken) || !hash_equals($sessionToken, $token)) {
        jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
    }
}

/**
 * Vérifie le CSRF pour les API (alias pour cohérence)
 * 
 * @param string|null $token Token CSRF (optionnel, récupéré automatiquement)
 * @return void
 */
function requireCsrfForApi(?string $token = null): void {
    requireCsrfToken($token);
}

/**
 * Vérifie que la connexion PDO existe
 * @deprecated Utiliser getPdo() à la place (depuis includes/helpers.php)
 * Conservé temporairement pour compatibilité pendant la migration
 */
/**
 * @deprecated Utiliser getPdoOrFail() à la place
 * Fonction conservée pour compatibilité mais redirige vers getPdoOrFail()
 */
function requirePdoConnection(?PDO $pdo = null): PDO {
    // Si un PDO est passé en paramètre, le retourner directement (cas rare)
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    
    // Sinon, utiliser getPdoOrFail() qui gère l'erreur et renvoie une réponse JSON
    return getPdoOrFail();
}

/**
 * Initialise l'environnement API (session, DB, headers)
 */
function initApi(): void {
    // Activer le rate limiting en premier (60 requêtes par minute par défaut)
    if (!function_exists('requireRateLimit')) {
        require_once __DIR__ . '/rate_limiter.php';
    }
    requireRateLimit(60, 60); // 60 requêtes par minute
    
    ob_start();
    
    // Configuration d'erreurs sécurisée (production par défaut pour les API)
    if (!function_exists('configureErrorReporting')) {
        require_once __DIR__ . '/helpers.php';
    }
    configureErrorReporting(false); // API en production (pas d'affichage d'erreurs)
    
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
        // Utiliser DatabaseConnection directement (autonome, plus besoin de db.php)
        require_once __DIR__ . '/db_connection.php';
        $pdo = DatabaseConnection::getInstance();
        
        // Tester la connexion avec prepare() pour cohérence
        $stmt = $pdo->prepare('SELECT 1');
        $stmt->execute();
        error_log('initApi: Connexion PDO initialisée via DatabaseConnection');
        
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

