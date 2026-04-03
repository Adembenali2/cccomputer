<?php

declare(strict_types=1);

/**
 * Export PDF — Rapport SAV (période + filtre statut optionnel)
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$dateDebut = trim((string) ($_GET['date_debut'] ?? ''));
$dateFin = trim((string) ($_GET['date_fin'] ?? ''));
$statutFilter = trim((string) ($_GET['statut'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateDebut) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFin)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres date_debut / date_fin invalides (format Y-m-d)'], 400);
}

if ($dateDebut > $dateFin) {
    jsonResponse(['ok' => false, 'error' => 'date_debut doit être antérieure ou égale à date_fin'], 400);
}

$allowedStatuts = ['ouvert', 'en_cours', 'resolu', 'annule'];
if ($statutFilter !== '' && !in_array($statutFilter, $allowedStatuts, true)) {
    jsonResponse(['ok' => false, 'error' => 'Valeur statut invalide'], 400);
}

$pdo = getPdoOrFail();

$sql = 'SELECT s.*, c.raison_sociale, u.nom AS technicien_nom, u.prenom AS technicien_prenom
    FROM sav s
    LEFT JOIN clients c ON s.id_client = c.id
    LEFT JOIN utilisateurs u ON s.id_technicien = u.id
    WHERE s.date_ouverture BETWEEN :date_debut AND :date_fin';
$params = [':date_debut' => $dateDebut, ':date_fin' => $dateFin];

if ($statutFilter !== '') {
    $sql .= ' AND s.statut = :statut';
    $params[':statut'] = $statutFilter;
}

$sql .= ' ORDER BY s.date_ouverture DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('export_pdf_sav: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors de la lecture des données'], 500);
}

$typePanneLabels = [
    'logiciel' => 'Logiciel',
    'materiel' => 'Matériel',
    'piece_rechangeable' => 'Pièce',
];
$statutLabels = [
    'ouvert' => 'Ouvert',
    'en_cours' => 'En cours',
    'resolu' => 'Résolu',
    'annule' => 'Annulé',
];

$pdfStr = buildSavPdf(
    $rows,
    $dateDebut,
    $dateFin,
    $statutFilter,
    $typePanneLabels,
    $statutLabels,
    trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? '')) ?: 'Utilisateur'
);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$fname = 'rapport_sav_' . $dateDebut . '_' . $dateFin . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fname) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdfStr;

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, string> $typePanneLabels
 * @param array<string, string> $statutLabels
 */
function buildSavPdf(
    array $rows,
    string $dateDebut,
    string $dateFin,
    string $statutFilter,
    array $typePanneLabels,
    array $statutLabels,
    string $userDisplay
): string {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('CCComputer');
    $pdf->SetTitle('Rapport SAV');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(12, 12, 12);
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->setCellPaddings(1, 1, 1, 1);
    $pdf->setCellHeightRatio(1.05);

    $logoPath = __DIR__ . '/../assets/logos/logo1.png';
    if (!is_file($logoPath)) {
        $logoPath = __DIR__ . '/../assets/logos/logo.png';
    }
    if (is_file($logoPath)) {
        $ext = strtolower(pathinfo($logoPath, PATHINFO_EXTENSION));
        $imgFmt = $ext === 'png' ? 'PNG' : (($ext === 'jpg' || $ext === 'jpeg') ? 'JPEG' : '');
        $pdf->Image($logoPath, 12, 10, 45, 0, $imgFmt);
        $pdf->SetY(22);
    } else {
        $pdf->SetY(10);
    }

    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetTextColor(30, 41, 59);
    $pdf->Cell(0, 8, 'Rapport SAV', 0, 1, 'R');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(71, 85, 105);
    $periode = 'Période : du ' . date('d/m/Y', strtotime($dateDebut)) . ' au ' . date('d/m/Y', strtotime($dateFin));
    if ($statutFilter !== '') {
        $periode .= ' — Statut : ' . ($statutLabels[$statutFilter] ?? $statutFilter);
    }
    $pdf->Cell(0, 6, $periode, 0, 1, 'R');
    $pdf->Ln(4);

    $pdf->SetDrawColor(226, 232, 240);
    $pdf->SetLineWidth(0.3);
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetTextColor(71, 85, 105);

    // Largeurs (mm) — paysage ~277 - 24 marges = 253
    $w = [24, 34, 28, 20, 16, 18, 22, 22, 18, 18];
    $headers = ['Référence', 'Client', 'Technicien', 'Type panne', 'Priorité', 'Statut', 'Date ouv.', 'Date ferm.', 'Durée (h)', 'Coût (€)'];

    $renderHeader = static function () use ($pdf, $w, $headers): void {
        $pdf->SetFont('helvetica', 'B', 7);
        $pdf->SetFillColor(248, 250, 252);
        $pdf->SetTextColor(71, 85, 105);
        $pdf->SetX(12);
        foreach ($headers as $i => $h) {
            $pdf->Cell($w[$i], 7, $h, 1, 0, 'C', true);
        }
        $pdf->Ln();
        $pdf->SetFont('helvetica', '', 7);
        $pdf->SetTextColor(30, 41, 59);
    };

    $renderHeader();

    $totalCout = 0.0;
    $nb = count($rows);

    foreach ($rows as $r) {
        if ($pdf->GetY() > 185) {
            $pdf->AddPage();
            $renderHeader();
        }

        $tech = trim(($r['technicien_prenom'] ?? '') . ' ' . ($r['technicien_nom'] ?? ''));
        if ($tech === '') {
            $tech = '—';
        }
        $tp = (string) ($r['type_panne'] ?? '');
        $typeLabel = $typePanneLabels[$tp] ?? ($tp !== '' ? $tp : '—');
        $st = (string) ($r['statut'] ?? '');
        $statLabel = $statutLabels[$st] ?? $st;
        $do = !empty($r['date_ouverture']) ? date('d/m/Y', strtotime((string) $r['date_ouverture'])) : '—';
        $df = !empty($r['date_fermeture']) ? date('d/m/Y', strtotime((string) $r['date_fermeture'])) : '—';
        $duree = $r['temps_intervention_reel'] !== null && $r['temps_intervention_reel'] !== ''
            ? number_format((float) $r['temps_intervention_reel'], 2, ',', '')
            : '—';
        $cout = $r['cout_intervention'] !== null && $r['cout_intervention'] !== ''
            ? (float) $r['cout_intervention']
            : 0.0;
        $totalCout += $cout;
        $coutStr = $cout > 0 ? number_format($cout, 2, ',', ' ') : '—';

        $cells = [
            pdfTruncate((string) ($r['reference'] ?? ''), 18),
            pdfTruncate((string) ($r['raison_sociale'] ?? ''), 26),
            pdfTruncate($tech, 22),
            pdfTruncate($typeLabel, 14),
            pdfTruncate((string) ($r['priorite'] ?? ''), 12),
            pdfTruncate($statLabel, 14),
            $do,
            $df,
            $duree,
            $coutStr,
        ];

        $pdf->SetX(12);
        foreach ($cells as $i => $text) {
            $pdf->Cell($w[$i], 6, $text, 1, 0, $i >= 8 ? 'R' : 'L');
        }
        $pdf->Ln();
    }

    if ($pdf->GetY() > 175) {
        $pdf->AddPage();
    }

    $pdf->Ln(2);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(241, 245, 249);
    $pdf->SetX(12);
    $pdf->Cell(array_sum($w) - 36, 7, 'Totaux : ' . $nb . ' intervention(s)', 1, 0, 'L', true);
    $pdf->Cell(18, 7, '', 1, 0, 'C', true);
    $pdf->Cell(18, 7, number_format($totalCout, 2, ',', ' ') . ' €', 1, 1, 'R', true);

    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i') . ' par ' . $userDisplay, 0, 1, 'C');

    return $pdf->Output('', 'S');
}

function pdfTruncate(string $s, int $maxChars): string
{
    $s = preg_replace('/\s+/u', ' ', trim($s)) ?? '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        if (mb_strlen($s) <= $maxChars) {
            return $s;
        }
        return mb_substr($s, 0, max(1, $maxChars - 1)) . '…';
    }
    if (strlen($s) <= $maxChars) {
        return $s;
    }
    return substr($s, 0, $maxChars - 1) . '…';
}
