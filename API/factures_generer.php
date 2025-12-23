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
    $year = date('Y');
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM factures WHERE numero LIKE :pattern");
    $stmt->execute([':pattern' => "FAC-{$year}-%"]);
    $count = (int)$stmt->fetch(PDO::FETCH_ASSOC)['count'];
    $numero = sprintf("FAC-%s-%04d", $year, $count + 1);
    return $numero;
}

/**
 * Génère le PDF de la facture
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
    // TCPDF utilise 'P' pour portrait, 'mm' pour millimètres, 'A4' pour le format
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    
    // Informations du document
    $pdf->SetCreator('CC Computer');
    $pdf->SetAuthor('CC Computer');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    $pdf->SetSubject('Facture');
    
    // Supprimer les en-têtes et pieds de page par défaut
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Ajouter une page
    $pdf->AddPage();
    
    // Couleurs
    $pdf->SetTextColor(0, 0, 0);
    
    // En-tête
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->Cell(0, 10, 'FACTURE', 0, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'CC Computer', 0, 1, 'R');
    $pdf->Cell(0, 5, 'Adresse de l\'entreprise', 0, 1, 'R');
    $pdf->Cell(0, 5, 'Email: contact@cccomputer.fr', 0, 1, 'R');
    $pdf->Ln(10);
    
    // Informations facture
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Facture N°: ' . $facture['numero'], 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'Date: ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 1);
    if ($facture['date_debut_periode'] && $facture['date_fin_periode']) {
        $pdf->Cell(0, 5, 'Période: ' . date('d/m/Y', strtotime($facture['date_debut_periode'])) . ' au ' . date('d/m/Y', strtotime($facture['date_fin_periode'])), 0, 1);
    }
    $pdf->Ln(5);
    
    // Informations client
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'Client:', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1);
    $pdf->Cell(0, 5, $client['adresse'], 0, 1);
    $pdf->Cell(0, 5, $client['code_postal'] . ' ' . $client['ville'], 0, 1);
    if (!empty($client['siret'])) {
        $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1);
    }
    $pdf->Ln(10);
    
    // Tableau des lignes
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->Cell(80, 8, 'Description', 1, 0, 'L');
    $pdf->Cell(30, 8, 'Type', 1, 0, 'C');
    $pdf->Cell(25, 8, 'Qté', 1, 0, 'C');
    $pdf->Cell(25, 8, 'Prix unit.', 1, 0, 'R');
    $pdf->Cell(30, 8, 'Total HT', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 9);
    foreach ($lignes as $ligne) {
        $pdf->Cell(80, 7, $ligne['description'], 1, 0, 'L');
        $pdf->Cell(30, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell(25, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell(25, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell(30, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // Totaux
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(160, 7, 'Total HT:', 1, 0, 'R');
    $pdf->Cell(30, 7, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->Cell(160, 7, 'TVA (20%):', 1, 0, 'R');
    $pdf->Cell(30, 7, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(160, 8, 'Total TTC:', 1, 0, 'R');
    $pdf->Cell(30, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // Pied de page
    $pdf->Ln(10);
    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Merci de votre confiance !', 0, 1, 'C');
    
    // Générer le nom du fichier
    $filename = 'facture_' . $facture['numero'] . '_' . date('YmdHis') . '.pdf';
    $filepath = $uploadDir . '/' . $filename;
    
    // Sauvegarder le PDF
    $pdf->Output($filepath, 'F');
    
    // Retourner le chemin relatif
    return '/uploads/factures/' . date('Y') . '/' . $filename;
}

