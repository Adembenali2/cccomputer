<?php
declare(strict_types=1);

// [Fonctionnalité D]
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../src/Services/InvoiceCalculationService.php';

use App\Services\InvoiceCalculationService;

initApi();
requireApiAuth();

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
$type = (string)($data['type'] ?? 'Consommation');
$dateDebut = trim((string)($data['date_debut'] ?? ''));
$dateFin = trim((string)($data['date_fin'] ?? ''));

if ($idClient <= 0 || !in_array($type, ['Consommation', 'Achat', 'Service'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Parametres invalides'], 400);
}

if ($type !== 'Consommation') {
    jsonResponse([
        'success' => true,
        'lignes' => [],
        'totaux' => ['montant_ht' => 0, 'tva' => 0, 'montant_ttc' => 0],
        'machines' => [],
    ]);
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
    jsonResponse(['ok' => false, 'error' => 'Dates invalides'], 400);
}

$pdo = getPdoOrFail();
$stmt = $pdo->prepare("SELECT id, SerialNumber, Model, mac_norm FROM photocopieurs_clients WHERE id_client = ? ORDER BY id ASC LIMIT 2");
$stmt->execute([$idClient]);
$pcs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$machines = [];
foreach ($pcs as $idx => $pc) {
    $mac = trim((string)($pc['mac_norm'] ?? ''));
    if ($mac === '') {
        continue;
    }

    $qStart = $pdo->prepare("
        SELECT COALESCE(TotalBW,0) bw, COALESCE(TotalColor,0) color
        FROM compteur_relevee
        WHERE mac_norm = ? AND DATE(Timestamp) <= ?
        ORDER BY Timestamp ASC LIMIT 1
    ");
    $qEnd = $pdo->prepare("
        SELECT COALESCE(TotalBW,0) bw, COALESCE(TotalColor,0) color
        FROM compteur_relevee
        WHERE mac_norm = ? AND DATE(Timestamp) <= ?
        ORDER BY Timestamp DESC LIMIT 1
    ");
    $qStart->execute([$mac, $dateDebut]);
    $qEnd->execute([$mac, $dateFin]);
    $start = $qStart->fetch(PDO::FETCH_ASSOC) ?: ['bw' => 0, 'color' => 0];
    $end = $qEnd->fetch(PDO::FETCH_ASSOC) ?: ['bw' => 0, 'color' => 0];

    $bw = max(0, (int)$end['bw'] - (int)$start['bw']);
    $color = max(0, (int)$end['color'] - (int)$start['color']);
    $name = 'Imprimante ' . ($idx + 1);

    $machines[] = [
        'serial' => (string)($pc['SerialNumber'] ?? ''),
        'model' => (string)($pc['Model'] ?? ''),
        'bw' => $bw,
        'color' => $color,
        'nom' => $name,
    ];
}

$calcMachines = [];
foreach ($machines as $i => $m) {
    $calcMachines['machine' . ($i + 1)] = [
        'conso_nb' => (float)$m['bw'],
        'conso_couleur' => (float)$m['color'],
        'nom' => $m['nom'],
    ];
}

$offre = count($machines) >= 2 ? 2000 : 1000;
$lignes = InvoiceCalculationService::generateAllInvoiceLines($offre, max(1, count($machines)), $calcMachines);
$totaux = InvoiceCalculationService::calculateInvoiceTotals($lignes);

jsonResponse([
    'success' => true,
    'lignes' => array_map(static function (array $l): array {
        return [
            'description' => $l['description'] ?? '',
            'type' => $l['type'] ?? '',
            'quantite' => (float)($l['quantite'] ?? 0),
            'pu_ht' => (float)($l['prix_unitaire'] ?? 0),
            'total_ht' => (float)($l['total_ht'] ?? 0),
        ];
    }, $lignes),
    'totaux' => $totaux,
    'machines' => $machines,
]);
