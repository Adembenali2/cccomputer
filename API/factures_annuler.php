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

$id = (int)($data['id'] ?? 0);
$motif = trim((string)($data['motif'] ?? ''));
if ($id <= 0 || $motif === '') {
    jsonResponse(['ok' => false, 'error' => 'id et motif obligatoires'], 400);
}

$pdo = getPdoOrFail();

$st = $pdo->prepare("SELECT statut FROM factures WHERE id = ? LIMIT 1");
$st->execute([$id]);
$f = $st->fetch(PDO::FETCH_ASSOC);
if (!$f) {
    jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
}
if (($f['statut'] ?? '') === 'payee') {
    jsonResponse(['ok' => false, 'error' => 'Impossible d annuler une facture payee'], 400);
}

$stmt = $pdo->prepare("UPDATE factures SET statut = 'annulee', updated_at = NOW() WHERE id = ?");
$stmt->execute([$id]);

logAction(
    $pdo,
    'facture',
    'annulation',
    'Facture annulee — #' . $id,
    $motif,
    $id,
    'factures.php'
);
jsonResponse(['success' => true]);
