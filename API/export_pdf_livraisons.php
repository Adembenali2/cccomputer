<?php

declare(strict_types=1);

/**
 * Export PDF — Rapport livraisons (période + filtre statut optionnel)
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

$allowedStatuts = ['planifiee', 'en_cours', 'livree', 'annulee'];
if ($statutFilter !== '' && !in_array($statutFilter, $allowedStatuts, true)) {
    jsonResponse(['ok' => false, 'error' => 'Valeur statut invalide'], 400);
}

$pdo = getPdoOrFail();

$sql = 'SELECT l.*, c.raison_sociale, u.nom AS livreur_nom, u.prenom AS livreur_prenom
    FROM livraisons l
    LEFT JOIN clients c ON l.id_client = c.id
    LEFT JOIN utilisateurs u ON l.id_livreur = u.id
    WHERE l.date_prevue BETWEEN :date_debut AND :date_fin';
$params = [':date_debut' => $dateDebut, ':date_fin' => $dateFin];

if ($statutFilter !== '') {
    $sql .= ' AND l.statut = :statut';
    $params[':statut'] = $statutFilter;
}

$sql .= ' ORDER BY l.date_prevue DESC';

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('export_pdf_livraisons: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur lors de la lecture des données'], 500);
}

$statutLabels = [
    'planifiee' => 'Planifiée',
    'en_cours' => 'En cours',
    'livree' => 'Livrée',
    'annulee' => 'Annulée',
];

$pdfStr = buildLivraisonsPdf(
    $rows,
    $dateDebut,
    $dateFin,
    $statutFilter,
    $statutLabels,
    trim(($_SESSION['user_prenom'] ?? '') . ' ' . ($_SESSION['user_nom'] ?? '')) ?: 'Utilisateur'
);

while (ob_get_level() > 0) {
    ob_end_clean();
}

$fname = 'rapport_livraisons_' . $dateDebut . '_' . $dateFin . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace(['"', "\r", "\n"], '', $fname) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

echo $pdfStr;

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, string> $statutLabels
 */
function buildLivraisonsPdf(
    array $rows,
    string $dateDebut,
    string $dateFin,
    string $statutFilter,
    array $statutLabels,
    string $userDisplay
): string {
    $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('CCComputer');
    $pdf->SetTitle('Rapport livraisons');
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
    $pdf->Cell(0, 8, 'Rapport livraisons', 0, 1, 'R');
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

    $w = [22, 32, 26, 38, 28, 10, 20, 20, 22];
    $headers = ['Référence', 'Client', 'Livreur', 'Objet', 'Produit', 'Qté', 'Date prév.', 'Date réelle', 'Statut'];

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

    foreach ($rows as $r) {
        if ($pdf->GetY() > 185) {
            $pdf->AddPage();
            $renderHeader();
        }

        $livreur = trim(($r['livreur_prenom'] ?? '') . ' ' . ($r['livreur_nom'] ?? ''));
        if ($livreur === '') {
            $livreur = '—';
        }

        $ptype = (string) ($r['product_type'] ?? '');
        $pid = $r['product_id'] ?? null;
        $produit = '—';
        if ($ptype !== '') {
            $produit = $ptype . ($pid !== null && $pid !== '' ? ' #' . $pid : '');
        }

        $qty = $r['product_qty'] !== null && $r['product_qty'] !== '' ? (string) (int) $r['product_qty'] : '—';
        $dp = !empty($r['date_prevue']) ? date('d/m/Y', strtotime((string) $r['date_prevue'])) : '—';
        $dr = !empty($r['date_reelle']) ? date('d/m/Y', strtotime((string) $r['date_reelle'])) : '—';
        $st = (string) ($r['statut'] ?? '');
        $statLabel = $statutLabels[$st] ?? $st;

        $cells = [
            pdfTruncateLiv((string) ($r['reference'] ?? ''), 16),
            pdfTruncateLiv((string) ($r['raison_sociale'] ?? ''), 24),
            pdfTruncateLiv($livreur, 20),
            pdfTruncateLiv((string) ($r['objet'] ?? ''), 30),
            pdfTruncateLiv($produit, 22),
            $qty,
            $dp,
            $dr,
            pdfTruncateLiv($statLabel, 14),
        ];

        $pdf->SetX(12);
        foreach ($cells as $i => $text) {
            $align = ($i === 5 || $i === 6 || $i === 7) ? 'C' : 'L';
            $pdf->Cell($w[$i], 6, $text, 1, 0, $align);
        }
        $pdf->Ln();
    }

    $pdf->Ln(4);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 116, 139);
    $pdf->Cell(0, 5, 'Généré le ' . date('d/m/Y à H:i') . ' par ' . $userDisplay . ' — ' . count($rows) . ' ligne(s)', 0, 1, 'C');

    return $pdf->Output('', 'S');
}

function pdfTruncateLiv(string $s, int $maxChars): string
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
