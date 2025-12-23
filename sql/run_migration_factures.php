<?php
/**
 * Script de migration pour créer les tables factures et facture_lignes
 * À exécuter une seule fois via navigateur ou ligne de commande
 */

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

$pdo = getPdo();

header('Content-Type: text/html; charset=utf-8');
echo "<!DOCTYPE html><html><head><meta charset='UTF-8'><title>Migration Factures</title></head><body>";
echo "<h1>Migration : Création des tables factures et facture_lignes</h1>";
echo "<pre>";

try {
    $pdo->beginTransaction();
    
    // 1. Vérifier et créer la table factures
    echo "1. Vérification de la table factures...\n";
    
    $check = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'factures'
    ");
    $check->execute();
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$result['cnt'] === 0) {
        echo "   - Création de la table factures...\n";
        
        $pdo->exec("
            CREATE TABLE `factures` (
              `id` int NOT NULL AUTO_INCREMENT,
              `id_client` int NOT NULL,
              `numero` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
              `date_facture` date NOT NULL,
              `date_debut_periode` date DEFAULT NULL COMMENT 'Date de début de période de consommation (20 du mois)',
              `date_fin_periode` date DEFAULT NULL COMMENT 'Date de fin de période de consommation (20 du mois suivant)',
              `type` enum('Consommation','Achat','Service') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Consommation',
              `montant_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
              `tva` decimal(10,2) NOT NULL DEFAULT '0.00',
              `montant_ttc` decimal(10,2) NOT NULL DEFAULT '0.00',
              `statut` enum('brouillon','envoyee','payee','en_retard','annulee') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'brouillon',
              `pdf_genere` tinyint(1) NOT NULL DEFAULT '0',
              `pdf_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `email_envoye` tinyint(1) NOT NULL DEFAULT '0',
              `date_envoi_email` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `created_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur qui a créé la facture',
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_factures_numero` (`numero`),
              KEY `idx_factures_client` (`id_client`),
              KEY `idx_factures_date` (`date_facture`),
              KEY `idx_factures_statut` (`statut`),
              KEY `idx_factures_created_by` (`created_by`),
              CONSTRAINT `fk_factures_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
              CONSTRAINT `fk_factures_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        echo "   ✓ Table factures créée avec succès\n";
    } else {
        echo "   - La table factures existe déjà\n";
    }
    
    // 2. Vérifier et créer la table facture_lignes
    echo "\n2. Vérification de la table facture_lignes...\n";
    
    $check = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'facture_lignes'
    ");
    $check->execute();
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$result['cnt'] === 0) {
        echo "   - Création de la table facture_lignes...\n";
        
        $pdo->exec("
            CREATE TABLE `facture_lignes` (
              `id` int NOT NULL AUTO_INCREMENT,
              `id_facture` int NOT NULL,
              `description` varchar(255) COLLATE utf8mb4_general_ci NOT NULL,
              `type` enum('N&B','Couleur','Service','Produit') COLLATE utf8mb4_general_ci NOT NULL,
              `quantite` decimal(10,2) NOT NULL DEFAULT '1.00',
              `prix_unitaire_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
              `total_ht` decimal(10,2) NOT NULL DEFAULT '0.00',
              `ordre` int NOT NULL DEFAULT '0',
              PRIMARY KEY (`id`),
              KEY `idx_facture_lignes_facture` (`id_facture`),
              CONSTRAINT `fk_facture_lignes_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        echo "   ✓ Table facture_lignes créée avec succès\n";
    } else {
        echo "   - La table facture_lignes existe déjà\n";
    }
    
    $pdo->commit();
    
    echo "\n✅ Migration terminée avec succès !\n";
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERREUR SQL : " . $e->getMessage() . "\n";
    echo "Code d'erreur : " . $e->getCode() . "\n";
    if (isset($e->errorInfo)) {
        echo "Error Info : " . print_r($e->errorInfo, true) . "\n";
    }
    http_response_code(500);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\n❌ ERREUR : " . $e->getMessage() . "\n";
    http_response_code(500);
}

echo "</pre>";
echo "<p><a href='/public/paiements.php'>Retour à la page paiements</a></p>";
echo "</body></html>";

