<?php
declare(strict_types=1);

// [Fonctionnalité A] Liste paiements avec filtres
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

$date = trim((string)($_GET['date'] ?? ''));
$client = (int)($_GET['client_id'] ?? 0);
$mode = trim((string)($_GET['mode_paiement'] ?? ''));
$statutFacture = trim((string)($_GET['statut_facture'] ?? ''));

$where = [];
$params = [];
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $where[] = "p.date_paiement = :date";
    $params[':date'] = $date;
}
if ($client > 0) {
    $where[] = "c.id = :client_id";
    $params[':client_id'] = $client;
}
if ($mode !== '') {
    $where[] = "p.mode_paiement = :mode";
    $params[':mode'] = $mode;
}
if ($statutFacture !== '') {
    $where[] = "f.statut = :sf";
    $params[':sf'] = $statutFacture;
}

$hasFactureId = columnExists($pdo, 'paiements', 'facture_id');
$joinFacture = $hasFactureId ? 'COALESCE(p.facture_id, p.id_facture)' : 'p.id_facture';

$sql = "
SELECT p.id,
       {$joinFacture} AS facture_id,
       p.montant, p.mode_paiement, p.date_paiement, p.reference, p.created_at,
       f.numero AS facture_numero, f.statut AS facture_statut, f.montant_ttc, COALESCE(f.montant_paye,0) AS montant_paye,
       c.id AS client_id, c.raison_sociale AS client_nom
FROM paiements p
LEFT JOIN factures f ON f.id = {$joinFacture}
LEFT JOIN clients c ON c.id = f.id_client
" . (!empty($where) ? (" WHERE " . implode(" AND ", $where)) : "") . "
ORDER BY p.date_paiement DESC, p.id DESC
LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

jsonResponse(['ok' => true, 'items' => $rows]);
