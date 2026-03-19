<?php
declare(strict_types=1);

/**
 * Gestion des paramètres applicatifs (table parametres_app)
 * Priorité : DB > variables d'environnement
 */

/**
 * Retourne true si l'envoi automatique des reçus et factures est activé
 * Contrôle : reçu paiement (enregistrement, validation) + facture (validation paiement)
 */
function getAutoSendEmailsEnabled(PDO $pdo): bool
{
    try {
        $stmt = $pdo->prepare("SELECT valeur FROM parametres_app WHERE cle = 'auto_send_emails' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row !== false && isset($row['valeur'])) {
            return filter_var($row['valeur'], FILTER_VALIDATE_BOOLEAN);
        }
    } catch (PDOException $e) {
        // Table peut ne pas exister
    }
    $config = require __DIR__ . '/../config/app.php';
    $receipts = filter_var($config['auto_send_receipts'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $invoices = filter_var($_ENV['AUTO_SEND_INVOICES'] ?? $config['auto_send_invoices'] ?? false, FILTER_VALIDATE_BOOLEAN);
    return $receipts || $invoices;
}

/**
 * Active ou désactive l'envoi automatique des emails
 */
function setAutoSendEmailsEnabled(PDO $pdo, bool $enabled): void
{
    $stmt = $pdo->prepare("
        INSERT INTO parametres_app (cle, valeur, updated_at) VALUES ('auto_send_emails', ?, NOW())
        ON DUPLICATE KEY UPDATE valeur = VALUES(valeur), updated_at = NOW()
    ");
    $stmt->execute([$enabled ? '1' : '0']);
}
