<?php
declare(strict_types=1);

// [Fonctionnalité F]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$idClient = (int)($_GET['id_client'] ?? 0);
if ($idClient <= 0) {
    jsonResponse(['success' => false, 'error' => 'id_client invalide'], 400);
}

try {
    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT * FROM client_contacts WHERE id_client = ? ORDER BY est_principal DESC, nom ASC');
    $stmt->execute([$idClient]);
    $contacts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jsonResponse(['success' => true, 'contacts' => $contacts]);
} catch (Throwable $e) {
    error_log('contacts_get.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
