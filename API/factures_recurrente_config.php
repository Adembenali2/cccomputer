<?php
declare(strict_types=1);

// [Fonctionnalité E]
require_once __DIR__ . '/../includes/api_helpers.php';

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

$idClient = (int)($data['id_client'] ?? 0);
$actif = (int)($data['actif'] ?? 1);
$type = (string)($data['type'] ?? 'Consommation');
$jour = (int)($data['jour_generation'] ?? 1);
$description = trim((string)($data['description_manuelle'] ?? ''));
$montant = ($data['montant_fixe'] === '' || !isset($data['montant_fixe'])) ? null : (float)$data['montant_fixe'];

if ($idClient <= 0 || !in_array($type, ['Consommation', 'Achat', 'Service'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Parametres invalides'], 400);
}
$jour = max(1, min(28, $jour));

$pdo = getPdoOrFail();
$sql = "
INSERT INTO factures_recurrentes (id_client, actif, type, jour_generation, description_manuelle, montant_fixe, updated_at)
VALUES (:id_client, :actif, :type, :jour_generation, :description_manuelle, :montant_fixe, NOW())
ON DUPLICATE KEY UPDATE
  actif = VALUES(actif),
  type = VALUES(type),
  jour_generation = VALUES(jour_generation),
  description_manuelle = VALUES(description_manuelle),
  montant_fixe = VALUES(montant_fixe),
  updated_at = NOW()
";
$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id_client' => $idClient,
    ':actif' => $actif ? 1 : 0,
    ':type' => $type,
    ':jour_generation' => $jour,
    ':description_manuelle' => $description !== '' ? $description : null,
    ':montant_fixe' => $montant,
]);

jsonResponse(['success' => true]);
