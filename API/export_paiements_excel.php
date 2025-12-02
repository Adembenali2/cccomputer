<?php
// API pour exporter les données de consommation en Excel
// Affiche toutes les périodes de facturation (20→20) avec compteur départ, fin, consommation et période
// Note: On n'utilise pas initApi() car on veut générer un fichier Excel, pas du JSON

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/includes/paiements_helpers.php';

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die('Non authentifié');
}

// Vérifier la connexion PDO
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    die('Erreur de connexion à la base de données');
}

// Paramètres de filtrage
$period = trim($_GET['period'] ?? 'month');
$macFilter = trim($_GET['mac'] ?? '');
$dateStart = trim($_GET['date_start'] ?? '');
$dateEnd = trim($_GET['date_end'] ?? '');

// Validation de la période
if (!in_array($period, ['day', 'month', 'year'], true)) {
    $period = 'month';
}

// Validation des dates
if (empty($dateStart) || empty($dateEnd)) {
    http_response_code(400);
    die('Dates de début et de fin requises');
}

// Normaliser la MAC si fournie
$macNorm = null;
if (!empty($macFilter)) {
    $macNorm = strtoupper(preg_replace('/[^0-9A-F]/', '', $macFilter));
    if (empty($macNorm)) {
        if (is_numeric($macFilter)) {
            $macNorm = strtoupper(dechex((int)$macFilter));
        }
    }
    if (strlen($macNorm) < 12 && preg_match('/^[0-9A-F]+$/', $macNorm)) {
        $macNorm = str_pad($macNorm, 12, '0', STR_PAD_LEFT);
    }
    if (strlen($macNorm) !== 12 || !preg_match('/^[0-9A-F]{12}$/', $macNorm)) {
        http_response_code(400);
        die('Format MAC invalide');
    }
}

try {
    $dateStartObj = new DateTime($dateStart);
    $dateEndObj = new DateTime($dateEnd);
    
    // Récupérer les informations des photocopieurs
    $sqlPhotocopieurs = "
        SELECT DISTINCT
            r.mac_norm,
            COALESCE(pc.MacAddress, r.MacAddress) as MacAddress,
            COALESCE(r.Model, 'Inconnu') as Model,
            COALESCE(c.raison_sociale, 'Photocopieur non attribué') as client_name,
            c.id as client_id,
            c.numero_client
        FROM (
            SELECT DISTINCT mac_norm, MacAddress, Model
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
            UNION
            SELECT DISTINCT mac_norm, MacAddress, Model
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL AND mac_norm != ''
        ) AS r
        LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = r.mac_norm
        LEFT JOIN clients c ON c.id = pc.id_client
    ";
    
    if ($macNorm) {
        $sqlPhotocopieurs .= " WHERE r.mac_norm = :mac_norm";
    }
    
    $stmtPhotocopieurs = $pdo->prepare($sqlPhotocopieurs);
    if ($macNorm) {
        $stmtPhotocopieurs->execute([':mac_norm' => $macNorm]);
    } else {
        $stmtPhotocopieurs->execute();
    }
    $photocopieursInfo = $stmtPhotocopieurs->fetchAll(PDO::FETCH_ASSOC);
    
    // Construire un tableau des données par période et par MAC
    $exportData = []; // Structure: [period_key => [mac => data]]
    
    // Générer toutes les périodes de facturation dans la plage de dates
    $currentPeriod = clone $dateStartObj;
    
    // Ajuster à la période de facturation de départ
    $startDay = (int)$currentPeriod->format('d');
    if ($startDay < 20) {
        $currentPeriod->modify('-1 month');
    }
    $currentPeriod->setDate(
        (int)$currentPeriod->format('Y'),
        (int)$currentPeriod->format('m'),
        20
    );
    $currentPeriod->setTime(0, 0, 0);
    
    // Parcourir toutes les périodes jusqu'à la date de fin
    while ($currentPeriod <= $dateEndObj) {
        $periodStart = clone $currentPeriod;
        $periodEnd = clone $currentPeriod;
        $periodEnd->modify('+1 month');
        
        $periodKey = $periodStart->format('Y-m-d') . '_' . $periodEnd->format('Y-m-d');
        $periodLabel = $periodStart->format('d/m/Y') . ' → ' . $periodEnd->format('d/m/Y');
        
        // Pour chaque photocopieur, calculer la consommation de cette période
        foreach ($photocopieursInfo as $photo) {
            $mac = $photo['mac_norm'];
            
            if (!isset($exportData[$periodKey])) {
                $exportData[$periodKey] = [];
            }
            
            // Calculer la consommation pour cette période selon la logique 20→20
            $consumption = calculatePeriodConsumption($pdo, $mac, $periodStart, $periodEnd);
            
            if (!$consumption || (($consumption['bw'] ?? 0) == 0 && ($consumption['color'] ?? 0) == 0)) {
                // Ne pas inclure les périodes sans consommation
                continue;
            }
            
            $startCounter = $consumption['start_counter'] ?? null;
            $endCounter = $consumption['end_counter'] ?? null;
            
            $exportData[$periodKey][$mac] = [
                'mac_norm' => $mac,
                'mac_address' => $photo['MacAddress'] ?? $mac,
                'model' => $photo['Model'] ?? 'Inconnu',
                'client_name' => $photo['client_name'] ?? 'Photocopieur non attribué',
                'numero_client' => $photo['numero_client'] ?? '',
                'period_label' => $periodLabel,
                'period_start' => $periodStart->format('Y-m-d'),
                'period_end' => $periodEnd->format('Y-m-d'),
                'counter_start_bw' => $startCounter ? ($startCounter['bw'] ?? 0) : 0,
                'counter_start_color' => $startCounter ? ($startCounter['color'] ?? 0) : 0,
                'counter_start_date' => $startCounter && isset($startCounter['timestamp']) ? $startCounter['timestamp']->format('Y-m-d H:i:s') : '',
                'counter_end_bw' => $endCounter ? ($endCounter['bw'] ?? 0) : 0,
                'counter_end_color' => $endCounter ? ($endCounter['color'] ?? 0) : 0,
                'counter_end_date' => $endCounter && isset($endCounter['timestamp']) ? $endCounter['timestamp']->format('Y-m-d H:i:s') : '',
                'consumption_bw' => $consumption['bw'] ?? 0,
                'consumption_color' => $consumption['color'] ?? 0
            ];
        }
        
        // Passer à la période suivante
        $currentPeriod->modify('+1 month');
    }
    
    // Générer le fichier Excel ou CSV
    $usePhpSpreadsheet = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
    
    if ($usePhpSpreadsheet) {
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // En-têtes avec toutes les colonnes demandées
        $headers = [
            'A1' => 'Période',
            'B1' => 'MAC adresse',
            'C1' => 'Client',
            'D1' => 'Numéro client',
            'E1' => 'Photocopieur',
            'F1' => 'Date compteur départ',
            'G1' => 'Compteur départ N&B',
            'H1' => 'Compteur départ Couleur',
            'I1' => 'Date compteur fin',
            'J1' => 'Compteur fin N&B',
            'K1' => 'Compteur fin Couleur',
            'L1' => 'Consommation N&B',
            'M1' => 'Consommation Couleur'
        ];
        
        foreach ($headers as $cell => $value) {
            $sheet->setCellValue($cell, $value);
        }
        
        // Style des en-têtes
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => [
                'fillType' => \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => ['horizontal' => \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($headerStyle);
        
        // Données par période
        $row = 2;
        foreach ($exportData as $periodKey => $macs) {
            foreach ($macs as $data) {
                $sheet->setCellValue('A' . $row, $data['period_label']);
                $sheet->setCellValue('B' . $row, $data['mac_address']);
                $sheet->setCellValue('C' . $row, $data['client_name']);
                $sheet->setCellValue('D' . $row, $data['numero_client']);
                $sheet->setCellValue('E' . $row, $data['model']);
                $sheet->setCellValue('F' . $row, $data['counter_start_date']);
                $sheet->setCellValue('G' . $row, $data['counter_start_bw']);
                $sheet->setCellValue('H' . $row, $data['counter_start_color']);
                $sheet->setCellValue('I' . $row, $data['counter_end_date']);
                $sheet->setCellValue('J' . $row, $data['counter_end_bw']);
                $sheet->setCellValue('K' . $row, $data['counter_end_color']);
                $sheet->setCellValue('L' . $row, $data['consumption_bw']);
                $sheet->setCellValue('M' . $row, $data['consumption_color']);
                $row++;
            }
        }
        
        // Ajuster la largeur des colonnes
        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }
        
        // Générer le fichier
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="paiements_' . date('Y-m-d_His') . '.xlsx"');
        header('Cache-Control: max-age=0');
        
        $writer->save('php://output');
        exit;
        
    } else {
        // Fallback : Générer un CSV
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment;filename="paiements_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // En-têtes
        fputcsv($output, [
            'Période',
            'MAC adresse',
            'Client',
            'Numéro client',
            'Photocopieur',
            'Date compteur départ',
            'Compteur départ N&B',
            'Compteur départ Couleur',
            'Date compteur fin',
            'Compteur fin N&B',
            'Compteur fin Couleur',
            'Consommation N&B',
            'Consommation Couleur'
        ], ';');
        
        // Données
        foreach ($exportData as $periodKey => $macs) {
            foreach ($macs as $data) {
                fputcsv($output, [
                    $data['period_label'],
                    $data['mac_address'],
                    $data['client_name'],
                    $data['numero_client'],
                    $data['model'],
                    $data['counter_start_date'],
                    $data['counter_start_bw'],
                    $data['counter_start_color'],
                    $data['counter_end_date'],
                    $data['counter_end_bw'],
                    $data['counter_end_color'],
                    $data['consumption_bw'],
                    $data['consumption_color']
                ], ';');
            }
        }
        
        fclose($output);
        exit;
    }
    
} catch (PDOException $e) {
    error_log('export_paiements_excel.php PDO error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur base de données: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('export_paiements_excel.php error: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur serveur: ' . $e->getMessage());
}
