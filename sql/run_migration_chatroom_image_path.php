<?php
/**
 * Script de migration pour ajouter la colonne image_path à chatroom_messages
 * À exécuter une seule fois via navigateur ou ligne de commande
 */

require_once __DIR__ . '/../includes/db.php';

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration chatroom image_path</title></head><body>";
echo "<h1>Migration : Ajout colonne image_path à chatroom_messages</h1>";
echo "<pre>";

try {
    $pdo->beginTransaction();
    
    // 1. Vérifier si la table existe
    echo "1. Vérification de l'existence de la table chatroom_messages...\n";
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chatroom_messages'
    ");
    $checkTable->execute();
    $tableExists = (int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    if (!$tableExists) {
        throw new Exception("La table chatroom_messages n'existe pas. Veuillez d'abord créer la table.");
    }
    echo "   ✓ La table chatroom_messages existe.\n\n";
    
    // 2. Vérifier si la colonne existe déjà
    echo "2. Vérification de l'existence de la colonne image_path...\n";
    $checkColumn = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chatroom_messages'
          AND COLUMN_NAME = 'image_path'
    ");
    $checkColumn->execute();
    $columnExists = (int)$checkColumn->fetch(PDO::FETCH_ASSOC)['cnt'] > 0;
    
    if ($columnExists) {
        echo "   ✓ La colonne image_path existe déjà. Aucune modification nécessaire.\n";
        $pdo->rollBack();
        echo "\n✅ Migration terminée : La colonne existe déjà.\n";
        exit(0);
    }
    echo "   → La colonne n'existe pas, elle va être créée.\n\n";
    
    // 3. Ajouter la colonne
    echo "3. Ajout de la colonne image_path...\n";
    $pdo->exec("
        ALTER TABLE `chatroom_messages` 
        ADD COLUMN `image_path` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL 
        COMMENT 'Chemin relatif vers l''image uploadée (ex: /uploads/chatroom/filename.jpg)' 
        AFTER `mentions`
    ");
    echo "   ✓ Colonne image_path ajoutée avec succès.\n\n";
    
    // 4. Vérifier que la colonne a bien été créée
    echo "4. Vérification finale...\n";
    $verify = $pdo->prepare("
        SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLUMN_COMMENT
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'chatroom_messages'
          AND COLUMN_NAME = 'image_path'
    ");
    $verify->execute();
    $columnInfo = $verify->fetch(PDO::FETCH_ASSOC);
    
    if ($columnInfo) {
        echo "   ✓ Colonne vérifiée :\n";
        echo "     - Nom : {$columnInfo['COLUMN_NAME']}\n";
        echo "     - Type : {$columnInfo['DATA_TYPE']}\n";
        echo "     - Nullable : {$columnInfo['IS_NULLABLE']}\n";
        echo "     - Défaut : " . ($columnInfo['COLUMN_DEFAULT'] ?? 'NULL') . "\n";
        echo "     - Commentaire : {$columnInfo['COLUMN_COMMENT']}\n\n";
    }
    
    $pdo->commit();
    echo "✅ Migration terminée avec succès !\n";
    echo "\nLa colonne image_path a été ajoutée à la table chatroom_messages.\n";
    echo "Vous pouvez maintenant envoyer des images dans les messages.\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ ERREUR SQL : " . $e->getMessage() . "\n";
    echo "Code : " . $e->getCode() . "\n";
    if (isset($e->errorInfo)) {
        echo "SQL State : " . ($e->errorInfo[0] ?? 'N/A') . "\n";
        echo "Driver Code : " . ($e->errorInfo[1] ?? 'N/A') . "\n";
        echo "Driver Message : " . ($e->errorInfo[2] ?? 'N/A') . "\n";
    }
    http_response_code(500);
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    http_response_code(500);
}

echo "</pre></body></html>";



