<?php
/**
 * Script de migration pour ajouter la table client_geocode
 * À exécuter une seule fois via navigateur ou ligne de commande
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration client_geocode</title></head><body>";
echo "<h1>Migration : Ajout table client_geocode</h1>";
echo "<pre>";

try {
    $pdo->beginTransaction();
    
    // Vérifier si la table existe déjà
    echo "1. Vérification de l'existence de la table client_geocode...\n";
    
    $check = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'client_geocode'
    ");
    $check->execute();
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$result['cnt'] > 0) {
        echo "   - La table client_geocode existe déjà\n";
        echo "   - Suppression de l'ancienne table...\n";
        $pdo->exec("DROP TABLE IF EXISTS `client_geocode`");
    }
    
    // Créer la table client_geocode
    echo "\n2. Création de la table client_geocode...\n";
    
    $pdo->exec("
        CREATE TABLE `client_geocode` (
          `id_client` int NOT NULL,
          `address_hash` varchar(64) NOT NULL COMMENT 'Hash MD5 de l''adresse géocodée pour détecter les changements',
          `lat` decimal(10,8) NOT NULL,
          `lng` decimal(11,8) NOT NULL,
          `display_name` varchar(500) DEFAULT NULL COMMENT 'Nom d''affichage retourné par le géocodeur',
          `geocoded_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id_client`),
          KEY `idx_address_hash` (`address_hash`),
          CONSTRAINT `fk_client_geocode_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
    echo "   ✓ Table client_geocode créée avec succès\n";
    
    $pdo->commit();
    
    echo "\n✅ Migration terminée avec succès !\n";
    echo "\nLa table client_geocode permet de stocker les coordonnées géocodées des clients\n";
    echo "pour éviter de géocoder à chaque chargement de la page maps.php.\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    echo "Code d'erreur : " . $e->getCode() . "\n";
    http_response_code(500);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    http_response_code(500);
}

echo "</pre>";
echo "<p><a href='/public/maps.php'>Aller à la page Maps</a> | <a href='/public/dashboard.php'>Retour au dashboard</a></p>";
echo "</body></html>";

