<?php
declare(strict_types=1);

// [Fonctionnalité A]
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

$sql = "
    SELECT f.numero, f.date_facture, f.type, f.montant_ht, f.tva, f.montant_ttc, f.statut,
           f.email_envoye, c.raison_sociale
    FROM factures f
    LEFT JOIN clients c ON c.id = f.id_client
    ORDER BY f.date_facture DESC, f.id DESC
    LIMIT 500
";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="factures_export.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['N°', 'Client', 'Date', 'Type', 'HT', 'TVA', 'TTC', 'Statut', 'Email envoye'], ';');
foreach ($rows as $r) {
    fputcsv($out, [
        $r['numero'] ?? '',
        $r['raison_sociale'] ?? '',
        $r['date_facture'] ?? '',
        $r['type'] ?? '',
        number_format((float)($r['montant_ht'] ?? 0), 2, ',', ''),
        number_format((float)($r['tva'] ?? 0), 2, ',', ''),
        number_format((float)($r['montant_ttc'] ?? 0), 2, ',', ''),
        $r['statut'] ?? '',
        ((int)($r['email_envoye'] ?? 0) === 1) ? 'Oui' : 'Non',
    ], ';');
}
fclose($out);
exit;
