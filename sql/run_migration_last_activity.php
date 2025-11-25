<?php
/**
 * Script pour exécuter la migration last_activity
 * Usage: php sql/run_migration_last_activity.php
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Migration: Ajout du champ last_activity ===\n\n";

try {
    // Vérifier si le champ existe déjà
    $check = $pdo->query("SHOW COLUMNS FROM utilisateurs LIKE 'last_activity'");
    if ($check->rowCount() > 0) {
        echo "✓ Le champ 'last_activity' existe déjà.\n";
        exit(0);
    }
    
    echo "1. Ajout du champ last_activity...\n";
    $pdo->exec("ALTER TABLE `utilisateurs` 
                ADD COLUMN `last_activity` datetime NULL DEFAULT NULL AFTER `date_modification`");
    echo "   ✓ Champ ajouté avec succès.\n\n";
    
    echo "2. Création de l'index...\n";
    $pdo->exec("CREATE INDEX `idx_last_activity` ON `utilisateurs` (`last_activity`)");
    echo "   ✓ Index créé avec succès.\n\n";
    
    echo "3. Initialisation avec date_modification...\n";
    $stmt = $pdo->prepare("UPDATE `utilisateurs` 
                          SET `last_activity` = `date_modification` 
                          WHERE `last_activity` IS NULL");
    $stmt->execute();
    $affected = $stmt->rowCount();
    echo "   ✓ {$affected} utilisateur(s) initialisé(s).\n\n";
    
    echo "=== Migration terminée avec succès ! ===\n";
    
} catch (PDOException $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}



