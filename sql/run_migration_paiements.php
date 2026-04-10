<?php
/**
 * Script de migration pour créer la table paiements
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

// Note: Les opérations DDL (CREATE TABLE) dans MySQL auto-commitent automatiquement
// donc on n'utilise pas de transaction pour ce script

$errorOccurred = false;
$errorMessage = '';

try {
    // Vérifier si la table existe déjà
    $check = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'paiements'
    ");
    $check->execute();
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ((int)$result['cnt'] === 0) {
        // Créer la table paiements
        $pdo->exec("
            CREATE TABLE `paiements` (
              `id` int NOT NULL AUTO_INCREMENT,
              `id_facture` int DEFAULT NULL COMMENT 'ID de la facture liée (peut être NULL pour paiement sans facture)',
              `id_client` int NOT NULL COMMENT 'ID du client',
              `montant` decimal(10,2) NOT NULL,
              `date_paiement` date NOT NULL,
              `mode_paiement` enum('virement','cb','cheque','especes','autre') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'virement',
              `reference` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL COMMENT 'Référence du paiement (ex: VIR-2025-001)',
              `commentaire` text COLLATE utf8mb4_general_ci,
              `statut` enum('en_cours','recu','refuse','annule') COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_cours',
              `recu_genere` tinyint(1) NOT NULL DEFAULT '0',
              `recu_path` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
              `email_envoye` tinyint(1) NOT NULL DEFAULT '0',
              `date_envoi_email` datetime DEFAULT NULL,
              `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              `created_by` int DEFAULT NULL COMMENT 'ID de l''utilisateur qui a créé le paiement',
              PRIMARY KEY (`id`),
              KEY `idx_paiements_facture` (`id_facture`),
              KEY `idx_paiements_client` (`id_client`),
              KEY `idx_paiements_date` (`date_paiement`),
              KEY `idx_paiements_statut` (`statut`),
              KEY `idx_paiements_created_by` (`created_by`),
              CONSTRAINT `fk_paiements_facture` FOREIGN KEY (`id_facture`) REFERENCES `factures` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
              CONSTRAINT `fk_paiements_client` FOREIGN KEY (`id_client`) REFERENCES `clients` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
              CONSTRAINT `fk_paiements_created_by` FOREIGN KEY (`created_by`) REFERENCES `utilisateurs` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        
        $successMessage = "La table 'paiements' a été créée avec succès.";
    } else {
        $successMessage = "La table 'paiements' existe déjà.";
    }
    
} catch (PDOException $e) {
    $errorOccurred = true;
    $errorMessage = "Erreur SQL lors de la création de la table 'paiements': " . $e->getMessage();
    error_log($errorMessage);
} catch (Exception $e) {
    $errorOccurred = true;
    $errorMessage = "Erreur inattendue: " . $e->getMessage();
    error_log($errorMessage);
}

// Affichage HTML si exécuté via navigateur
if (!empty($_SERVER['HTTP_HOST'])) {
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Migration - Table paiements</title>
        <style>
            body { font-family: Arial, sans-serif; padding: 20px; max-width: 800px; margin: 0 auto; }
            .success { color: green; padding: 10px; background: #d4edda; border: 1px solid #c3e6cb; border-radius: 4px; }
            .error { color: red; padding: 10px; background: #f8d7da; border: 1px solid #f5c6cb; border-radius: 4px; }
        </style>
    </head>
    <body>
        <h1>Migration - Table paiements</h1>
        <?php if ($errorOccurred): ?>
            <div class="error"><?= htmlspecialchars($errorMessage) ?></div>
        <?php else: ?>
            <div class="success"><?= htmlspecialchars($successMessage) ?></div>
        <?php endif; ?>
    </body>
    </html>
    <?php
} else {
    // Affichage console si exécuté via ligne de commande
    if ($errorOccurred) {
        echo $errorMessage . "\n";
        exit(1);
    } else {
        echo $successMessage . "\n";
        exit(0);
    }
}

