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
$pdo = getPdoOrFail();

$id = (int)($_POST['id'] ?? 0);
$statut = trim((string)($_POST['statut'] ?? ''));

if ($id > 0 && $statut !== '') {
    if (!in_array($statut, ['planifie','fait','annule'], true)) {
        jsonResponse(['success' => false, 'message' => 'Statut invalide'], 400);
    }
    $stmt = $pdo->prepare("UPDATE commercial_activites SET statut = ? WHERE id = ?");
    $stmt->execute([$statut, $id]);
    jsonResponse(['success' => true, 'message' => 'Activité mise à jour']);
}

$clientId = (int)($_POST['client_id'] ?? 0);
$type = trim((string)($_POST['type'] ?? ''));
$titre = trim((string)($_POST['titre'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$dateActivite = trim((string)($_POST['date_activite'] ?? ''));

if ($clientId <= 0 || $titre === '' || $dateActivite === '' || !in_array($type, ['appel','email','rdv','relance','devis','autre'], true)) {
    jsonResponse(['success' => false, 'message' => 'Paramètres invalides'], 400);
}

$stmt = $pdo->prepare("
  INSERT INTO commercial_activites (client_id, type, titre, description, statut, date_activite, created_by)
  VALUES (?, ?, ?, ?, 'planifie', ?, ?)
");
$stmt->execute([
    $clientId,
    $type,
    $titre,
    $description !== '' ? $description : null,
    str_replace('T', ' ', $dateActivite),
    (int)($_SESSION['user_id'] ?? 0) ?: null,
]);

jsonResponse(['success' => true, 'message' => 'Activité enregistrée', 'id' => (int)$pdo->lastInsertId()]);
