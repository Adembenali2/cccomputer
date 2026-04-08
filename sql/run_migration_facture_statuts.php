<?php
/**
 * Migration: Ajouter en_attente et en_cours au statut des factures
 * À exécuter une seule fois
 */

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

$pdo = getPdo();

try {
    $pdo->exec("
        ALTER TABLE `factures` 
        MODIFY COLUMN `statut` enum('brouillon','en_attente','envoyee','en_cours','en_retard','payee','annulee') 
        COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'en_attente'
    ");
    $pdo->exec("UPDATE factures SET statut = 'en_attente' WHERE statut = 'brouillon'");
    echo "Migration terminée: statuts en_attente et en_cours ajoutés aux factures.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "Migration déjà appliquée ou colonne déjà existante.\n";
    } else {
        throw $e;
    }
}
