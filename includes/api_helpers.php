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
 */
function requireApiAuth(): void {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
    }
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
function requirePdoConnection(?PDO $pdo = null): PDO {
    // Priorité 1: Vérifier le paramètre passé
    if ($pdo instanceof PDO) {
        // Compatibilité temporaire
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }
    
    // Priorité 2: Utiliser DatabaseConnection (source de vérité)
    try {
        $pdo = DatabaseConnection::getInstance();
        // Compatibilité temporaire
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    } catch (RuntimeException $e) {
        // Fallback pour compatibilité temporaire
    }
    
    // Fallback temporaire : vérifier GLOBALS directement (sera retiré après migration)
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    
    // Si toujours pas de PDO, essayer de le charger depuis db.php
    if (!defined('DB_LOADED')) {
        require_once __DIR__ . '/db.php';
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            return $GLOBALS['pdo'];
        }
    }
    
    error_log('requirePdoConnection error: Impossible de récupérer PDO');
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur de connexion à la base de données'
    ], 500);
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
        // Vérifier si db.php a déjà été chargé
        $dbFile = __DIR__ . '/db.php';
        if (!file_exists($dbFile)) {
            throw new RuntimeException("Le fichier db.php n'existe pas à: $dbFile");
        }
        
        // Toujours utiliser require_once pour éviter les redéclarations
        require_once $dbFile;
        
        // Vérifier que db.php a bien été chargé
        if (!defined('DB_LOADED')) {
            throw new RuntimeException('db.php a été inclus mais DB_LOADED n\'est pas défini. Vérifiez que db.php définit cette constante.');
        }
        
        // Vérifier si une erreur de connexion a été stockée
        if (isset($GLOBALS['db_connection_error'])) {
            $error = $GLOBALS['db_connection_error'];
            throw new RuntimeException(
                'Erreur de connexion à la base de données: ' . $error->getMessage(),
                0,
                $error
            );
        }
        
        // Vérifier que $pdo a été créé - d'abord dans GLOBALS (plus fiable)
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            $pdo = $GLOBALS['pdo'];
            error_log('initApi: PDO trouvé dans GLOBALS');
        } else {
            // Essayer la variable globale classique
            global $pdo;
            if (isset($pdo) && $pdo instanceof PDO) {
                // S'assurer qu'il est aussi dans GLOBALS
                $GLOBALS['pdo'] = $pdo;
                error_log('initApi: PDO trouvé dans variable globale, stocké dans GLOBALS');
            } else {
                // Diagnostic détaillé
                $debugInfo = [
                    'GLOBALS[pdo] existe' => isset($GLOBALS['pdo']),
                    'GLOBALS[pdo] type' => isset($GLOBALS['pdo']) ? gettype($GLOBALS['pdo']) : 'N/A',
                    'GLOBALS[pdo] instanceof PDO' => isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO,
                    'Variable globale $pdo existe' => isset($pdo),
                    'Variable globale $pdo type' => isset($pdo) ? gettype($pdo) : 'N/A',
                    'DB_LOADED défini' => defined('DB_LOADED'),
                    'db_connection_error existe' => isset($GLOBALS['db_connection_error'])
                ];
                
                error_log('initApi: PDO non trouvé. Debug: ' . json_encode($debugInfo));
                
                throw new RuntimeException(
                    'La connexion PDO n\'a pas été initialisée par db.php. ' .
                    'Vérifiez la configuration de la base de données et les logs d\'erreur. ' .
                    'Debug: ' . json_encode($debugInfo)
                );
            }
        }
        
        // Tester la connexion
        try {
            $pdo->query('SELECT 1');
            error_log('initApi: Test de connexion PDO réussi');
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

