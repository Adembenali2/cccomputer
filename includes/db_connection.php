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
            // Compatibilité temporaire : maintenir GLOBALS tant que la migration n'est pas terminée
            $GLOBALS['pdo'] = self::$instance;
            return self::$instance;
        }
        
        // Si pas encore initialisé, charger depuis db.php qui crée la connexion
        if (!defined('DB_LOADED')) {
            require_once __DIR__ . '/db.php';
        }
        
        // Récupérer depuis GLOBALS (créé par db.php)
        if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
            self::$instance = $GLOBALS['pdo'];
            return self::$instance;
        }
        
        throw new RuntimeException('Impossible de récupérer la connexion PDO');
    }
}

