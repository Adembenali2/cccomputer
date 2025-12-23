<?php
/**
 * API pour générer une facture et son PDF
 * Version : Design ajusté (Décalage Logo/SSS, Numéro à droite, Tableau centré)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Vérifier les tables
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'factures'");
        if ($stmt->rowCount() === 0) jsonResponse(['ok' => false, 'error' => 'Table factures manquante'], 500);
        $stmt = $pdo->query("SHOW TABLES LIKE 'facture_lignes'");
        if ($stmt->rowCount() === 0) jsonResponse(['ok' => false, 'error' => 'Table facture_lignes manquante'], 500);
    } catch (PDOException $e) {
        jsonResponse(['ok' => false, 'error' => 'Erreur DB: ' . $e->getMessage()], 500);
    }
    
    // Récupérer et valider les données
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['factureClient']) || empty($data['factureDate']) || empty($data['lignes'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
    }
    
    // Récupérer Client
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$data['factureClient']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
    
    // Générer Numéro
    $numeroFacture = generateFactureNumber($pdo);
    
    // Calculs
    $montantHT = 0;
    foreach ($data['lignes'] as $ligne) $montantHT += (float)($ligne['total_ht'] ?? 0);
    $tva = $montantHT * 0.20;
    $montantTTC = $montantHT + $tva;
    
    $pdo->beginTransaction();
    
    try {
        // Insert Facture
        $stmt = $pdo->prepare("INSERT INTO factures (id_client, numero, date_facture, type, montant_ht, tva, montant_ttc, statut, created_by) VALUES (:id_client, :numero, :date_facture, :type, :montant_ht, :tva, :montant_ttc, 'brouillon', :created_by)");
        $stmt->execute([
            ':id_client' => (int)$data['factureClient'],
            ':numero' => $numeroFacture,
            ':date_facture' => $data['factureDate'],
            ':type' => $data['factureType'],
            ':montant_ht' => $montantHT,
            ':tva' => $tva,
            ':montant_ttc' => $montantTTC,
            ':created_by' => currentUserId()
        ]);
        $factureId = $pdo->lastInsertId();
        
        // Insert Lignes
        $stmtLigne = $pdo->prepare("INSERT INTO facture_lignes (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre) VALUES (:id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre)");
        foreach ($data['lignes'] as $i => $l) {
            $stmtLigne->execute([
                ':id_facture' => $factureId,
                ':description' => $l['description'],
                ':type' => $l['type'],
                ':quantite' => $l['quantite'],
                ':prix_unitaire_ht' => $l['prix_unitaire'],
                ':total_ht' => $l['total_ht'],
                ':ordre' => $i
            ]);
        }
        
        // Générer PDF
        $pdfPath = generateFacturePDF($pdo, $factureId, $client, $data);
        
        // Update PDF Path
        $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ?, statut = 'envoyee' WHERE id = ?")->execute([$pdfPath, $factureId]);
        
        $pdo->commit();
        jsonResponse(['ok' => true, 'facture_id' => $factureId, 'numero' => $numeroFacture, 'pdf_url' => $pdfPath]);
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log($e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}

function generateFactureNumber($pdo) {
    $year = date('Y');
    $count = $pdo->query("SELECT COUNT(*) FROM factures WHERE numero LIKE 'FAC-$year-%'")->fetchColumn();
    return sprintf("FAC-%s-%04d", $year, $count + 1);
}

/**
 * FONCTION DE GÉNÉRATION PDF MISE À JOUR
 */
function generateFacturePDF(PDO $pdo, int $factureId, array $client, array $data): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Données
    $lignes = $pdo->query("SELECT * FROM facture_lignes WHERE id_facture = $factureId ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $facture = $pdo->query("SELECT * FROM factures WHERE id = $factureId")->fetch(PDO::FETCH_ASSOC);

    // Setup Dossier
    $uploadDir = __DIR__ . '/../uploads/factures/' . date('Y');
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    
    // Setup TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('System');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 10, 15); // Marges Gauche/Droite de 15mm
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);

    // ==========================================
    // 1. LOGO (Haut Gauche - Y=10)
    // ==========================================
    $logoPath = __DIR__ . '/../assets/logos/logo1.png';
    if (file_exists($logoPath)) {
        // Image à X=15, Y=10
        $pdf->Image($logoPath, 15, 10, 40, 0, 'PNG');
    }

    // ==========================================
    // 2. EXPÉDITEUR (Haut Droite - DÉCALÉ Y=25)
    // ==========================================
    // On force le curseur plus bas pour le texte "SSS"
    $pdf->SetY(25); 
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'R');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'R');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'R');

    // ==========================================
    // 3. CLIENT (Gauche - Y=50)
    // ==========================================
    $pdf->SetY(50); // Position verticale fixe pour le client
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1, 'L');
    $pdf->Cell(0, 5, $client['adresse'], 0, 1, 'L');
    $pdf->Cell(0, 5, trim(($client['code_postal'] ?? '') . ' ' . ($client['ville'] ?? '')), 0, 1, 'L');
    if (!empty($client['siret'])) $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1, 'L');

    // ==========================================
    // 4. DATE (Gauche) et NUMÉRO (Strictement Droite)
    // ==========================================
    $pdf->SetY(80); // On descend avant le tableau
    $pdf->SetFont('helvetica', '', 11);
    
    // Date à gauche
    $pdf->Cell(90, 6, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 0, 'L');
    
    // Numéro STRICTEMENT à droite
    $pdf->SetFont('helvetica', 'B', 11);
    // Cell(0) indique "jusqu'à la marge de droite", align 'R' plaque le texte à droite
    $pdf->Cell(0, 6, 'Facture N° : ' . $facture['numero'], 0, 1, 'R');

    $pdf->Ln(5); // Espace avant tableau

    // ==========================================
    // 5. TABLEAU (Centré)
    // ==========================================
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    
    // Largeurs (Total 180mm)
    $wDesc = 80; $wType = 25; $wQty = 20; $wPrix = 25; $wTotal = 30;
    
    // Pour centrer sur A4 (210mm) avec un tableau de 180mm, X doit commencer à 15mm.
    // SetMargins(15...) le fait déjà, mais on force X pour être sûr.
    $pdf->SetX(15);
    
    $pdf->Cell($wDesc, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell($wType, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell($wQty, 8, 'Qté', 1, 0, 'C', true);
    $pdf->Cell($wPrix, 8, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell($wTotal, 8, 'Total HT', 1, 1, 'R', true);
    
    $pdf->SetFont('helvetica', '', 9);
    
    foreach ($lignes as $ligne) {
        $pdf->SetX(15); // Re-centrage à chaque ligne
        $desc = mb_substr($ligne['description'], 0, 60);
        
        $pdf->Cell($wDesc, 7, $desc, 1, 0, 'L');
        $pdf->Cell($wType, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell($wQty, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell($wPrix, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell($wTotal, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // ==========================================
    // 6. TOTAUX (Alignés sous les colonnes de droite)
    // ==========================================
    $pdf->SetFont('helvetica', '', 10);
    // Offset X = Marge(15) + Desc(80) + Type(25) + Qty(20) + Prix(25) = 165
    $xTotaux = 165; 
    
    $pdf->SetX(15); // Reset pour être propre, puis on utilise Cell pour pousser ou SetX direct
    
    // Total HT
    $pdf->SetX($xTotaux); // Positionnement précis
    $pdf->Cell(25, 6, 'Total HT', 1, 0, 'R'); // Largeur match "Prix unit" (approx) ou juste label
    $pdf->Cell($wTotal, 6, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // TVA
    $pdf->SetX($xTotaux);
    $pdf->Cell(25, 6, 'TVA (20%)', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // TTC
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetX($xTotaux);
    $pdf->Cell(25, 8, 'Total TTC', 1, 0, 'R');
    $pdf->Cell($wTotal, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');

    // ==========================================
    // 7. IBAN
    // ==========================================
    $pdf->Ln(5);
    $pdf->SetX(15); // Retour à la marge gauche
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'IBAN : FR76 1027 8063 4700 0229 4870 249 - BIC : CMCIFR2A', 0, 1, 'L');

    // ==========================================
    // 8. FOOTER (Fixe en bas)
    // ==========================================
    $pdf->SetY(-35); 
    $pdf->SetFont('helvetica', '', 8);
    
    $f1 = "Conditions de règlement : Toutes nos factures sont payables au comptant net sans escompte. Taux de pénalités de retard applicable : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €";
    $f2 = "Camson Group - 97, Boulevard Maurice Berteaux - SANNOIS SASU - Siret 947 820 585 00018 RCS Versailles TVA FR81947820585";
    $f3 = "www.camsongroup.fr - 01 55 99 00 69";
    
    $pdf->MultiCell(0, 4, $f1, 0, 'C');
    $pdf->Ln(1);
    $pdf->Cell(0, 4, $f2, 0, 1, 'C');
    $pdf->Cell(0, 4, $f3, 0, 1, 'C');

    // Sauvegarde
    $filename = 'facture_' . $facture['numero'] . '_' . date('YmdHis') . '.pdf';
    $pdf->Output($uploadDir . '/' . $filename, 'F');
    return '/uploads/factures/' . date('Y') . '/' . $filename;
}
?>