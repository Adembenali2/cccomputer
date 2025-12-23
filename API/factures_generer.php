<?php
/**
 * API pour générer une facture et son PDF
 * Mise à jour : Layout spécifique SSS / ALR / Footer bas de page
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Vérifier que les tables existent
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'factures'");
        if ($stmt->rowCount() === 0) {
            jsonResponse([
                'ok' => false, 
                'error' => 'La table "factures" n\'existe pas. Veuillez exécuter le script de migration.'
            ], 500);
        }
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'facture_lignes'");
        if ($stmt->rowCount() === 0) {
            jsonResponse([
                'ok' => false, 
                'error' => 'La table "facture_lignes" n\'existe pas. Veuillez exécuter le script de migration.'
            ], 500);
        }
    } catch (PDOException $e) {
        error_log('factures_generer.php Erreur vérification tables: ' . $e->getMessage());
        jsonResponse([
            'ok' => false, 
            'error' => 'Erreur de connexion à la base de données.'
        ], 500);
    }
    
    // Récupérer les données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $jsonError = json_last_error_msg();
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides: ' . $jsonError], 400);
    }
    
    // Validation des champs obligatoires
    if (empty($data['factureClient']) || empty($data['factureDate']) || empty($data['factureType'])) {
        jsonResponse(['ok' => false, 'error' => 'Champs obligatoires manquants'], 400);
    }
    
    if (empty($data['lignes']) || !is_array($data['lignes']) || count($data['lignes']) === 0) {
        jsonResponse(['ok' => false, 'error' => 'Au moins une ligne de facture est requise'], 400);
    }
    
    // Vérifier que le client existe
    $stmt = $pdo->prepare("SELECT id, numero_client, raison_sociale, adresse, code_postal, ville, siret, numero_tva, email FROM clients WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => (int)$data['factureClient']]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
    }
    
    // Générer le numéro de facture
    $numeroFacture = generateFactureNumber($pdo);
    
    // Calculer les totaux
    $montantHT = 0;
    foreach ($data['lignes'] as $ligne) {
        $montantHT += (float)($ligne['total_ht'] ?? 0);
    }
    
    $tauxTVA = 20;
    $tva = $montantHT * ($tauxTVA / 100);
    $montantTTC = $montantHT + $tva;
    
    // Transaction
    $pdo->beginTransaction();
    
    try {
        // Insérer la facture
        $stmt = $pdo->prepare("
            INSERT INTO factures 
            (id_client, numero, date_facture, date_debut_periode, date_fin_periode, type, 
             montant_ht, tva, montant_ttc, statut, created_by)
            VALUES 
            (:id_client, :numero, :date_facture, :date_debut_periode, :date_fin_periode, :type,
             :montant_ht, :tva, :montant_ttc, 'brouillon', :created_by)
        ");
        
        $stmt->execute([
            ':id_client' => (int)$data['factureClient'],
            ':numero' => $numeroFacture,
            ':date_facture' => $data['factureDate'],
            ':date_debut_periode' => !empty($data['factureDateDebut']) ? $data['factureDateDebut'] : null,
            ':date_fin_periode' => !empty($data['factureDateFin']) ? $data['factureDateFin'] : null,
            ':type' => $data['factureType'],
            ':montant_ht' => $montantHT,
            ':tva' => $tva,
            ':montant_ttc' => $montantTTC,
            ':created_by' => currentUserId()
        ]);
        
        $factureId = $pdo->lastInsertId();
        
        // Insérer les lignes
        $stmtLigne = $pdo->prepare("
            INSERT INTO facture_lignes 
            (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre)
            VALUES 
            (:id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre)
        ");
        
        foreach ($data['lignes'] as $index => $ligne) {
            $stmtLigne->execute([
                ':id_facture' => $factureId,
                ':description' => trim($ligne['description']),
                ':type' => $ligne['type'],
                ':quantite' => (float)($ligne['quantite'] ?? 0),
                ':prix_unitaire_ht' => (float)($ligne['prix_unitaire'] ?? 0),
                ':total_ht' => (float)($ligne['total_ht'] ?? 0),
                ':ordre' => $index
            ]);
        }
        
        // Générer le PDF
        $pdfPath = generateFacturePDF($pdo, $factureId, $client, $data);
        
        // Update facture path
        $stmt = $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = :pdf_path, statut = 'envoyee' WHERE id = :id");
        $stmt->execute([
            ':pdf_path' => $pdfPath,
            ':id' => $factureId
        ]);
        
        $pdo->commit();
        
        jsonResponse([
            'ok' => true,
            'facture_id' => $factureId,
            'numero' => $numeroFacture,
            'pdf_url' => $pdfPath
        ]);
        
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    
} catch (Throwable $e) {
    error_log('Erreur factures_generer.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur: ' . $e->getMessage()], 500);
}

/**
 * Génère un numéro de facture unique
 */
function generateFactureNumber(PDO $pdo): string {
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM factures WHERE numero LIKE :pattern");
    $stmt->execute([':pattern' => "FAC-{$year}-%"]);
    $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    return sprintf("FAC-%s-%04d", $year, $count + 1);
}

/**
 * Génère le PDF avec le design spécifique demandé :
 * - Expéditeur à Droite
 * - Client à Gauche en dessous
 * - Date/Numéro au dessus du tableau
 * - Tableau centré
 * - Footer tout en bas
 * - Tout sur une seule page
 */
function generateFacturePDF(PDO $pdo, int $factureId, array $client, array $data): string {
    // 1. Chargement TCPDF
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorAutoload)) {
        throw new RuntimeException('vendor/autoload.php introuvable.');
    }
    require_once $vendorAutoload;
    
    // 2. Données
    $stmt = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre ASC");
    $stmt->execute([':id' => $factureId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $pdo->prepare("SELECT * FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3. Dossier
    $uploadDir = __DIR__ . '/../uploads/factures/' . date('Y');
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
    
    // 4. Config TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('System');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    
    // Suppression Header/Footer auto pour gérer manuellement le layout "Une seule page"
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Marges réduites pour tout faire tenir
    $pdf->SetMargins(15, 10, 15); 
    $pdf->SetAutoPageBreak(false); // IMPORTANT: Désactivé pour contrôler le footer manuellement
    
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);

    // ==========================================
    // SECTION 1 : EXPÉDITEUR (Aligné à DROITE)
    // ==========================================
    $pdf->SetFont('helvetica', '', 10);
    // On utilise Cell(0, ...) avec align 'R' pour coller à droite
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'R');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'R');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'R');

    // ==========================================
    // SECTION 2 : CLIENT (Aligné à GAUCHE, en dessous)
    // ==========================================
    $pdf->Ln(10); // Espace vertical après l'expéditeur
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1, 'L');
    $pdf->Cell(0, 5, $client['adresse'], 0, 1, 'L');
    $pdf->Cell(0, 5, trim(($client['code_postal'] ?? '') . ' ' . ($client['ville'] ?? '')), 0, 1, 'L');
    if (!empty($client['siret'])) {
        $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1, 'L');
    }

    // ==========================================
    // SECTION 3 : DATE ET FACTURE (Juste au dessus du tableau)
    // ==========================================
    $pdf->Ln(8); // Espace après le client
    
    // On met Date et Numéro sur la même ligne ou l'un sous l'autre
    $pdf->SetFont('helvetica', '', 11);
    $dateStr = date('d/m/Y', strtotime($facture['date_facture']));
    
    // Texte complet : "Date : XX/XX/XXXX   Facture N° : FAC-..."
    // On peut utiliser des tabulations ou des cellules
    $pdf->Cell(50, 6, 'Date : ' . $dateStr, 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Facture N° : ' . $facture['numero'], 0, 1, 'L'); 
    // Note: Le prompt demandait "un peu en bas", ici c'est juste avant le tableau.

    $pdf->Ln(2); // Petit espace avant le tableau

    // ==========================================
    // SECTION 4 : TABLEAU (Centré / Pleine largeur)
    // ==========================================
    
    // Entêtes
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    
    // Largeurs col: Total 180 (15+15 marges = 30, A4=210. Reste 180)
    $wDesc = 80;
    $wType = 25;
    $wQty = 20;
    $wPrix = 25;
    $wTotal = 30;
    
    $pdf->Cell($wDesc, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell($wType, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell($wQty, 8, 'Qté', 1, 0, 'C', true);
    $pdf->Cell($wPrix, 8, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell($wTotal, 8, 'Total HT', 1, 1, 'R', true);
    
    $pdf->SetFont('helvetica', '', 9);
    
    // Lignes
    foreach ($lignes as $ligne) {
        $description = mb_substr($ligne['description'], 0, 60); // Tronquer si trop long
        
        $pdf->Cell($wDesc, 7, $description, 1, 0, 'L');
        $pdf->Cell($wType, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell($wQty, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell($wPrix, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell($wTotal, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // Totaux (Alignés à droite du tableau)
    $pdf->SetFont('helvetica', '', 10);
    $offsetLabels = $wDesc + $wType + $wQty + $wPrix; // Pour aligner sous la colonne prix
    
    $pdf->Cell($offsetLabels, 6, 'Total HT', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->Cell($offsetLabels, 6, 'TVA (20%)', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell($offsetLabels, 8, 'Total TTC', 1, 0, 'R');
    $pdf->Cell($wTotal, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');

    // ==========================================
    // SECTION 5 : IBAN (Juste sous le tableau)
    // ==========================================
    $pdf->Ln(5); // Petit espace demandé "mais pas trop"
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'IBAN : FR76 1027 8063 4700 0229 4870 249 - BIC : CMCIFR2A', 0, 1, 'L');

    // ==========================================
    // SECTION 6 : FOOTER (Tout en bas de la feuille)
    // ==========================================
    
    // On se positionne à 35mm du bas de la page (A4 = 297mm de haut)
    // -35mm permet d'avoir assez de place pour le texte légal
    $pdf->SetY(-35); 
    
    $pdf->SetFont('helvetica', '', 8); // Police plus petite pour le footer
    
    // Texte légal concaténé
    $footerLigne1 = "Conditions de règlement : Toutes nos factures sont payables au comptant net sans escompte. Taux de pénalités de retard applicable : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €";
    $footerLigne2 = "Camson Group - 97, Boulevard Maurice Berteaux - SANNOIS SASU - Siret 947 820 585 00018 RCS Versailles TVA FR81947820585";
    $footerLigne3 = "www.camsongroup.fr - 01 55 99 00 69";
    
    // MultiCell pour gérer le retour à la ligne si la phrase est trop longue, centré ('C')
    $pdf->MultiCell(0, 4, $footerLigne1, 0, 'C', false, 1);
    $pdf->Ln(1);
    $pdf->Cell(0, 4, $footerLigne2, 0, 1, 'C');
    $pdf->Cell(0, 4, $footerLigne3, 0, 1, 'C');

    // ==========================================
    // SAUVEGARDE
    // ==========================================
    $filename = 'facture_' . $facture['numero'] . '_' . date('YmdHis') . '.pdf';
    $filepath = $uploadDir . '/' . $filename;
    
    $pdf->Output($filepath, 'F');
    
    return '/uploads/factures/' . date('Y') . '/' . $filename;
}
?>