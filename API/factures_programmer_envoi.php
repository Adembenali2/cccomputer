<?php
declare(strict_types=1);
/**
 * API pour créer une programmation d'envoi de facture(s)
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\InvoiceEmailService;

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data || !is_array($data)) {
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
    }

    $typeEnvoi = $data['type_envoi'] ?? 'une_facture';
    $allowedTypes = ['une_facture', 'plusieurs_factures', 'toutes_selectionnees', 'tous_clients'];
    if (!in_array($typeEnvoi, $allowedTypes)) {
        jsonResponse(['ok' => false, 'error' => 'Type d\'envoi invalide'], 400);
    }

    $factureId = null;
    $facturesJson = null;
    $clientId = isset($data['client_id']) ? (int)$data['client_id'] : null;
    $emailDestination = !empty($data['email_destination']) ? trim($data['email_destination']) : null;
    $useClientEmail = !empty($data['use_client_email']);
    $allClients = !empty($data['all_clients']);
    $allSelectedFactures = !empty($data['all_selected_factures']);
    $sujet = !empty($data['sujet']) ? trim($data['sujet']) : null;
    $message = !empty($data['message']) ? trim($data['message']) : null;

    $dateEnvoi = $data['date_envoi_programmee'] ?? null;
    if (empty($dateEnvoi)) {
        jsonResponse(['ok' => false, 'error' => 'Date d\'envoi requise'], 400);
    }

    $config = require __DIR__ . '/../config/app.php';
    $appTz = new \DateTimeZone($config['app_timezone'] ?? 'Europe/Paris');
    $utcTz = new \DateTimeZone('UTC');

    $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $dateEnvoi, $appTz);
    if (!$dt) {
        $dt = \DateTime::createFromFormat('Y-m-d H:i', $dateEnvoi, $appTz);
    }
    if (!$dt) {
        $dt = \DateTime::createFromFormat('Y-m-d', $dateEnvoi, $appTz);
        if ($dt) {
            $dt->setTime(9, 0);
        }
    }
    if (!$dt) {
        jsonResponse(['ok' => false, 'error' => 'Format de date/heure invalide'], 400);
    }
    $dt->setTimezone($utcTz);
    $dateEnvoiFormatted = $dt->format('Y-m-d H:i:s');

    $nowUtc = new \DateTime('now', $utcTz);
    if ($dt <= $nowUtc) {
        jsonResponse(['ok' => false, 'error' => 'La date/heure doit être dans le futur'], 400);
    }

    if ($typeEnvoi === 'une_facture') {
        $factureId = isset($data['facture_id']) ? (int)$data['facture_id'] : null;
        if (!$factureId || $factureId <= 0) {
            jsonResponse(['ok' => false, 'error' => 'Facture requise'], 400);
        }
    } elseif ($typeEnvoi === 'plusieurs_factures' || $typeEnvoi === 'toutes_selectionnees') {
        $ids = $data['facture_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_filter(array_map('intval', $ids), fn($id) => $id > 0);
        if (empty($ids)) {
            jsonResponse(['ok' => false, 'error' => 'Aucune facture sélectionnée'], 400);
        }
        $facturesJson = json_encode(array_values($ids));
    }

    if (!$useClientEmail && !$allClients && empty($emailDestination)) {
        jsonResponse(['ok' => false, 'error' => 'Email destinataire requis ou cochez "Utiliser l\'email du client"'], 400);
    }

    if ($useClientEmail || $allClients) {
        $emailDestination = null;
    } elseif ($emailDestination && !filter_var($emailDestination, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'Email invalide'], 400);
    }

    $userId = $_SESSION['user_id'] ?? $_SESSION['user']['id'] ?? null;

    $stmt = $pdo->prepare("
        INSERT INTO factures_envois_programmes (
            type_envoi, facture_id, factures_json, client_id, email_destination,
            use_client_email, all_clients, all_selected_factures, sujet, message,
            date_envoi_programmee, statut, created_by
        ) VALUES (
            :type_envoi, :facture_id, :factures_json, :client_id, :email_destination,
            :use_client_email, :all_clients, :all_selected_factures, :sujet, :message,
            :date_envoi, 'en_attente', :created_by
        )
    ");
    $stmt->execute([
        ':type_envoi' => $typeEnvoi,
        ':facture_id' => $factureId,
        ':factures_json' => $facturesJson,
        ':client_id' => $clientId ?: null,
        ':email_destination' => $emailDestination,
        ':use_client_email' => $useClientEmail ? 1 : 0,
        ':all_clients' => $allClients ? 1 : 0,
        ':all_selected_factures' => $allSelectedFactures ? 1 : 0,
        ':sujet' => $sujet,
        ':message' => $message,
        ':date_envoi' => $dateEnvoiFormatted,
        ':created_by' => $userId
    ]);

    $id = (int)$pdo->lastInsertId();
    jsonResponse([
        'ok' => true,
        'message' => 'Programmation créée',
        'id' => $id
    ]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'factures_envois_programmes') !== false) {
        jsonResponse(['ok' => false, 'error' => 'Table factures_envois_programmes manquante. Exécutez la migration SQL.'], 500);
    }
    error_log('[factures_programmer_envoi] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
} catch (Throwable $e) {
    error_log('[factures_programmer_envoi] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
