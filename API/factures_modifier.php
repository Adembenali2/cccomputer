<?php
declare(strict_types=1);

// [Fonctionnalité B]
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Methode non autorisee'], 405);
}
requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$id = (int)($data['id'] ?? $data['facture_id'] ?? 0);
$dateFacture = trim((string)($data['date_facture'] ?? ''));
$commentaire = trim((string)($data['commentaire'] ?? ''));

if ($id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFacture)) {
    jsonResponse(['ok' => false, 'error' => 'Parametres invalides'], 400);
}

$pdo = getPdoOrFail();

$stmt = $pdo->prepare("UPDATE factures SET date_facture = ?, updated_at = NOW() WHERE id = ? AND statut = 'brouillon'");
$stmt->execute([$dateFacture, $id]);
if ($stmt->rowCount() === 0) {
    jsonResponse(['ok' => false, 'error' => 'Seule une facture brouillon peut etre modifiee'], 400);
}

$detail = "Facture #{$id} modifiee. Date={$dateFacture}";
if ($commentaire !== '') {
    $detail .= " | {$commentaire}";
}
enregistrerAction($pdo, currentUserId(), 'facture_modifiee', $detail);

jsonResponse(['success' => true]);
