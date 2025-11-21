<?php
/**
 * Script de migration pour ajouter la table client_stock et les colonnes produit dans livraisons
 * À exécuter une seule fois via navigateur ou ligne de commande
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration client_stock</title></head><body>";
echo "<h1>Migration : Ajout table client_stock et colonnes produit</h1>";
echo "<pre>";

try {
    $pdo->beginTransaction();
    
    // 1. Vérifier et ajouter les colonnes à livraisons
    echo "1. Vérification des colonnes dans la table livraisons...\n";
    
    $columns = ['product_type', 'product_id', 'product_qty'];
    $added = [];
    
    foreach ($columns as $col) {
        $check = $pdo->query("
            SELECT COUNT(*) as cnt
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'livraisons'
              AND COLUMN_NAME = '{$col}'
        ")->fetch(PDO::FETCH_ASSOC);
        
        if ((int)$check['cnt'] === 0) {
            echo "   - Ajout de la colonne {$col}...\n";
            
            if ($col === 'product_type') {
                $pdo->exec("
                    ALTER TABLE `livraisons` 
                    ADD COLUMN `product_type` enum('papier','toner','lcd','pc','autre') 
                    CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL AFTER `commentaire`
                ");
            } elseif ($col === 'product_id') {
                $pdo->exec("
                    ALTER TABLE `livraisons` 
                    ADD COLUMN `product_id` int DEFAULT NULL AFTER `product_type`
                ");
            } elseif ($col === 'product_qty') {
                $pdo->exec("
                    ALTER TABLE `livraisons` 
                    ADD COLUMN `product_qty` int DEFAULT NULL AFTER `product_id`
                ");
            }
            
            $added[] = $col;
            echo "   ✓ Colonne {$col} ajoutée avec succès\n";
        } else {
            echo "   - La colonne {$col} existe déjà\n";
        }
    }
    
    // 2. Créer la table client_stock
    echo "\n2. Création de la table client_stock...\n";
    
    $pdo->exec("DROP TABLE IF EXISTS `client_stock`");
    
    $pdo->exec("
        CREATE TABLE `client_stock` (
          `id` int NOT NULL AUTO_INCREMENT,
          `id_client` int NOT NULL,
          `product_type` enum('papier','toner','lcd','pc') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
          `product_id` int NOT NULL,
          `qty_stock` int NOT NULL DEFAULT 0,
          `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          UNIQUE KEY `uq_client_stock` (`id_client`,`product_type`,`product_id`),
          KEY `idx_client_stock_client` (`id_client`),
          KEY `idx_client_stock_product` (`product_type`,`product_id`),
          CONSTRAINT `fk_client_stock_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
    echo "   ✓ Table client_stock créée avec succès\n";
    
    $pdo->commit();
    
    echo "\n✅ Migration terminée avec succès !\n";
    if (!empty($added)) {
        echo "\nColonnes ajoutées : " . implode(', ', $added) . "\n";
    }
    
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
echo "<p><a href='/public/dashboard.php'>Retour au dashboard</a></p>";
echo "</body></html>";

