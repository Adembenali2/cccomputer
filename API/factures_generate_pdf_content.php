<?php
declare(strict_types=1);
/**
 * Reformate une description de facture (ancien format vers format professionnel)
 * Supprime les tirets et pipes, organise le texte
 */
function formatInvoiceDescription(string $desc): string
{
    $desc = trim(preg_replace("/\r\n|\r/", "\n", $desc));
    if ($desc === '') {
        return '';
    }
    // Remplacer " - " par " · "
    $desc = str_replace(' - ', ' · ', $desc);
    // Supprimer les parties redondantes "X copies x 0.XX€" ou "Dépassement: X copies x 0.XX€"
    $desc = preg_replace('/\s*\|\s*\d+\s*copies\s*x\s*[\d,]+€\s*/i', '', $desc);
    $desc = preg_replace('/\s*\|\s*Dépassement:\s*\d+\s*copies\s*x\s*[\d,]+€\s*/i', '', $desc);
    $desc = preg_replace('/\s*\d+\s*copies\s*x\s*[\d,]+€\s*/i', '', $desc);
    $desc = preg_replace('/\s*Dépassement:\s*\d+\s*copies\s*x\s*[\d,]+€\s*/i', '', $desc);
    // Reformater "Début: X (date) | Fin: Y (date)" en 3 lignes : Période, Compteur début, Compteur fin
    if (preg_match('/Début:\s*([\d\s]+)\s*\(([^)]+)\)\s*\|\s*Fin:\s*([\d\s]+)\s*\(([^)]+)\)/u', $desc, $m)) {
        $debut = preg_replace('/\s+/', '', trim($m[1]));
        $dateDebut = trim($m[2]);
        $fin = preg_replace('/\s+/', '', trim($m[3]));
        $dateFin = trim($m[4]);
        $replacement = "Période du {$dateDebut} au {$dateFin}\nCompteur début {$debut}\nCompteur fin {$fin}";
        $desc = preg_replace('/Début:\s*[\d\s]+\([^)]+\)\s*\|\s*Fin:\s*[\d\s]+\([^)]+\)\s*/u', "\n" . $replacement, $desc);
    }
    // Reformater "Période du X au Y · Compteur : A ? B" ou "A → B" (ancien format) en 3 lignes
    if (preg_match('/Période du ([^·]+) au ([^·]+)\s*·\s*Compteur\s*:\s*([\d\s]+)\s*[?→]\s*([\d\s]+)/u', $desc, $m)) {
        $dateDebut = trim($m[1]);
        $dateFin = trim($m[2]);
        $debut = preg_replace('/\s+/', '', trim($m[3]));
        $fin = preg_replace('/\s+/', '', trim($m[4]));
        $replacement = "Période du {$dateDebut} au {$dateFin}\nCompteur début {$debut}\nCompteur fin {$fin}";
        $desc = preg_replace('/Période du [^·]+ au [^·]+\s*·\s*Compteur\s*:\s*[\d\s]+\s*[?→]\s*[\d\s]+/u', $replacement, $desc);
    }
    // Nettoyer les pipes restants
    $desc = str_replace(' | ', "\n", $desc);
    return trim(preg_replace("/\n{2,}/", "\n", $desc));
}

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
    $stmt = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre ASC");
    $stmt->execute([':id' => $factureId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    $pdf->SetMargins(15, 12, 15);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetTextColor(30, 41, 59); // Slate-800
    $pdf->setCellPaddings(2, 2.5, 2, 2.5);
    $pdf->setCellHeightRatio(1.2);

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

    // Tableau — style professionnel
    $pdf->SetDrawColor(226, 232, 240); // Bordure gris clair
    $pdf->SetLineWidth(0.3);
    
    // Définition des largeurs (Total 180mm)
    $wDesc = 80; $wType = 25; $wQty = 20; $wPrix = 25; $wTotal = 30;
    
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(248, 250, 252); // Header gris très clair
    $pdf->SetTextColor(71, 85, 105); // Slate-600
    
    // Header
    $pdf->Cell($wDesc, 9, 'Description', 1, 0, 'L', true);
    $pdf->Cell($wType, 9, 'Type', 1, 0, 'C', true);
    $pdf->Cell($wQty, 9, 'Qté', 1, 0, 'C', true);
    $pdf->Cell($wPrix, 9, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell($wTotal, 9, 'Total HT', 1, 1, 'R', true);
    
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetTextColor(30, 41, 59);
    
    // Lignes
    foreach ($lignes as $ligne) {
        $xStart = 15;
        $yStart = $pdf->GetY();
        
        // Description — formatage professionnel (supprime tirets, pipes, redondances)
        $desc = formatInvoiceDescription((string)($ligne['description'] ?? ''));
        $lineHeight = 3.5; // Hauteur d'une ligne de texte
        
        // Calculer le nombre de lignes dans la description
        $nbLines = substr_count($desc, "\n") + 1;
        // Hauteur de cellule : au moins 7mm, ou hauteur calculée selon le nombre de lignes
        $cellHeight = max(7, ($lineHeight * $nbLines) + 1); // +1 pour un peu d'espace
        
        // MultiCell pour la description (permet les retours à la ligne)
        // Paramètres: width, height, text, border, align, fill, ln, x, y, reset, stretch
        // $ln=0 pour ne pas avancer la position après
        $pdf->MultiCell($wDesc, $lineHeight, $desc, 1, 'L', false, 0, $xStart, $yStart);
        
        // Positionner les autres colonnes à la même hauteur de départ
        $xAfterDesc = $xStart + $wDesc;
        $pdf->SetXY($xAfterDesc, $yStart);
        
        // Autres colonnes avec la même hauteur que la description
        $pdf->Cell($wType, $cellHeight, $ligne['type'], 1, 0, 'C');
        $pdf->Cell($wQty, $cellHeight, number_format((float)($ligne['quantite'] ?? 0), 2, ',', ' '), 1, 0, 'C');
        $prixUnit = (float)($ligne['prix_unitaire_ht'] ?? $ligne['prix_unitaire'] ?? 0);
        $pdf->Cell($wPrix, $cellHeight, number_format($prixUnit, 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell($wTotal, $cellHeight, number_format((float)($ligne['total_ht'] ?? 0), 2, ',', ' ') . ' €', 1, 1, 'R');
        
        // Ajuster la position Y pour la prochaine ligne (utiliser la hauteur calculée)
        $pdf->SetY($yStart + $cellHeight);
    }
    
    // Totaux — style professionnel
    $wMerged = $wDesc + $wType + $wQty + $wPrix;
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Total HT
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 7, 'Total HT', 1, 0, 'R');
    $pdf->Cell($wTotal, 7, number_format((float)($facture['montant_ht'] ?? 0), 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // TVA
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 7, 'TVA (20 %)', 1, 0, 'R');
    $pdf->Cell($wTotal, 7, number_format((float)($facture['tva'] ?? 0), 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // Total TTC — mise en gras
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetFillColor(248, 250, 252);
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 9, 'Total TTC', 1, 0, 'R', true);
    $pdf->Cell($wTotal, 9, number_format((float)($facture['montant_ttc'] ?? 0), 2, ',', ' ') . ' €', 1, 1, 'R', true);

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
