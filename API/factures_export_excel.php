<?php
/**
 * Export des factures en Excel (XLSX)
 * GET ?client_id=&date_debut=&date_fin=&statut=
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('HTTP/1.1 405 Method Not Allowed');
    exit;
}

try {
    $pdo = getPdo();
    $clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $dateDebut = trim($_GET['date_debut'] ?? '');
    $dateFin = trim($_GET['date_fin'] ?? '');
    $statut = trim($_GET['statut'] ?? '');
    
    $conditions = ["f.statut != 'annulee'"];
    $params = [];
    
    if ($clientId > 0) {
        $conditions[] = "f.id_client = :client_id";
        $params[':client_id'] = $clientId;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut)) {
        $conditions[] = "f.date_facture >= :date_debut";
        $params[':date_debut'] = $dateDebut;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
        $conditions[] = "f.date_facture <= :date_fin";
        $params[':date_fin'] = $dateFin;
    }
    if ($statut !== '' && in_array($statut, ['brouillon', 'en_attente', 'envoyee', 'en_cours', 'en_retard', 'payee'], true)) {
        if ($statut === 'payee') {
            $conditions[] = "COALESCE(p.total_paye, 0) >= f.montant_ttc AND f.montant_ttc > 0";
        } else {
            $conditions[] = "f.statut = :statut";
            $params[':statut'] = $statut;
        }
    }
    
    $where = implode(' AND ', $conditions);
    $joinPayee = "
        LEFT JOIN (SELECT id_facture, SUM(montant) as total_paye FROM paiements WHERE statut='recu' GROUP BY id_facture) p ON p.id_facture = f.id
    ";
    
    $sql = "
        SELECT f.id, f.numero, f.date_facture, f.type, f.montant_ht, f.tva, f.montant_ttc, f.statut,
               c.raison_sociale as client_nom, c.numero_client as client_code,
               COALESCE(p.total_paye, 0) as total_paye
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        $joinPayee
        WHERE $where
        ORDER BY f.date_facture DESC, f.id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="factures_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, ['Numéro', 'Date', 'Client', 'Type', 'Montant HT', 'TVA', 'TTC', 'Payé', 'Reste', 'Statut'], ';');
        foreach ($rows as $r) {
            $reste = max(0, (float)$r['montant_ttc'] - (float)$r['total_paye']);
            fputcsv($out, [
                $r['numero'], $r['date_facture'], $r['client_nom'], $r['type'],
                $r['montant_ht'], $r['tva'], $r['montant_ttc'], $r['total_paye'], $reste, $r['statut']
            ], ';');
        }
        fclose($out);
        exit;
    }
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Factures');
    
    $headers = ['Numéro', 'Date', 'Client', 'Code', 'Type', 'Montant HT', 'TVA', 'TTC', 'Payé', 'Reste', 'Statut'];
    $col = 'A';
    foreach ($headers as $h) {
        $sheet->setCellValue($col . '1', $h);
        $col++;
    }
    $sheet->getStyle('A1:K1')->getFont()->setBold(true);
    $sheet->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
    
    $row = 2;
    foreach ($rows as $r) {
        $reste = max(0, (float)$r['montant_ttc'] - (float)$r['total_paye']);
        $sheet->setCellValue('A' . $row, $r['numero']);
        $sheet->setCellValue('B' . $row, $r['date_facture']);
        $sheet->setCellValue('C' . $row, $r['client_nom']);
        $sheet->setCellValue('D' . $row, $r['client_code']);
        $sheet->setCellValue('E' . $row, $r['type']);
        $sheet->setCellValue('F' . $row, (float)$r['montant_ht']);
        $sheet->setCellValue('G' . $row, (float)$r['tva']);
        $sheet->setCellValue('H' . $row, (float)$r['montant_ttc']);
        $sheet->setCellValue('I' . $row, (float)$r['total_paye']);
        $sheet->setCellValue('J' . $row, $reste);
        $sheet->setCellValue('K' . $row, $r['statut']);
        $sheet->getStyle('F' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0.00');
        $row++;
    }
    
    foreach (range('A', 'K') as $c) {
        $sheet->getColumnDimension($c)->setAutoSize(true);
    }
    
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $filename = 'factures_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer->save('php://output');
    exit;
    
} catch (Throwable $e) {
    error_log('factures_export_excel.php: ' . $e->getMessage());
    header('HTTP/1.1 500 Internal Server Error');
    echo 'Erreur: ' . $e->getMessage();
    exit;
}
