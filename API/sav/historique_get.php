<?php
declare(strict_types=1);

// [Fonctionnalité G]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$idSav = (int)($_GET['id_sav'] ?? 0);
if ($idSav <= 0) {
    jsonResponse(['success' => false, 'error' => 'id_sav invalide'], 400);
}

try {
    $pdo = getPdo();
    $savStmt = $pdo->prepare('SELECT id, reference FROM sav WHERE id = ? LIMIT 1');
    $savStmt->execute([$idSav]);
    $sav = $savStmt->fetch(PDO::FETCH_ASSOC);
    if (!$sav) {
        jsonResponse(['success' => false, 'error' => 'SAV introuvable'], 404);
    }
    $ref = (string)($sav['reference'] ?? '');

    $stmt = $pdo->prepare("
      SELECT h.action, h.details, h.date_action, u.nom, u.prenom
      FROM historique h
      LEFT JOIN utilisateurs u ON u.id = h.user_id
      WHERE h.details LIKE CONCAT('%SAV #', :id_sav, '%')
         OR h.details LIKE CONCAT('%', :ref, '%')
      ORDER BY h.date_action DESC
      LIMIT 20
    ");
    $stmt->execute([':id_sav' => (string)$idSav, ':ref' => $ref]);
    $historique = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jsonResponse(['success' => true, 'historique' => $historique]);
} catch (Throwable $e) {
    error_log('historique_get.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
