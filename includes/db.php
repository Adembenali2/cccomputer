<?php
declare(strict_types=1);

/**
 * includes/db.php
 * @deprecated Ce fichier est conservé uniquement pour la constante DB_LOADED (compatibilité).
 * La création PDO est maintenant gérée par DatabaseConnection::getInstance() (includes/db_connection.php).
 * Utiliser getPdo() ou getPdoOrFail() au lieu d'inclure db.php pour obtenir PDO.
 * 
 * Ce fichier peut être supprimé une fois que tous les fichiers qui l'incluent directement
 * auront été migrés vers getPdo() / getPdoOrFail().
 */

// Variable pour indiquer que db.php a été chargé (conservée pour compatibilité)
if (!defined('DB_LOADED')) {
    define('DB_LOADED', true);
}

// NOTE: La création PDO a été retirée - elle est maintenant gérée par DatabaseConnection::getInstance()
// Ce fichier est conservé uniquement pour définir DB_LOADED (compatibilité avec certains anciens fichiers)
// Tous les nouveaux fichiers doivent utiliser getPdo() ou getPdoOrFail() depuis includes/helpers.php ou includes/api_helpers.php
