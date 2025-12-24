<?php
declare(strict_types=1);
/**
 * Fonction utilitaire pour générer le contenu PDF d'une facture
 * Peut être utilisée pour générer dans un répertoire spécifique (ex: /tmp)
 * 
 * @param PDO $pdo Connexion à la base de données
 * @param int $factureId ID de la facture
 * @param array $facture Données de la facture (depuis la DB)
 * @param array $client Données du client
 * @param string|null $outputDir Répertoire de sortie (si null, utilise uploads/factures/YYYY)
 * @return string Chemin absolu du fichier PDF généré
 * @throws RuntimeException En cas d'erreur
 */
function generateInvoicePdf(PDO $pdo, int $factureId, array $facture, array $client, ?string $outputDir = null): string
{
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Récupérer les lignes de facture
    $lignes = $pdo->query("SELECT * FROM facture_lignes WHERE id_facture = $factureId ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
    
    // Déterminer le répertoire de sortie
    if ($outputDir === null) {
        // Logique par défaut (comme generateFacturePDF)
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
        
        $uploadDir = $baseDir . '/uploads/factures/' . date('Y');
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }
        $outputDir = $uploadDir;
    } else {
        // Vérifier que le répertoire existe et est accessible en écriture
        if (!is_dir($outputDir)) {
            throw new RuntimeException("Le répertoire de sortie n'existe pas: {$outputDir}");
        }
        if (!is_writable($outputDir)) {
            throw new RuntimeException("Le répertoire de sortie n'est pas accessible en écriture: {$outputDir}");
        }
    }
    
    // Nom du fichier
    $filename = 'facture_' . $facture['numero'] . '_' . date('YmdHis') . '.pdf';
    $filepath = rtrim($outputDir, '/') . '/' . $filename;
    
    // Setup TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('System');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);

    // Logo
    $logoPath = __DIR__ . '/../assets/logos/logo1.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 10, 60, 0, 'PNG');
    }

    // Expéditeur
    $pdf->SetY(25);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'R');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'R');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'R');

    // Client
    $pdf->SetY(55);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1, 'L');
    $pdf->Cell(0, 5, $client['adresse'], 0, 1, 'L');
    $pdf->Cell(0, 5, trim(($client['code_postal'] ?? '') . ' ' . ($client['ville'] ?? '')), 0, 1, 'L');
    if (!empty($client['siret'])) {
        $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1, 'L');
    }

    // Date & Numéro
    $pdf->SetY(85);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(90, 6, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 0, 'L');
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Facture N° : ' . $facture['numero'], 0, 1, 'R');
    $pdf->Ln(5);

    // Tableau (même structure que l'original)
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    
    // Définition des largeurs (Total 180mm)
    $wDesc = 80; $wType = 25; $wQty = 20; $wPrix = 25; $wTotal = 30;
    
    $pdf->SetX(15);
    
    // Header
    $pdf->Cell($wDesc, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell($wType, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell($wQty, 8, 'Qté', 1, 0, 'C', true);
    $pdf->Cell($wPrix, 8, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell($wTotal, 8, 'Total HT', 1, 1, 'R', true);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Lignes
    foreach ($lignes as $ligne) {
        $pdf->SetX(15);
        $desc = mb_substr($ligne['description'], 0, 80);
        $pdf->Cell($wDesc, 7, $desc, 1, 0, 'L');
        $pdf->Cell($wType, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell($wQty, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell($wPrix, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell($wTotal, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // Totaux
    $wMerged = $wDesc + $wType + $wQty + $wPrix;
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Total HT
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 6, 'Total HT', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // TVA
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 6, 'TVA (20%)', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // Total TTC
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 8, 'Total TTC', 1, 0, 'R');
    $pdf->Cell($wTotal, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');

    // IBAN & Footer
    $pdf->Ln(5);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'IBAN : FR76 1027 8063 4700 0229 4870 249 - BIC : CMCIFR2A', 0, 1, 'L');

    // Footer
    $pdf->SetY(-35);
    $pdf->SetFont('helvetica', '', 8);
    
    $f1 = "Conditions de règlement : Toutes nos factures sont payables au comptant net sans escompte. Taux de pénalités de retard applicable : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €";
    $f2 = "Camson Group - 97, Boulevard Maurice Berteaux - SANNOIS SASU - Siret 947 820 585 00018 RCS Versailles TVA FR81947820585";
    $f3 = "www.camsongroup.fr - 01 55 99 00 69";
    
    $pdf->MultiCell(0, 4, $f1, 0, 'C');
    $pdf->Ln(1);
    $pdf->Cell(0, 4, $f2, 0, 1, 'C');
    $pdf->Cell(0, 4, $f3, 0, 1, 'C');

    // Sauvegarder le PDF
    try {
        $pdf->Output($filepath, 'F');
    } catch (Exception $e) {
        throw new RuntimeException('Erreur lors de la sauvegarde du PDF: ' . $e->getMessage());
    }
    
    // Vérifier que le fichier a bien été créé
    if (!file_exists($filepath) || !is_readable($filepath)) {
        throw new RuntimeException('Le fichier PDF créé n\'est pas accessible: ' . $filepath);
    }
    
    $fileSize = filesize($filepath);
    if ($fileSize === 0) {
        @unlink($filepath);
        throw new RuntimeException('Le fichier PDF créé est vide: ' . $filepath);
    }
    
    return $filepath;
}
