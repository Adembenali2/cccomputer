<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$role = strtolower((string)($_SESSION['role'] ?? $_SESSION['emploi'] ?? ''));
$allowed = ['admin','dirigeant','secretaire','commercial','charge_relation_clients'];
if (!in_array($role, $allowed, true)) {
    jsonResponse(['success' => false, 'message' => 'Accès refusé'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

requireCsrfForApi((string)($_POST['csrf_token'] ?? ''));
$factureId = (int)($_POST['facture_id'] ?? 0);
if ($factureId <= 0) {
    jsonResponse(['success' => false, 'message' => 'facture_id invalide'], 400);
}

$pdo = getPdoOrFail();
$numeroRelance = (int)($_POST['numero_relance'] ?? 1);
if (!in_array($numeroRelance, [1, 2, 3], true)) {
    $numeroRelance = 1;
}

$stmt = $pdo->prepare("
  SELECT id, statut FROM factures
  WHERE id = ? AND statut IN ('envoyee','partielle','en_retard')
  LIMIT 1
");
$stmt->execute([$factureId]);
$facture = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$facture) {
    jsonResponse(['success' => false, 'message' => 'Facture non éligible à la relance'], 400);
}

try {
    $pdo->prepare("
      INSERT INTO facture_relances (id_facture, numero_relance, envoye_par, statut, created_at)
      VALUES (?, ?, ?, 'envoye', NOW())
    ")->execute([$factureId, $numeroRelance, (int)($_SESSION['user_id'] ?? 0) ?: null]);
} catch (Throwable $e) {
    // Table optionnelle selon environnement, on continue avec update facture.
}

$pdo->prepare("
  UPDATE factures
  SET nb_relances = GREATEST(COALESCE(nb_relances,0), ?),
      date_derniere_relance = NOW(),
      statut = IF(? >= 2, 'en_retard', statut)
  WHERE id = ?
")->execute([$numeroRelance, $numeroRelance, $factureId]);

jsonResponse(['success' => true, 'message' => 'Relance enregistrée']);
