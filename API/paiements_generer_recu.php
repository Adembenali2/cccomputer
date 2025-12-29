<?php
/**
 * Fonction pour générer un reçu de paiement en PDF avec logo
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $paiementId ID du paiement
 * @return string Chemin relatif du fichier PDF généré
 * @throws RuntimeException En cas d'erreur
 */
function generateRecuPDF(PDO $pdo, int $paiementId): string {
    require_once __DIR__ . '/../vendor/autoload.php';

    // Récupérer les données du paiement
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.raison_sociale,
            c.adresse,
            c.code_postal,
            c.ville,
            c.siret,
            f.numero as facture_numero,
            f.date_facture as facture_date
        FROM paiements p
        LEFT JOIN clients c ON p.id_client = c.id
        LEFT JOIN factures f ON p.id_facture = f.id
        WHERE p.id = :paiement_id
        LIMIT 1
    ");
    $stmt->execute([':paiement_id' => $paiementId]);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$paiement) {
        throw new RuntimeException('Paiement introuvable');
    }

    // Setup Dossier - Compatible Railway
    $possibleBaseDirs = [];

    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && is_dir($docRoot)) {
        $possibleBaseDirs[] = $docRoot;
    }

    $projectDir = dirname(__DIR__);
    if (is_dir($projectDir)) {
        $possibleBaseDirs[] = $projectDir;
    }

    if (is_dir('/app')) {
        $possibleBaseDirs[] = '/app';
    }
    if (is_dir('/var/www/html')) {
        $possibleBaseDirs[] = '/var/www/html';
    }

    $baseDir = null;
    foreach ($possibleBaseDirs as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $baseDir = $dir;
            break;
        }
    }

    if (!$baseDir) {
        $baseDir = dirname(__DIR__);
    }

    $baseUploadDir = $baseDir . '/uploads';
    $recusDir = $baseUploadDir . '/recus';
    $uploadDir = $recusDir . '/' . date('Y');

    if (!is_dir($baseUploadDir)) {
        $created = @mkdir($baseUploadDir, 0755, true);
        if (!$created) {
            throw new RuntimeException('Impossible de créer le répertoire de base uploads: ' . $baseUploadDir);
        }
    }

    if (!is_dir($recusDir)) {
        $created = @mkdir($recusDir, 0755, true);
        if (!$created) {
            throw new RuntimeException('Impossible de créer le répertoire recus: ' . $recusDir);
        }
    }

    if (!is_dir($uploadDir)) {
        $created = @mkdir($uploadDir, 0755, true);
        if (!$created) {
            throw new RuntimeException('Impossible de créer le répertoire de stockage des reçus: ' . $uploadDir);
        }
    }

    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Le répertoire de stockage des reçus n\'est pas accessible en écriture: ' . $uploadDir);
    }

    // Nom du fichier
    $fileName = 'recu_' . $paiement['reference'] . '_' . date('Ymd') . '.pdf';
    $filePath = $uploadDir . '/' . $fileName;
    $relativePath = '/uploads/recus/' . date('Y') . '/' . $fileName;

    // Setup TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('System');
    $pdf->SetTitle('Reçu de paiement ' . $paiement['reference']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);

    $pdf->setCellPaddings(1, 1, 1, 1);
    $pdf->setCellHeightRatio(1.1);

    // LOGO
    $logoPath = __DIR__ . '/../assets/logos/logo1.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 10, 60, 0, 'PNG');
    }

    // EXPÉDITEUR
    $pdf->SetY(25);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'R');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'R');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'R');

    // TITRE
    $pdf->SetY(50);
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 8, 'REÇU DE PAIEMENT', 0, 1, 'C');
    $pdf->Ln(5);

    // CLIENT
    $pdf->SetY(70);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    if (!empty($paiement['raison_sociale'])) {
        $pdf->Cell(0, 5, $paiement['raison_sociale'], 0, 1, 'L');
    }
    if (!empty($paiement['adresse'])) {
        $pdf->Cell(0, 5, $paiement['adresse'], 0, 1, 'L');
    }
    if (!empty($paiement['code_postal']) || !empty($paiement['ville'])) {
        $pdf->Cell(0, 5, trim(($paiement['code_postal'] ?? '') . ' ' . ($paiement['ville'] ?? '')), 0, 1, 'L');
    }
    if (!empty($paiement['siret'])) {
        $pdf->Cell(0, 5, 'SIRET: ' . $paiement['siret'], 0, 1, 'L');
    }

    // INFORMATIONS DU PAIEMENT
    $pdf->SetY(110);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Référence du paiement : ' . $paiement['reference'], 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Date de paiement : ' . date('d/m/Y', strtotime($paiement['date_paiement'])), 0, 1, 'L');
    
    // Mode de paiement
    $modesPaiement = [
        'virement' => 'Virement bancaire',
        'cb' => 'Carte bancaire',
        'cheque' => 'Chèque',
        'especes' => 'Espèces',
        'autre' => 'Autre'
    ];
    $modePaiementLibelle = $modesPaiement[$paiement['mode_paiement']] ?? $paiement['mode_paiement'];
    $pdf->Cell(0, 5, 'Mode de paiement : ' . $modePaiementLibelle, 0, 1, 'L');
    
    // Facture associée
    if (!empty($paiement['facture_numero'])) {
        $pdf->Cell(0, 5, 'Facture concernée : ' . $paiement['facture_numero'], 0, 1, 'L');
        if (!empty($paiement['facture_date'])) {
            $pdf->Cell(0, 5, 'Date de facture : ' . date('d/m/Y', strtotime($paiement['facture_date'])), 0, 1, 'L');
        }
    }
    
    $pdf->Ln(5);

    // MONTANT
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell(0, 10, 'Montant reçu : ' . number_format($paiement['montant'], 2, ',', ' ') . ' €', 1, 1, 'C', true);
    $pdf->Ln(5);

    // COMMENTAIRE
    if (!empty($paiement['commentaire'])) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Commentaire :', 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, $paiement['commentaire'], 0, 'L');
        $pdf->Ln(3);
    }

    // FOOTER
    $pdf->SetY(-40);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Ce document certifie que le paiement a bien été reçu.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Document généré le ' . date('d/m/Y à H:i'), 0, 1, 'C');

    // Sauvegarder le PDF
    $pdf->Output($filePath, 'F');

    return $relativePath;
}

