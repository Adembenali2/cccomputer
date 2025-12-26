<?php
/**
 * Script de migration : Création de la table email_logs
 * 
 * Usage: php sql/run_migration_email_logs.php
 * 
 * Ce script crée la table email_logs pour journaliser tous les envois d'emails
 * (factures, paiements, etc.) avec traçabilité complète.
 */

require_once __DIR__ . '/../includes/db_connection.php';

try {
    $pdo = getPdo();
    
    echo "Début de la migration : création de la table email_logs...\n";
    
    // Lire le fichier SQL
    $sqlFile = __DIR__ . '/migrations/create_email_logs_table.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("Fichier SQL introuvable: {$sqlFile}");
    }
    
    $sql = file_get_contents($sqlFile);
    
    // Séparer les requêtes (séparées par ;)
    $queries = array_filter(
        array_map('trim', explode(';', $sql)),
        function($q) { return !empty($q) && !preg_match('/^--/', $q); }
    );
    
    $pdo->beginTransaction();
    
    try {
        foreach ($queries as $query) {
            if (empty(trim($query))) continue;
            
            echo "Exécution: " . substr($query, 0, 80) . "...\n";
            $pdo->exec($query);
        }
        
        $pdo->commit();
        echo "✅ Migration réussie : table email_logs créée avec succès.\n";
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (PDOException $e) {
    echo "❌ Erreur SQL: " . $e->getMessage() . "\n";
    exit(1);
} catch (Throwable $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

