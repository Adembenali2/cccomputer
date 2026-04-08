<?php
/**
 * API pour envoyer un reçu de paiement par email au client
 * Utilise le template professionnel receipt_email.html (logo, style facture)
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

initApi();
requireApiAuth();

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdoOrFail();
    
    // Récupération des données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !is_array($data)) {
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
    }
    
    if (empty($data['paiement_id'])) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id requis'], 400);
    }
    
    $paiementId = (int)$data['paiement_id'];
    
    if ($paiementId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id invalide'], 400);
    }
    
    // Récupérer les informations du paiement
    $stmt = $pdo->prepare("
        SELECT p.id, p.recu_path, p.recu_genere, c.email as client_email
        FROM paiements p
        LEFT JOIN clients c ON p.id_client = c.id
        WHERE p.id = :paiement_id
        LIMIT 1
    ");
    $stmt->execute([':paiement_id' => $paiementId]);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$paiement) {
        jsonResponse(['ok' => false, 'error' => 'Paiement introuvable'], 404);
    }
    
    if (empty($paiement['client_email'])) {
        jsonResponse(['ok' => false, 'error' => 'Le client n\'a pas d\'adresse email enregistrée'], 400);
    }
    
    if (empty($paiement['recu_path'])) {
        jsonResponse(['ok' => false, 'error' => 'Aucun reçu disponible pour ce paiement'], 400);
    }
    
    // Si le reçu n'existe pas sur le disque, tenter de le régénérer (si recu_genere)
    $findRecuPath = function($relativePath) {
        $normalized = ltrim(str_replace('\\', '/', $relativePath), '/');
        $dirs = array_filter([
            rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'),
            dirname(__DIR__),
            '/app',
            '/var/www/html',
        ], fn($d) => $d !== '' && is_dir($d));
        foreach ($dirs as $base) {
            $full = rtrim($base, '/') . '/' . $normalized;
            if (file_exists($full) && is_readable($full)) {
                return $full;
            }
        }
        return null;
    };
    
    if (!$findRecuPath($paiement['recu_path']) && $paiement['recu_genere']) {
        try {
            require_once __DIR__ . '/paiements_generer_recu.php';
            $newRecuPath = generateRecuPDF($pdo, $paiementId);
            $stmt = $pdo->prepare("UPDATE paiements SET recu_path = :recu_path WHERE id = :id");
            $stmt->execute([':recu_path' => $newRecuPath, ':id' => $paiementId]);
        } catch (Throwable $e) {
            error_log('[paiements_envoyer_recu] Régénération reçu: ' . $e->getMessage());
            jsonResponse(['ok' => false, 'error' => 'Erreur lors de la régénération du reçu'], 500);
        }
    }
    
    $config = require __DIR__ . '/../config/app.php';
    $receiptService = new \App\Services\PaymentReceiptEmailService($pdo, $config);
    $emailOverride = !empty($data['email']) && filter_var($data['email'], FILTER_VALIDATE_EMAIL) ? $data['email'] : null;
    
    $result = $receiptService->sendReceipt($paiementId, $emailOverride);
    
    if ($result['success']) {
        jsonResponse([
            'ok' => true,
            'message' => 'Reçu envoyé avec succès',
            'paiement_id' => $paiementId,
            'email' => $result['email'] ?? $paiement['client_email'],
            'message_id' => $result['message_id'] ?? null
        ]);
    } else {
        jsonResponse(['ok' => false, 'error' => $result['message'] ?? 'Erreur lors de l\'envoi'], 500);
    }
    
} catch (PDOException $e) {
    error_log('[paiements_envoyer_recu] PDOException: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('[paiements_envoyer_recu] Exception: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

