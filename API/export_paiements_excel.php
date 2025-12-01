<?php
// API pour exporter les données de consommation en Excel
// Note: On n'utilise pas initApi() car on veut générer un fichier Excel, pas du JSON

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';

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
    $dateStartFull = $dateStart . ' 00:00:00';
    $dateEndFull = $dateEnd . ' 23:59:59';
    
    // ÉTAPE 1 : Récupérer tous les relevés pour trouver le premier compteur par MAC
    $sqlAllReleves = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor,
            Model,
            MacAddress
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              " . ($macNorm ? "AND mac_norm = :mac_norm1" : "") . "
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              " . ($macNorm ? "AND mac_norm = :mac_norm2" : "") . "
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $params = [];
    if ($macNorm) {
        $params[':mac_norm1'] = $macNorm;
        $params[':mac_norm2'] = $macNorm;
    }
    
    $stmt = $pdo->prepare($sqlAllReleves);
    $stmt->execute($params);
    $allReleves = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Trouver le premier compteur pour chaque MAC (compteur de départ)
    $firstCounters = [];
    foreach ($allReleves as $releve) {
        $mac = $releve['mac_norm'];
        if (!isset($firstCounters[$mac])) {
            $firstCounters[$mac] = [
                'bw' => (int)($releve['TotalBW'] ?? 0),
                'color' => (int)($releve['TotalColor'] ?? 0)
            ];
        }
    }
    
    // ÉTAPE 2 : Récupérer les relevés dans la période filtrée
    $sqlFiltered = "
        SELECT 
            mac_norm,
            Timestamp,
            COALESCE(TotalBW, 0) as TotalBW,
            COALESCE(TotalColor, 0) as TotalColor,
            Model,
            MacAddress
        FROM (
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start1 
              AND Timestamp <= :date_end1
              " . ($macNorm ? "AND mac_norm = :mac_norm1" : "") . "
            
            UNION ALL
            
            SELECT 
                mac_norm,
                Timestamp,
                TotalBW,
                TotalColor,
                Model,
                MacAddress
            FROM compteur_relevee_ancien
            WHERE mac_norm IS NOT NULL 
              AND mac_norm != ''
              AND Timestamp >= :date_start2 
              AND Timestamp <= :date_end2
              " . ($macNorm ? "AND mac_norm = :mac_norm2" : "") . "
        ) AS combined
        ORDER BY mac_norm, Timestamp ASC
    ";
    
    $paramsFiltered = [
        ':date_start1' => $dateStartFull,
        ':date_end1' => $dateEndFull,
        ':date_start2' => $dateStartFull,
        ':date_end2' => $dateEndFull
    ];
    
    if ($macNorm) {
        $paramsFiltered[':mac_norm1'] = $macNorm;
        $paramsFiltered[':mac_norm2'] = $macNorm;
    }
    
    $stmtFiltered = $pdo->prepare($sqlFiltered);
    $stmtFiltered->execute($paramsFiltered);
    $releves = $stmtFiltered->fetchAll(PDO::FETCH_ASSOC);
    
    // ÉTAPE 3 : Récupérer les informations des photocopieurs (nom, client)
    $sqlPhotocopieurs = "
        SELECT DISTINCT
            r.mac_norm,
            COALESCE(pc.MacAddress, r.MacAddress) as MacAddress,
            COALESCE(r.Model, 'Inconnu') as Model,
            COALESCE(c.raison_sociale, 'Photocopieur non attribué') as client_name
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
    
    // Créer un tableau associatif pour accéder rapidement aux infos
    $photocopieursMap = [];
    foreach ($photocopieursInfo as $p) {
        $photocopieursMap[$p['mac_norm']] = $p;
    }
    
    // ÉTAPE 4 : Calculer les compteurs début/fin pour chaque MAC
    $macData = [];
    
    foreach ($releves as $releve) {
        if (empty($releve['mac_norm']) || empty($releve['Timestamp'])) {
            continue;
        }
        
        $mac = $releve['mac_norm'];
        
        if (!isset($macData[$mac])) {
            $macData[$mac] = [
                'mac_norm' => $mac,
                'mac_address' => $releve['MacAddress'] ?? '',
                'model' => $photocopieursMap[$mac]['Model'] ?? 'Inconnu',
                'client_name' => $photocopieursMap[$mac]['client_name'] ?? 'Photocopieur non attribué',
                'first_bw' => $firstCounters[$mac]['bw'] ?? 0,
                'first_color' => $firstCounters[$mac]['color'] ?? 0,
                'start_bw' => null,
                'start_color' => null,
                'start_timestamp' => null,
                'end_bw' => null,
                'end_color' => null,
                'end_timestamp' => null
            ];
        }
        
        $timestamp = new DateTime($releve['Timestamp']);
        $bw = (int)($releve['TotalBW'] ?? 0);
        $color = (int)($releve['TotalColor'] ?? 0);
        
        // Premier relevé dans la période = compteur début
        if ($macData[$mac]['start_timestamp'] === null || $timestamp < $macData[$mac]['start_timestamp']) {
            $macData[$mac]['start_bw'] = $bw;
            $macData[$mac]['start_color'] = $color;
            $macData[$mac]['start_timestamp'] = $timestamp;
        }
        
        // Dernier relevé dans la période = compteur fin
        if ($macData[$mac]['end_timestamp'] === null || $timestamp > $macData[$mac]['end_timestamp']) {
            $macData[$mac]['end_bw'] = $bw;
            $macData[$mac]['end_color'] = $color;
            $macData[$mac]['end_timestamp'] = $timestamp;
        }
    }
    
    // ÉTAPE 5 : Générer le fichier Excel
    // Vérifier si PhpSpreadsheet est disponible
    $usePhpSpreadsheet = class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet');
    
    if ($usePhpSpreadsheet) {
        // Utiliser PhpSpreadsheet
        require_once __DIR__ . '/../vendor/autoload.php';
        
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        
        // En-têtes
        $headers = [
            'A1' => 'MAC adresse',
            'B1' => 'Photocopieur',
            'C1' => 'Compteur départ N&B',
            'D1' => 'Compteur départ Couleur',
            'E1' => 'Compteur début N&B',
            'F1' => 'Compteur début Couleur',
            'G1' => 'Compteur fin N&B',
            'H1' => 'Compteur fin Couleur',
            'I1' => 'Consommation N&B',
            'J1' => 'Consommation Couleur',
            'K1' => 'Période sélectionnée',
            'L1' => 'date_start',
            'M1' => 'date_end'
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
        
        // Données
        $row = 2;
        foreach ($macData as $data) {
            $consumptionBw = max(0, ($data['end_bw'] ?? 0) - ($data['first_bw'] ?? 0));
            $consumptionColor = max(0, ($data['end_color'] ?? 0) - ($data['first_color'] ?? 0));
            
            $sheet->setCellValue('A' . $row, $data['mac_address'] ?: $data['mac_norm']);
            $sheet->setCellValue('B' . $row, $data['client_name'] . ' - ' . $data['model']);
            $sheet->setCellValue('C' . $row, $data['first_bw']);
            $sheet->setCellValue('D' . $row, $data['first_color']);
            $sheet->setCellValue('E' . $row, $data['start_bw'] ?? 0);
            $sheet->setCellValue('F' . $row, $data['start_color'] ?? 0);
            $sheet->setCellValue('G' . $row, $data['end_bw'] ?? 0);
            $sheet->setCellValue('H' . $row, $data['end_color'] ?? 0);
            $sheet->setCellValue('I' . $row, $consumptionBw);
            $sheet->setCellValue('J' . $row, $consumptionColor);
            $sheet->setCellValue('K' . $row, $period);
            $sheet->setCellValue('L' . $row, $dateStart);
            $sheet->setCellValue('M' . $row, $dateEnd);
            $row++;
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
        // Fallback : Générer un CSV si PhpSpreadsheet n'est pas disponible
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment;filename="paiements_' . date('Y-m-d_His') . '.csv"');
        header('Cache-Control: max-age=0');
        
        $output = fopen('php://output', 'w');
        
        // BOM UTF-8 pour Excel
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // En-têtes
        fputcsv($output, [
            'MAC adresse',
            'Photocopieur',
            'Compteur départ N&B',
            'Compteur départ Couleur',
            'Compteur début N&B',
            'Compteur début Couleur',
            'Compteur fin N&B',
            'Compteur fin Couleur',
            'Consommation N&B',
            'Consommation Couleur',
            'Période sélectionnée',
            'date_start',
            'date_end'
        ], ';');
        
        // Données
        foreach ($macData as $data) {
            $consumptionBw = max(0, ($data['end_bw'] ?? 0) - ($data['first_bw'] ?? 0));
            $consumptionColor = max(0, ($data['end_color'] ?? 0) - ($data['first_color'] ?? 0));
            
            fputcsv($output, [
                $data['mac_address'] ?: $data['mac_norm'],
                $data['client_name'] . ' - ' . $data['model'],
                $data['first_bw'],
                $data['first_color'],
                $data['start_bw'] ?? 0,
                $data['start_color'] ?? 0,
                $data['end_bw'] ?? 0,
                $data['end_color'] ?? 0,
                $consumptionBw,
                $consumptionColor,
                $period,
                $dateStart,
                $dateEnd
            ], ';');
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

