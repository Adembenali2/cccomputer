<?php
// includes/api_helpers.php
// Fonctions helper communes pour les API

// Charger le logger si disponible
if (file_exists(__DIR__ . '/Logger.php')) {
    require_once __DIR__ . '/Logger.php';
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
 * Classe simple pour gérer la connexion PDO de manière centralisée
 * Améliore la gestion des GLOBALS sans casser le comportement existant
 */
class DatabaseConnection {
    private static ?PDO $instance = null;
    
    /**
     * Récupère l'instance PDO unique (Singleton)
     * Compatible avec le système GLOBALS existant
     */
    public static function getInstance(): PDO {
        // Si déjà initialisé, retourner l'instance
        if (self::$instance !== null && self::$instance instanceof PDO) {
            // Maintenir la compatibilité avec GLOBALS
            $GLOBALS['pdo'] = self::$instance;
            return self::$instance;
        }
        
        // Sinon, récupérer depuis GLOBALS ou charger db.php
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            self::$instance = $GLOBALS['pdo'];
            return self::$instance;
        }
        
        // Dernier recours : charger db.php
        if (!defined('DB_LOADED')) {
            require_once __DIR__ . '/db.php';
        }
        
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            self::$instance = $GLOBALS['pdo'];
            return self::$instance;
        }
        
        throw new RuntimeException('Impossible de récupérer la connexion PDO');
    }
}

/**
 * Vérifie que la connexion PDO existe
 * Utilise DatabaseConnection pour une meilleure gestion interne
 */
function requirePdoConnection(?PDO $pdo = null): PDO {
    // Priorité 1: Vérifier le paramètre passé
    if ($pdo instanceof PDO) {
        // S'assurer qu'il est aussi dans GLOBALS pour compatibilité
        $GLOBALS['pdo'] = $pdo;
        DatabaseConnection::$instance = $pdo;
        return $pdo;
    }
    
    // Priorité 2: Utiliser DatabaseConnection (améliore la gestion interne)
    try {
        $pdo = DatabaseConnection::getInstance();
        // Maintenir la compatibilité avec GLOBALS
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    } catch (RuntimeException $e) {
        // Fallback sur l'ancienne méthode pour compatibilité
    }
    
    // Priorité 3: Vérifier GLOBALS directement (ancienne méthode)
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    
    // Priorité 4: Vérifier la variable globale classique
    global $pdo;
    if (isset($pdo) && $pdo instanceof PDO) {
        // S'assurer qu'il est aussi dans GLOBALS
        $GLOBALS['pdo'] = $pdo;
        return $pdo;
    }
    
    // Si toujours pas de PDO, essayer de le charger depuis db.php
    try {
        // Vérifier si db.php a été chargé
        if (!isset($GLOBALS['pdo'])) {
            // Essayer de charger db.php si pas déjà fait
            if (!defined('DB_LOADED')) {
                require_once __DIR__ . '/db.php';
                // Après le require, vérifier à nouveau GLOBALS
                if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
                    return $GLOBALS['pdo'];
                }
            }
        }
        
        // Dernière vérification
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            throw new RuntimeException(
                'La connexion PDO n\'est pas disponible. ' .
                'db.php a été chargé mais $pdo n\'est pas dans $GLOBALS. ' .
                'Vérifiez la configuration de la base de données.'
            );
        }
        
        return $GLOBALS['pdo'];
        
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
        // Vérifier si db.php a déjà été chargé
        $dbFile = __DIR__ . '/db.php';
        if (!file_exists($dbFile)) {
            throw new RuntimeException("Le fichier db.php n'existe pas à: $dbFile");
        }
        
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

