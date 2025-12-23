<?php
/**
 * API pour générer une facture et son PDF
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
                'error' => 'La table "factures" n\'existe pas. Veuillez exécuter le script de migration : /sql/run_migration_factures.php'
            ], 500);
        }
        
        $stmt = $pdo->query("SHOW TABLES LIKE 'facture_lignes'");
        if ($stmt->rowCount() === 0) {
            jsonResponse([
                'ok' => false, 
                'error' => 'La table "facture_lignes" n\'existe pas. Veuillez exécuter le script de migration : /sql/run_migration_factures.php'
            ], 500);
        }
    } catch (PDOException $e) {
        error_log('factures_generer.php Erreur vérification tables: ' . $e->getMessage());
        jsonResponse([
            'ok' => false, 
            'error' => 'Erreur de connexion à la base de données. Vérifiez que les tables factures et facture_lignes existent.'
        ], 500);
    }
    
    // Récupérer les données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        $jsonError = json_last_error_msg();
        error_log('factures_generer.php JSON decode error: ' . $jsonError);
        error_log('factures_generer.php Raw input: ' . substr($input, 0, 500));
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides: ' . $jsonError], 400);
    }
    
    // Log des données reçues pour débogage
    error_log('factures_generer.php Données reçues: ' . print_r($data, true));
    
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
    
    // Calculer les totaux depuis les lignes
    $montantHT = 0;
    foreach ($data['lignes'] as $ligne) {
        $montantHT += (float)($ligne['total_ht'] ?? 0);
    }
    
    // Calculer la TVA (20%)
    $tauxTVA = 20;
    $tva = $montantHT * ($tauxTVA / 100);
    $montantTTC = $montantHT + $tva;
    
    // Démarrer une transaction
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
        
        // Insérer les lignes de facture
        $stmtLigne = $pdo->prepare("
            INSERT INTO facture_lignes 
            (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre)
            VALUES 
            (:id_facture, :description, :type, :quantite, :prix_unitaire_ht, :total_ht, :ordre)
        ");
        
        foreach ($data['lignes'] as $index => $ligne) {
            // Vérifier que toutes les données nécessaires sont présentes
            if (empty($ligne['description']) || empty($ligne['type'])) {
                throw new InvalidArgumentException("Ligne $index incomplète : description et type sont requis");
            }
            
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
        
        // Mettre à jour la facture avec le chemin du PDF
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
        error_log('factures_generer.php transaction error: ' . $e->getMessage());
        error_log('factures_generer.php transaction trace: ' . $e->getTraceAsString());
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log('factures_generer.php SQL error: ' . $e->getMessage());
    error_log('factures_generer.php SQL error info: ' . print_r($e->errorInfo ?? [], true));
    error_log('factures_generer.php SQL trace: ' . $e->getTraceAsString());
    
    // Message d'erreur plus détaillé pour le débogage
    $errorMsg = 'Erreur de base de données';
    if (isset($e->errorInfo[2])) {
        $errorMsg .= ': ' . $e->errorInfo[2];
    } else {
        $errorMsg .= ': ' . $e->getMessage();
    }
    
    jsonResponse(['ok' => false, 'error' => $errorMsg], 500);
} catch (Throwable $e) {
    error_log('factures_generer.php error: ' . $e->getMessage());
    error_log('factures_generer.php error class: ' . get_class($e));
    error_log('factures_generer.php trace: ' . $e->getTraceAsString());
    
    // Message d'erreur plus informatif
    $errorMsg = 'Erreur : ' . $e->getMessage();
    if ($e instanceof RuntimeException) {
        $errorMsg = $e->getMessage();
    }
    
    jsonResponse(['ok' => false, 'error' => $errorMsg], 500);
}

/**
 * Génère un numéro de facture unique
 */
function generateFactureNumber(PDO $pdo): string {
    // Vérifier que la table existe
    $stmt = $pdo->query("SHOW TABLES LIKE 'factures'");
    if ($stmt->rowCount() === 0) {
        throw new RuntimeException('La table "factures" n\'existe pas dans la base de données. Veuillez exécuter le script SQL de création des tables.');
    }
    
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM factures WHERE numero LIKE :pattern");
    $stmt->execute([':pattern' => "FAC-{$year}-%"]);
    $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $numero = sprintf("FAC-%s-%04d", $year, $count + 1);
    return $numero;
}

/**
 * Génère le PDF de la facture avec le nouveau design demandé
 */
function generateFacturePDF(PDO $pdo, int $factureId, array $client, array $data): string {
    // Charger TCPDF via Composer autoload
    $vendorAutoload = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorAutoload)) {
        throw new RuntimeException('Le fichier vendor/autoload.php est introuvable. Exécutez "composer install".');
    }
    require_once $vendorAutoload;
    
    // Vérifier que TCPDF est disponible
    if (!class_exists('TCPDF')) {
        throw new RuntimeException('La classe TCPDF n\'est pas disponible. Vérifiez que tecnickcom/tcpdf est installé via Composer.');
    }
    
    // Récupérer les lignes de facture
    $stmt = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre ASC");
    $stmt->execute([':id' => $factureId]);
    $lignes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Récupérer la facture complète
    $stmt = $pdo->prepare("SELECT * FROM factures WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        throw new RuntimeException('Facture introuvable après insertion');
    }
    
    // Créer le répertoire de stockage si nécessaire
    $uploadDir = __DIR__ . '/../uploads/factures/' . date('Y');
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0755, true);
    }
    
    // Vérifier que le répertoire est accessible en écriture
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Le répertoire de stockage des factures n\'est pas accessible en écriture: ' . $uploadDir);
    }
    
    // Créer le PDF avec TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Informations du document
    $pdf->SetCreator('CC Computer');
    $pdf->SetAuthor('SSS International');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    $pdf->SetSubject('Facture');
    
    // Supprimer les en-têtes et pieds de page par défaut pour contrôle total
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Marges (Gauche, Haut, Droite)
    $pdf->SetMargins(15, 15, 15);
    $pdf->SetAutoPageBreak(true, 40); // Grande marge en bas pour le footer
    
    // Ajouter une page
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);
    
    // --- 1. LOGO (Haut Gauche) ---
    $logoPath = __DIR__ . '/../assets/logos/logo.png';
    $currentY = 15;
    
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, $currentY, 40, 0, '', '', '', false, 300, '', false, false, 0);
        // On estime la hauteur du logo + un espace
        $currentY += 35; 
    } else {
        $currentY += 10;
    }
    
    // --- 2. ADRESSE "SSS International" (Sous le logo) ---
    $pdf->SetY($currentY);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'L');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'L');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'L');
    
    $pdf->Ln(10); // Espace de séparation
    
    // --- 3. DATE ET NUMÉRO DE FACTURE (Même ligne) ---
    // Date à gauche, Numéro à droite
    $pdf->SetFont('helvetica', '', 11);
    
    // Cellule date (gauche)
    $pdf->Cell(90, 8, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 0, 'L');
    
    // Cellule numéro (droite), en gras
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(90, 8, 'Facture N° : ' . $facture['numero'], 0, 1, 'R');
    
    $pdf->Ln(5);
    
    // --- 4. INFORMATIONS CLIENT ---
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1, 'L');
    if (!empty($client['adresse'])) {
        $pdf->Cell(0, 5, $client['adresse'], 0, 1, 'L');
    }
    if (!empty($client['code_postal']) || !empty($client['ville'])) {
        $pdf->Cell(0, 5, trim(($client['code_postal'] ?? '') . ' ' . ($client['ville'] ?? '')), 0, 1, 'L');
    }
    if (!empty($client['siret'])) {
        $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1, 'L');
    }
    
    $pdf->Ln(10); // Espace avant tableau
    
    // --- 5. TABLEAU DE CONSOMMATION ---
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240); // Gris clair pour l'entête
    
    // En-têtes du tableau
    $pdf->Cell(80, 8, 'Description', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'Type', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Qté', 1, 0, 'C', true);
    $pdf->Cell(25, 8, 'Prix unit.', 1, 0, 'R', true);
    $pdf->Cell(30, 8, 'Total HT', 1, 1, 'R', true);
    
    $pdf->SetFillColor(255, 255, 255);
    $pdf->SetFont('helvetica', '', 9);
    
    // Lignes du tableau
    foreach ($lignes as $ligne) {
        // Gérer les descriptions longues
        $description = mb_substr($ligne['description'], 0, 50);
        if (mb_strlen($ligne['description']) > 50) {
            $description .= '...';
        }
        
        $pdf->Cell(80, 7, $description, 1, 0, 'L');
        $pdf->Cell(30, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell(25, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell(25, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell(30, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // Totaux
    $pdf->Ln(2);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(160, 6, 'Total HT:', 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->Cell(160, 6, 'TVA (20%):', 0, 0, 'R');
    $pdf->Cell(30, 6, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(160, 8, 'Total TTC:', 0, 0, 'R');
    $pdf->Cell(30, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // --- 6. IBAN (Sous le tableau) ---
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'IBAN : FR76 1027 8063 4700 0229 4870 249 - BIC : CMCIFR2A', 0, 1, 'L');
    
    // --- 7. PIED DE PAGE (Tout en bas, centré, écriture mince) ---
    // Positionner à 35mm du bas de la page
    $pdf->SetY(-35); 
    
    $pdf->SetFont('helvetica', '', 8); // Police taille 8 pour l'effet "mince"
    
    $footerText1 = "Conditions de règlement : Toutes nos factures sont payables au comptant net sans escompte. Taux de pénalités de retard applicable : 3 fois le taux légal. Indemnité forfaitaire pour frais de recouvrement : 40 €";
    $footerText2 = "Camson Group - 97, Boulevard Maurice Berteaux - SANNOIS SASU - Siret 947 820 585 00018 RCS Versailles TVA FR81947820585";
    $footerText3 = "www.camsongroup.fr - 01 55 99 00 69";
    
    // Affichage centré
    $pdf->MultiCell(0, 4, $footerText1, 0, 'C', false, 1);
    $pdf->Ln(1);
    $pdf->Cell(0, 4, $footerText2, 0, 1, 'C');
    $pdf->Cell(0, 4, $footerText3, 0, 1, 'C');
    
    // Générer le nom du fichier
    $filename = 'facture_' . $facture['numero'] . '_' . date('YmdHis') . '.pdf';
    $filepath = $uploadDir . '/' . $filename;
    
    // Sauvegarder le PDF sur le serveur
    $pdf->Output($filepath, 'F');
    
    // Retourner le chemin relatif
    return '/uploads/factures/' . date('Y') . '/' . $filename;
}
?>