<?php
declare(strict_types=1);

/**
 * includes/db_connection.php
 * Classe DatabaseConnection - Gestion centralisée de la connexion PDO
 * 
 * Cette classe est isolée dans un fichier séparé pour éviter les dépendances circulaires
 * entre includes/helpers.php et includes/api_helpers.php
 */

/**
 * Classe simple pour gérer la connexion PDO de manière centralisée
 * Pattern Singleton pour garantir une seule instance PDO
 * Autonome : crée directement l'instance PDO sans dépendre de $GLOBALS
 */
class DatabaseConnection {
    private static ?PDO $instance = null;
    
    /**
     * Récupère l'instance PDO unique (Singleton)
     * Source de vérité pour toute la gestion PDO
     * 
     * @return PDO Instance PDO unique
     * @throws RuntimeException Si la connexion PDO n'est pas disponible
     */
    public static function getInstance(): PDO {
        // Si déjà initialisé, retourner l'instance
        if (self::$instance !== null && self::$instance instanceof PDO) {
            return self::$instance;
        }
        
        // Créer une nouvelle instance PDO avec la même configuration que db.php
        try {
            // Priorité 1: Variables d'environnement (Railway, Docker, etc.)
            $host = getenv('MYSQLHOST');
            $port = getenv('MYSQLPORT');
            $db   = getenv('MYSQLDATABASE');
            $user = getenv('MYSQLUSER');
            $pass = getenv('MYSQLPASSWORD');
            
            // Priorité 2: Fallback pour XAMPP/local (fichier de config optionnel)
            if (empty($host) || empty($db) || empty($user)) {
                $configFile = __DIR__ . '/db_config.local.php';
                if (file_exists($configFile)) {
                    require_once $configFile;
                    $host = $host ?? $DB_HOST ?? 'localhost';
                    $port = $port ?? $DB_PORT ?? '3306';
                    $db   = $db ?? $DB_NAME ?? '';
                    $user = $user ?? $DB_USER ?? 'root';
                    $pass = $pass ?? $DB_PASS ?? '';
                } else {
                    // Fallback par défaut XAMPP
                    $host = 'localhost';
                    $port = '3306';
                    $db   = 'cccomputer';
                    $user = 'root';
                    $pass = '';
                }
            }
            
            $charset = 'utf8mb4';
            $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            
            self::$instance = new PDO($dsn, $user, $pass, $options);
            
            // Log de succès pour le débogage (sans informations sensibles)
            $safeDsn = preg_replace('/:[^@]+@/', ':****@', $dsn);
            error_log("DatabaseConnection::getInstance() - PDO créé: DSN=$safeDsn");
            
            return self::$instance;
            
        } catch (PDOException $e) {
            // Ne jamais logger les credentials en clair
            $safeDsn = isset($dsn) ? preg_replace('/:[^@]+@/', ':****@', $dsn) : 'N/A';
            error_log("DatabaseConnection::getInstance() - Erreur: " . $e->getMessage() . " | DSN: $safeDsn");
            throw new RuntimeException('Impossible de créer la connexion PDO: ' . $e->getMessage(), 0, $e);
        }
    }
}

