<?php
declare(strict_types=1);
/**
 * API pour annuler une programmation d'envoi
 */

require_once __DIR__ . '/../includes/api_helpers.php';

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

    if (!$data || !is_array($data) || empty($data['id'])) {
        jsonResponse(['ok' => false, 'error' => 'ID requis'], 400);
    }

    $id = (int)$data['id'];
    if ($id <= 0) {
        jsonResponse(['ok' => false, 'error' => 'ID invalide'], 400);
    }

    $stmt = $pdo->prepare("UPDATE factures_envois_programmes SET statut = 'annule' WHERE id = :id AND statut = 'en_attente'");
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) {
        $check = $pdo->prepare("SELECT id, statut FROM factures_envois_programmes WHERE id = :id");
        $check->execute([':id' => $id]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            jsonResponse(['ok' => false, 'error' => 'Programmation introuvable'], 404);
        }
        jsonResponse(['ok' => false, 'error' => 'Seules les programmations en attente peuvent être annulées'], 400);
    }

    jsonResponse(['ok' => true, 'message' => 'Programmation annulée']);
} catch (PDOException $e) {
    error_log('[factures_programmation_annuler] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
