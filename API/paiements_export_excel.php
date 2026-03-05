<?php
/**
 * API pour exporter les statistiques d'impression en Excel (XLSX)
 * Consommation = différence de compteurs cumulés (fin - début)
 * Filtres : client_id, mois, annee
 * - Mois vide + Année : export mensuel (12 lignes)
 * - Mois + Année : export journalier (jours du mois)
 * Feuilles : Résumé (agrégé) + Détails (par machine)
 */

require_once __DIR__ . '/../includes/api_helpers.php';
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

initApi();
requireApiAuth();

try {
    $pdo = getPdoOrFail();

    $idClient = isset($_GET['client_id']) ? (int)$_GET['client_id'] : null;
    $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : null;
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : null;

    $groupByMonth = ($annee !== null && $annee > 0 && ($mois === null || $mois <= 0));

    if ($groupByMonth) {
        $dateStart = sprintf('%04d-01-01', $annee);
        $dateEnd = sprintf('%04d-12-31 23:59:59', $annee);
        $dateStartExtended = sprintf('%04d-01-01', $annee - 1);
    } elseif ($mois !== null && $mois > 0 && $annee !== null && $annee > 0) {
        $dateStart = sprintf('%04d-%02d-01', $annee, $mois);
        $lastDay = (int)date('t', mktime(0, 0, 0, $mois, 1, $annee));
        $dateEnd = sprintf('%04d-%02d-%02d 23:59:59', $annee, $mois, $lastDay);
        $prevMonth = $mois - 1;
        $prevYear = $annee;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        $dateStartExtended = sprintf('%04d-%02d-01', $prevYear, $prevMonth);
    } else {
        $dateStart = date('Y-m-d', strtotime('-90 days'));
        $dateEnd = date('Y-m-d 23:59:59');
        $dateStartExtended = date('Y-m-d', strtotime($dateStart . ' -1 day'));
    }

    $clientFilter = "";
    $params = [':date_start' => $dateStartExtended, ':date_end' => $dateEnd];
    if ($idClient !== null && $idClient > 0) {
        $clientFilter = " AND pc.id_client = :id_client";
        $params[':id_client'] = $idClient;
    }

    $moisNoms = ['', 'Janvier', 'Février', 'Mars', 'Avril', 'Mai', 'Juin', 'Juillet', 'Août', 'Septembre', 'Octobre', 'Novembre', 'Décembre'];

    if ($groupByMonth) {
        $sqlWithPrev = "
            WITH raw AS (
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start AND r.Timestamp <= :date_end
                  " . $clientFilter . "
                UNION ALL
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee_ancien r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start2 AND r.Timestamp <= :date_end2
                  " . str_replace(':id_client', ':id_client2', $clientFilter) . "
            ),
            last_per_month AS (
                SELECT mac_norm, YEAR(Timestamp) AS annee, MONTH(Timestamp) AS mois,
                       TotalPages, TotalBW, TotalColor,
                       ROW_NUMBER() OVER (PARTITION BY mac_norm, YEAR(Timestamp), MONTH(Timestamp) ORDER BY Timestamp DESC) AS rn
                FROM raw
            ),
            one_per_month AS (
                SELECT mac_norm, annee, mois, TotalPages AS tp, TotalBW AS tb, TotalColor AS tc
                FROM last_per_month
                WHERE rn = 1
            ),
            with_prev AS (
                SELECT mac_norm, annee, mois,
                       tp, tb, tc,
                       COALESCE(tp, tb + tc) AS tp_safe,
                       LAG(COALESCE(tp, tb + tc)) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tp,
                       LAG(tb) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tb,
                       LAG(tc) OVER (PARTITION BY mac_norm ORDER BY annee, mois) AS prev_tc
                FROM one_per_month
            )
            SELECT mac_norm, annee, mois,
                   prev_tp AS debut_tp, tp_safe AS fin_tp,
                   CASE WHEN prev_tp IS NULL THEN 0 ELSE GREATEST(0, tp_safe - prev_tp) END AS conso_tp,
                   prev_tb AS debut_tb, tb AS fin_tb,
                   CASE WHEN prev_tb IS NULL THEN 0 ELSE GREATEST(0, tb - prev_tb) END AS conso_tb,
                   prev_tc AS debut_tc, tc AS fin_tc,
                   CASE WHEN prev_tc IS NULL THEN 0 ELSE GREATEST(0, tc - prev_tc) END AS conso_tc
            FROM with_prev
            WHERE annee = :annee_filter AND mois >= 1 AND mois <= 12
            ORDER BY annee ASC, mois ASC, mac_norm ASC
        ";
        $params[':date_start2'] = $dateStartExtended;
        $params[':date_end2'] = $dateEnd;
        $params[':annee_filter'] = $annee;
    } else {
        $sqlWithPrev = "
            WITH raw AS (
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start AND r.Timestamp <= :date_end
                  " . $clientFilter . "
                UNION ALL
                SELECT r.Timestamp, r.mac_norm, r.TotalPages, r.TotalBW, r.TotalColor
                FROM compteur_relevee_ancien r
                INNER JOIN photocopieurs_clients pc ON r.mac_norm = pc.mac_norm
                WHERE r.mac_norm IS NOT NULL AND r.mac_norm != ''
                  AND r.Timestamp >= :date_start2 AND r.Timestamp <= :date_end2
                  " . str_replace(':id_client', ':id_client2', $clientFilter) . "
            ),
            last_per_day AS (
                SELECT mac_norm, DATE(Timestamp) AS date_jour,
                       YEAR(Timestamp) AS annee, MONTH(Timestamp) AS mois, DAY(Timestamp) AS jour,
                       TotalPages, TotalBW, TotalColor,
                       ROW_NUMBER() OVER (PARTITION BY mac_norm, DATE(Timestamp) ORDER BY Timestamp DESC) AS rn
                FROM raw
            ),
            one_per_day AS (
                SELECT mac_norm, date_jour, annee, mois, jour,
                       TotalPages AS tp, TotalBW AS tb, TotalColor AS tc
                FROM last_per_day
                WHERE rn = 1
            ),
            with_prev AS (
                SELECT mac_norm, date_jour, annee, mois, jour,
                       tp, tb, tc,
                       COALESCE(tp, tb + tc) AS tp_safe,
                       LAG(COALESCE(tp, tb + tc)) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tp,
                       LAG(tb) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tb,
                       LAG(tc) OVER (PARTITION BY mac_norm ORDER BY date_jour) AS prev_tc
                FROM one_per_day
            )
            SELECT mac_norm, date_jour, annee, mois, jour,
                   prev_tp AS debut_tp, tp_safe AS fin_tp,
                   CASE WHEN prev_tp IS NULL THEN 0 ELSE GREATEST(0, tp_safe - prev_tp) END AS conso_tp,
                   prev_tb AS debut_tb, tb AS fin_tb,
                   CASE WHEN prev_tb IS NULL THEN 0 ELSE GREATEST(0, tb - prev_tb) END AS conso_tb,
                   prev_tc AS debut_tc, tc AS fin_tc,
                   CASE WHEN prev_tc IS NULL THEN 0 ELSE GREATEST(0, tc - prev_tc) END AS conso_tc
            FROM with_prev
            WHERE date_jour >= :date_start_filter
            ORDER BY date_jour ASC, mac_norm ASC
        ";
        $params[':date_start_filter'] = $dateStart;
        $params[':date_start2'] = $dateStartExtended;
        $params[':date_end2'] = $dateEnd;
    }

    if ($idClient !== null && $idClient > 0) {
        $params[':id_client2'] = $idClient;
    }

    $stmt = $pdo->prepare($sqlWithPrev);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $clientNom = 'Tous les clients';
    if ($idClient !== null && $idClient > 0) {
        $stmtC = $pdo->prepare("SELECT raison_sociale FROM clients WHERE id = :id LIMIT 1");
        $stmtC->execute([':id' => $idClient]);
        $c = $stmtC->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $clientNom = $c['raison_sociale'];
        }
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        $filename = 'consommation_' . date('Y-m-d_His') . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Statistiques d\'impression - Export CSV (PhpSpreadsheet non installé, exécutez: composer require phpoffice/phpspreadsheet)'], ';');
        fputcsv($out, ['Client: ' . $clientNom, 'Mode: ' . ($groupByMonth ? 'Mensuel' : 'Journalier')], ';');
        fputcsv($out, ['Période', 'Cpt début Total', 'Cpt fin Total', 'Conso Total', 'Cpt début N&B', 'Cpt fin N&B', 'Conso N&B', 'Cpt début Couleur', 'Cpt fin Couleur', 'Conso Couleur'], ';');
        $byPeriod = [];
        foreach ($rows as $r) {
            $key = $groupByMonth ? ($r['annee'] . '-' . $r['mois']) : $r['date_jour'];
            if (!isset($byPeriod[$key])) {
                $byPeriod[$key] = [
                    'periode' => $groupByMonth ? ($moisNoms[(int)$r['mois']] . ' ' . $r['annee']) : date('d/m/Y', strtotime($r['date_jour'])),
                    'debut_tp' => 0, 'fin_tp' => 0, 'conso_tp' => 0,
                    'debut_tb' => 0, 'fin_tb' => 0, 'conso_tb' => 0,
                    'debut_tc' => 0, 'fin_tc' => 0, 'conso_tc' => 0
                ];
            }
            $byPeriod[$key]['debut_tp'] += (int)($r['debut_tp'] ?? 0);
            $byPeriod[$key]['fin_tp'] += (int)($r['fin_tp'] ?? 0);
            $byPeriod[$key]['conso_tp'] += (int)($r['conso_tp'] ?? 0);
            $byPeriod[$key]['debut_tb'] += (int)($r['debut_tb'] ?? 0);
            $byPeriod[$key]['fin_tb'] += (int)($r['fin_tb'] ?? 0);
            $byPeriod[$key]['conso_tb'] += (int)($r['conso_tb'] ?? 0);
            $byPeriod[$key]['debut_tc'] += (int)($r['debut_tc'] ?? 0);
            $byPeriod[$key]['fin_tc'] += (int)($r['fin_tc'] ?? 0);
            $byPeriod[$key]['conso_tc'] += (int)($r['conso_tc'] ?? 0);
        }
        $fmt = function ($n) {
            return number_format((int)$n, 0, ',', ' ');
        };
        foreach ($byPeriod as $p) {
            fputcsv($out, [
                $p['periode'], $fmt($p['debut_tp']), $fmt($p['fin_tp']), $fmt($p['conso_tp']),
                $fmt($p['debut_tb']), $fmt($p['fin_tb']), $fmt($p['conso_tb']),
                $fmt($p['debut_tc']), $fmt($p['fin_tc']), $fmt($p['conso_tc'])
            ], ';');
        }
        fclose($out);
        exit;
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheetResume = $spreadsheet->getActiveSheet();
    $sheetResume->setTitle('Résumé');

    $row = 1;
    $sheetResume->setCellValue('A' . $row, 'Statistiques d\'impression');
    $sheetResume->mergeCells('A1:J1');
    $sheetResume->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $row += 2;

    $sheetResume->setCellValue('A' . $row, 'Filtres appliqués');
    $sheetResume->getStyle('A' . $row)->getFont()->setBold(true);
    $row++;
    $sheetResume->setCellValue('A' . $row, 'Client: ' . $clientNom);
    $row++;
    $sheetResume->setCellValue('A' . $row, 'Mode: ' . ($groupByMonth ? 'Mensuel' : 'Journalier'));
    $row++;
    $filters = [];
    if ($annee) $filters[] = 'Année: ' . $annee;
    if ($mois) $filters[] = 'Mois: ' . ($moisNoms[$mois] ?? $mois);
    $sheetResume->setCellValue('A' . $row, implode(' - ', $filters ?: ['Période: 90 derniers jours']));
    $row++;
    $sheetResume->setCellValue('A' . $row, 'Export: ' . date('d/m/Y H:i'));
    $row += 2;

    $headers = ['Période', 'Cpt début Total', 'Cpt fin Total', 'Consommation Total', 'Cpt début N&B', 'Cpt fin N&B', 'Consommation N&B', 'Cpt début Couleur', 'Cpt fin Couleur', 'Consommation Couleur'];
    foreach ($headers as $col => $h) {
        $sheetResume->setCellValueByColumnAndRow($col + 1, $row, $h);
    }
    $sheetResume->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
    $sheetResume->getStyle('A' . $row . ':J' . $row)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
    $headerRow = $row;
    $row++;

    $byPeriod = [];
    foreach ($rows as $r) {
        $key = $groupByMonth ? ($r['annee'] . '-' . str_pad($r['mois'], 2, '0', STR_PAD_LEFT)) : $r['date_jour'];
        if (!isset($byPeriod[$key])) {
            $byPeriod[$key] = [
                'periode' => $groupByMonth ? ($moisNoms[(int)$r['mois']] . ' ' . $r['annee']) : date('d/m/Y', strtotime($r['date_jour'])),
                'debut_tp' => 0, 'fin_tp' => 0, 'conso_tp' => 0,
                'debut_tb' => 0, 'fin_tb' => 0, 'conso_tb' => 0,
                'debut_tc' => 0, 'fin_tc' => 0, 'conso_tc' => 0,
                'sort' => $key
            ];
        }
        $byPeriod[$key]['debut_tp'] += (int)($r['debut_tp'] ?? 0);
        $byPeriod[$key]['fin_tp'] += (int)($r['fin_tp'] ?? 0);
        $byPeriod[$key]['conso_tp'] += (int)($r['conso_tp'] ?? 0);
        $byPeriod[$key]['debut_tb'] += (int)($r['debut_tb'] ?? 0);
        $byPeriod[$key]['fin_tb'] += (int)($r['fin_tb'] ?? 0);
        $byPeriod[$key]['conso_tb'] += (int)($r['conso_tb'] ?? 0);
        $byPeriod[$key]['debut_tc'] += (int)($r['debut_tc'] ?? 0);
        $byPeriod[$key]['fin_tc'] += (int)($r['fin_tc'] ?? 0);
        $byPeriod[$key]['conso_tc'] += (int)($r['conso_tc'] ?? 0);
    }
    ksort($byPeriod);

    $totDebutTp = $totFinTp = $totConsoTp = 0;
    $totDebutTb = $totFinTb = $totConsoTb = 0;
    $totDebutTc = $totFinTc = $totConsoTc = 0;

    foreach ($byPeriod as $p) {
        $sheetResume->setCellValue('A' . $row, $p['periode']);
        $sheetResume->setCellValue('B' . $row, (int)$p['debut_tp']);
        $sheetResume->setCellValue('C' . $row, (int)$p['fin_tp']);
        $sheetResume->setCellValue('D' . $row, (int)$p['conso_tp']);
        $sheetResume->setCellValue('E' . $row, (int)$p['debut_tb']);
        $sheetResume->setCellValue('F' . $row, (int)$p['fin_tb']);
        $sheetResume->setCellValue('G' . $row, (int)$p['conso_tb']);
        $sheetResume->setCellValue('H' . $row, (int)$p['debut_tc']);
        $sheetResume->setCellValue('I' . $row, (int)$p['fin_tc']);
        $sheetResume->setCellValue('J' . $row, (int)$p['conso_tc']);
        $sheetResume->getStyle('B' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0');
        $totDebutTp += (int)$p['debut_tp'];
        $totFinTp += (int)$p['fin_tp'];
        $totConsoTp += (int)$p['conso_tp'];
        $totDebutTb += (int)$p['debut_tb'];
        $totFinTb += (int)$p['fin_tb'];
        $totConsoTb += (int)$p['conso_tb'];
        $totDebutTc += (int)$p['debut_tc'];
        $totFinTc += (int)$p['fin_tc'];
        $totConsoTc += (int)$p['conso_tc'];
        $row++;
    }

    $sheetResume->setCellValue('A' . $row, 'TOTAL');
    $sheetResume->setCellValue('B' . $row, $totDebutTp);
    $sheetResume->setCellValue('C' . $row, $totFinTp);
    $sheetResume->setCellValue('D' . $row, $totConsoTp);
    $sheetResume->setCellValue('E' . $row, $totDebutTb);
    $sheetResume->setCellValue('F' . $row, $totFinTb);
    $sheetResume->setCellValue('G' . $row, $totConsoTb);
    $sheetResume->setCellValue('H' . $row, $totDebutTc);
    $sheetResume->setCellValue('I' . $row, $totFinTc);
    $sheetResume->setCellValue('J' . $row, $totConsoTc);
    $sheetResume->getStyle('A' . $row . ':J' . $row)->getFont()->setBold(true);
    $sheetResume->getStyle('B' . $row . ':J' . $row)->getNumberFormat()->setFormatCode('#,##0');
    $row++;

    $sheetResume->getColumnDimension('A')->setAutoSize(true);
    foreach (range('B', 'J') as $c) {
        $sheetResume->getColumnDimension($c)->setAutoSize(true);
    }
    $sheetResume->setAutoFilter('A' . $headerRow . ':J' . ($row - 2));
    $sheetResume->freezePane('A' . ($headerRow + 1));

    if (count($rows) > 0) {
        $sheetDetails = $spreadsheet->createSheet();
        $sheetDetails->setTitle('Détails');
        $rowD = 1;
        $sheetDetails->setCellValue('A' . $rowD, 'Machine');
        $sheetDetails->setCellValue('B' . $rowD, 'Période');
        $sheetDetails->setCellValue('C' . $rowD, 'Cpt début Total');
        $sheetDetails->setCellValue('D' . $rowD, 'Cpt fin Total');
        $sheetDetails->setCellValue('E' . $rowD, 'Consommation Total');
        $sheetDetails->setCellValue('F' . $rowD, 'Cpt début N&B');
        $sheetDetails->setCellValue('G' . $rowD, 'Cpt fin N&B');
        $sheetDetails->setCellValue('H' . $rowD, 'Consommation N&B');
        $sheetDetails->setCellValue('I' . $rowD, 'Cpt début Couleur');
        $sheetDetails->setCellValue('J' . $rowD, 'Cpt fin Couleur');
        $sheetDetails->setCellValue('K' . $rowD, 'Consommation Couleur');
        $sheetDetails->getStyle('A1:K1')->getFont()->setBold(true);
        $sheetDetails->getStyle('A1:K1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('E8E8E8');
        $rowD++;
        foreach ($rows as $r) {
            $periode = $groupByMonth ? ($moisNoms[(int)$r['mois']] . ' ' . $r['annee']) : date('d/m/Y', strtotime($r['date_jour']));
            $sheetDetails->setCellValue('A' . $rowD, $r['mac_norm']);
            $sheetDetails->setCellValue('B' . $rowD, $periode);
            $sheetDetails->setCellValue('C' . $rowD, (int)($r['debut_tp'] ?? 0));
            $sheetDetails->setCellValue('D' . $rowD, (int)($r['fin_tp'] ?? 0));
            $sheetDetails->setCellValue('E' . $rowD, (int)($r['conso_tp'] ?? 0));
            $sheetDetails->setCellValue('F' . $rowD, (int)($r['debut_tb'] ?? 0));
            $sheetDetails->setCellValue('G' . $rowD, (int)($r['fin_tb'] ?? 0));
            $sheetDetails->setCellValue('H' . $rowD, (int)($r['conso_tb'] ?? 0));
            $sheetDetails->setCellValue('I' . $rowD, (int)($r['debut_tc'] ?? 0));
            $sheetDetails->setCellValue('J' . $rowD, (int)($r['fin_tc'] ?? 0));
            $sheetDetails->setCellValue('K' . $rowD, (int)($r['conso_tc'] ?? 0));
            $sheetDetails->getStyle('C' . $rowD . ':K' . $rowD)->getNumberFormat()->setFormatCode('#,##0');
            $rowD++;
        }
        $sheetDetails->getColumnDimension('A')->setAutoSize(true);
        $sheetDetails->getColumnDimension('B')->setAutoSize(true);
        foreach (range('C', 'K') as $c) {
            $sheetDetails->getColumnDimension($c)->setAutoSize(true);
        }
        $sheetDetails->setAutoFilter('A1:K' . ($rowD - 1));
        $sheetDetails->freezePane('A2');
    }

    $filename = 'statistiques_impression_' . date('Y-m-d_His') . '.xlsx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;

} catch (PDOException $e) {
    error_log('paiements_export_excel.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('paiements_export_excel.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}
