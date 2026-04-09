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

$mois = trim((string)($_POST['mois'] ?? ''));
$objectifCa = (float)($_POST['objectif_ca'] ?? 0);
$objectifClients = (int)($_POST['objectif_clients'] ?? 0);
$objectifFactures = (int)($_POST['objectif_factures'] ?? 0);

if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
    jsonResponse(['success' => false, 'message' => 'Mois invalide'], 400);
}

$stmt = $pdo->prepare("
  INSERT INTO commercial_objectifs (mois, objectif_ca, objectif_clients, objectif_factures, created_by)
  VALUES (:mois, :objectif_ca, :objectif_clients, :objectif_factures, :created_by)
  ON DUPLICATE KEY UPDATE
    objectif_ca = VALUES(objectif_ca),
    objectif_clients = VALUES(objectif_clients),
    objectif_factures = VALUES(objectif_factures)
");
$stmt->execute([
    ':mois' => $mois,
    ':objectif_ca' => max(0, $objectifCa),
    ':objectif_clients' => max(0, $objectifClients),
    ':objectif_factures' => max(0, $objectifFactures),
    ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
]);

jsonResponse(['success' => true, 'message' => 'Objectif enregistré']);
