<?php
/**
 * API pour générer une facture et son PDF
 * Version : Logo Agrandi + Tableau Continu (Totaux intégrés dans la grille)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST seulement si le fichier est appelé directement
// Si le fichier est inclus via require_once, on ne vérifie pas la méthode et on ne s'exécute pas
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    // Le fichier est appelé directement, exécuter le code API
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
    }

    try {
        $pdo = getPdo();
        
        // Vérification basique des tables
        try {
            $pdo->query("SELECT 1 FROM factures LIMIT 1");
            $pdo->query("SELECT 1 FROM facture_lignes LIMIT 1");
        } catch (PDOException $e) {
            jsonResponse(['ok' => false, 'error' => 'Tables introuvables ou erreur DB'], 500);
        }
        
        // Récupération des données
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data || empty($data['factureClient']) || empty($data['factureDate']) || empty($data['lignes'])) {
            jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
        }
        
        // Client
        $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => (int)$data['factureClient']]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
        
        // Calculs et Sauvegarde DB
        // Déterminer le préfixe selon le type de facture
        $factureType = $data['factureType'] ?? 'Consommation';
        $numeroFacture = generateFactureNumber($pdo, $factureType);
        
        $montantHT = 0;
        foreach ($data['lignes'] as $ligne) $montantHT += (float)($ligne['total_ht'] ?? 0);
        $tva = $montantHT * 0.20;
        $montantTTC = $montantHT + $tva;
        
        $pdo->beginTransaction();
        
        try {
            // Insertion Facture
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
            
            // Insertion Lignes
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
            
            // Génération PDF
            $pdfWebPath = generateFacturePDF($pdo, $factureId, $client, $data);
            
            // Vérifier que le fichier existe vraiment avant de le stocker dans la DB
            // Utiliser le même pattern que dans generateFacturePDF
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
                if (is_dir($dir)) {
                    $baseDir = $dir;
                    break;
                }
            }
            
            if (!$baseDir) {
                $baseDir = dirname(__DIR__);
            }
            
            $baseUploadDir = $baseDir . '/uploads';
            $facturesDir = $baseUploadDir . '/factures';
            $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
            $actualFilePath = $facturesDir . '/' . $relativePath;
            
            error_log('Vérification fichier - Base dir: ' . $baseDir);
            error_log('Vérification fichier - Chemin testé: ' . $actualFilePath);
            
            if (!file_exists($actualFilePath)) {
                error_log('ERREUR CRITIQUE: Le fichier PDF n\'existe pas après génération: ' . $actualFilePath);
                error_log('Chemin web retourné: ' . $pdfWebPath);
                error_log('Chemin relatif: ' . $relativePath);
                throw new RuntimeException('Le fichier PDF n\'a pas pu être créé ou n\'existe pas: ' . $actualFilePath);
            }
            
            error_log('Vérification finale: Le fichier PDF existe bien: ' . $actualFilePath . ' (Taille: ' . filesize($actualFilePath) . ' bytes)');
            
            // Mise à jour chemin PDF (on stocke le chemin web relatif)
            $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ?, statut = 'envoyee' WHERE id = ?")->execute([$pdfWebPath, $factureId]);
            
            $pdo->commit();
            
            // Envoi automatique par email (si activé)
            $emailSent = false;
            $emailError = null;
            try {
                require_once __DIR__ . '/../vendor/autoload.php';
                $config = require __DIR__ . '/../config/app.php';
                $invoiceEmailService = new \App\Services\InvoiceEmailService($pdo, $config);
                
                if ($invoiceEmailService->isAutoSendEnabled()) {
                    $result = $invoiceEmailService->sendInvoiceAfterGeneration($factureId, false);
                    if ($result['success']) {
                        $emailSent = true;
                        error_log("[factures_generer] ✅ Facture #{$factureId} envoyée automatiquement par email");
                    } else {
                        $emailError = $result['message'];
                        error_log("[factures_generer] ⚠️ Échec envoi automatique facture #{$factureId}: " . $emailError);
                    }
                }
            } catch (Throwable $e) {
                // Ne pas bloquer la génération de facture si l'envoi échoue
                $emailError = $e->getMessage();
                error_log("[factures_generer] ❌ Erreur envoi automatique (non bloquant): " . $emailError);
            }
            
            $response = [
                'ok' => true, 
                'facture_id' => $factureId, 
                'numero' => $numeroFacture, 
                'pdf_url' => $pdfWebPath
            ];
            
            if ($emailSent) {
                $response['email_sent'] = true;
                $response['message'] = 'Facture générée et envoyée par email';
            } elseif ($emailError) {
                $response['email_sent'] = false;
                $response['email_error'] = $emailError;
            }
            
            jsonResponse($response);
            
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
        
    } catch (Throwable $e) {
        error_log($e->getMessage());
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}

function generateFactureNumber($pdo, $type) {
    // Déterminer le préfixe selon le type
    // Produit, Consommation, Achat → P
    // Service → S
    if (strtolower($type) === 'service') {
        $prefix = 'S';
    } else {
        // Consommation, Achat, Produit → P
        $prefix = 'P';
    }
    
    $year = date('Y');
    $month = date('m');
    
    // Pattern pour rechercher les factures du même type, année et mois
    $pattern = $prefix . $year . $month . '%';
    
    // Compter les factures existantes avec ce pattern
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero LIKE :pattern");
    $stmt->execute([':pattern' => $pattern]);
    $count = (int)$stmt->fetchColumn();
    
    // Générer le numéro : P/S + année + mois + numéro à 3 chiffres (001, 002, etc.)
    $numero = sprintf("%s%s%s%03d", $prefix, $year, $month, $count + 1);
    
    // Vérifier l'unicité (au cas où il y aurait une collision)
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM factures WHERE numero = :numero");
    $stmt->execute([':numero' => $numero]);
    $exists = (int)$stmt->fetchColumn();
    
    // Si le numéro existe déjà, incrémenter jusqu'à trouver un numéro libre
    $attempt = 1;
    while ($exists > 0 && $attempt < 1000) {
        $count++;
        $numero = sprintf("%s%s%s%03d", $prefix, $year, $month, $count + 1);
        $stmt->execute([':numero' => $numero]);
        $exists = (int)$stmt->fetchColumn();
        $attempt++;
    }
    
    return $numero;
}

/**
 * GÉNÉRATION PDF - LOGO AGRANDI & TABLEAU COMPLET
 */
function generateFacturePDF(PDO $pdo, int $factureId, array $client, array $data): string {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    // Données
    $lignes = $pdo->query("SELECT * FROM facture_lignes WHERE id_facture = $factureId ORDER BY ordre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $facture = $pdo->query("SELECT * FROM factures WHERE id = $factureId")->fetch(PDO::FETCH_ASSOC);

    // Setup Dossier - Compatible Railway
    // Sur Railway, le répertoire peut être /app ou /var/www/html selon la config
    // On essaie plusieurs chemins possibles pour trouver le bon répertoire
    
    $possibleBaseDirs = [];
    
    // 1. DOCUMENT_ROOT (le plus fiable)
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && is_dir($docRoot)) {
        $possibleBaseDirs[] = $docRoot;
    }
    
    // 2. Répertoire du projet (dirname(__DIR__))
    $projectDir = dirname(__DIR__);
    if (is_dir($projectDir)) {
        $possibleBaseDirs[] = $projectDir;
    }
    
    // 3. Chemins Railway courants
    if (is_dir('/app')) {
        $possibleBaseDirs[] = '/app';
    }
    if (is_dir('/var/www/html')) {
        $possibleBaseDirs[] = '/var/www/html';
    }
    
    // Utiliser le premier répertoire valide trouvé
    $baseDir = null;
    foreach ($possibleBaseDirs as $dir) {
        if (is_dir($dir) && is_writable($dir)) {
            $baseDir = $dir;
            break;
        }
    }
    
    // Si aucun répertoire valide, utiliser dirname(__DIR__) par défaut
    if (!$baseDir) {
        $baseDir = dirname(__DIR__);
    }
    
    $baseUploadDir = $baseDir . '/uploads';
    $facturesDir = $baseUploadDir . '/factures';
    $uploadDir = $facturesDir . '/' . date('Y');
    
    error_log('Génération PDF - DOCUMENT_ROOT: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Non défini'));
    error_log('Génération PDF - __DIR__: ' . __DIR__);
    error_log('Génération PDF - dirname(__DIR__): ' . dirname(__DIR__));
    error_log('Génération PDF - Base dir sélectionné: ' . $baseDir);
    error_log('Génération PDF - Base upload dir: ' . $baseUploadDir);
    error_log('Génération PDF - Base upload dir existe: ' . (is_dir($baseUploadDir) ? 'Oui' : 'Non'));
    
    // Créer le répertoire de base uploads s'il n'existe pas
    if (!is_dir($baseUploadDir)) {
        error_log('Génération PDF - Création du répertoire: ' . $baseUploadDir);
        $created = @mkdir($baseUploadDir, 0755, true);
        if (!$created) {
            $error = error_get_last();
            error_log('Génération PDF - Erreur création répertoire: ' . ($error['message'] ?? 'Erreur inconnue'));
            throw new RuntimeException('Impossible de créer le répertoire de base uploads: ' . $baseUploadDir);
        }
        error_log('Génération PDF - Répertoire créé avec succès');
    }
    
    // Créer le répertoire factures s'il n'existe pas
    if (!is_dir($facturesDir)) {
        $created = @mkdir($facturesDir, 0755, true);
        if (!$created) {
            throw new RuntimeException('Impossible de créer le répertoire factures: ' . $facturesDir);
        }
    }
    
    // Créer le répertoire de l'année s'il n'existe pas
    if (!is_dir($uploadDir)) {
        $created = @mkdir($uploadDir, 0755, true);
        if (!$created) {
            throw new RuntimeException('Impossible de créer le répertoire de stockage des factures: ' . $uploadDir);
        }
    }
    
    // Vérifier que le répertoire est accessible en écriture
    if (!is_writable($uploadDir)) {
        throw new RuntimeException('Le répertoire de stockage des factures n\'est pas accessible en écriture: ' . $uploadDir);
    }
    
    // Setup TCPDF
    $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
    $pdf->SetCreator('System');
    $pdf->SetTitle('Facture ' . $facture['numero']);
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->SetMargins(15, 10, 15);
    $pdf->SetAutoPageBreak(false); // Gestion manuelle du bas de page
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);

    // ==========================================
    // 1. LOGO (PLUS GRAND : Largeur 60mm)
    // ==========================================
    $logoPath = __DIR__ . '/../assets/logos/logo1.png';
    if (file_exists($logoPath)) {
        // Image(file, x, y, w, h) -> w=60mm (au lieu de 40)
        $pdf->Image($logoPath, 15, 10, 60, 0, 'PNG');
    }

    // ==========================================
    // 2. EXPÉDITEUR (Aligné Droite, Décalé Y=25)
    // ==========================================
    $pdf->SetY(25); 
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, 'SSS international', 0, 1, 'R');
    $pdf->Cell(0, 5, '7, rue pierre brolet', 0, 1, 'R');
    $pdf->Cell(0, 5, '93100 Stains', 0, 1, 'R');

    // ==========================================
    // 3. CLIENT (Gauche - Y=55)
    // ==========================================
    $pdf->SetY(55); // Un peu plus bas car le logo est plus grand
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 5, 'Client :', 0, 1, 'L');
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 5, $client['raison_sociale'], 0, 1, 'L');
    $pdf->Cell(0, 5, $client['adresse'], 0, 1, 'L');
    $pdf->Cell(0, 5, trim(($client['code_postal'] ?? '') . ' ' . ($client['ville'] ?? '')), 0, 1, 'L');
    if (!empty($client['siret'])) $pdf->Cell(0, 5, 'SIRET: ' . $client['siret'], 0, 1, 'L');

    // ==========================================
    // 4. DATE & NUMÉRO
    // ==========================================
    $pdf->SetY(85);
    $pdf->SetFont('helvetica', '', 11);
    $pdf->Cell(90, 6, 'Date : ' . date('d/m/Y', strtotime($facture['date_facture'])), 0, 0, 'L');
    
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell(0, 6, 'Facture N° : ' . $facture['numero'], 0, 1, 'R');

    $pdf->Ln(5); 

    // ==========================================
    // 5. TABLEAU (Continu avec les totaux)
    // ==========================================
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    
    // Définition des largeurs (Total 180mm)
    // Desc(80) + Type(25) + Qty(20) + Prix(25) + Total(30)
    $wDesc = 80; $wType = 25; $wQty = 20; $wPrix = 25; $wTotal = 30;
    
    $pdf->SetX(15); // Centrage forcé (Marge gauche)
    
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
        
        // On retire la troncation trop stricte pour avoir le texte "complet"
        // Si le texte est très long, on le coupe gentiment à 80 caractères
        $desc = mb_substr($ligne['description'], 0, 80); 
        
        $pdf->Cell($wDesc, 7, $desc, 1, 0, 'L');
        $pdf->Cell($wType, 7, $ligne['type'], 1, 0, 'C');
        $pdf->Cell($wQty, 7, number_format($ligne['quantite'], 2, ',', ' '), 1, 0, 'C');
        $pdf->Cell($wPrix, 7, number_format($ligne['prix_unitaire_ht'], 2, ',', ' ') . ' €', 1, 0, 'R');
        $pdf->Cell($wTotal, 7, number_format($ligne['total_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    }
    
    // ==========================================
    // 6. TOTAUX INTÉGRÉS (Grille continue)
    // ==========================================
    // On fusionne les 4 premières colonnes (80+25+20+25 = 150mm)
    // Cela crée une ligne qui ferme parfaitement le tableau
    
    $wMerged = $wDesc + $wType + $wQty + $wPrix; // 150mm
    
    $pdf->SetFont('helvetica', '', 10);
    
    // Total HT
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 6, 'Total HT', 1, 0, 'R'); // Bordure '1' pour fermer la grille
    $pdf->Cell($wTotal, 6, number_format($facture['montant_ht'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // TVA
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 6, 'TVA (20%)', 1, 0, 'R');
    $pdf->Cell($wTotal, 6, number_format($facture['tva'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    // Total TTC (en Gras)
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->SetX(15);
    $pdf->Cell($wMerged, 8, 'Total TTC', 1, 0, 'R');
    $pdf->Cell($wTotal, 8, number_format($facture['montant_ttc'], 2, ',', ' ') . ' €', 1, 1, 'R');

    // ==========================================
    // 7. IBAN & FOOTER
    // ==========================================
    $pdf->Ln(5);
    $pdf->SetX(15);
    $pdf->SetFont('helvetica', '', 10);
    $pdf->Cell(0, 6, 'IBAN : FR76 1027 8063 4700 0229 4870 249 - BIC : CMCIFR2A', 0, 1, 'L');

    // Footer Fixe
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
    $filepath = $uploadDir . '/' . $filename;
    
    // Log pour débogage
    error_log('Génération PDF - Base dir sélectionné: ' . $baseDir);
    error_log('Génération PDF - Upload dir: ' . $uploadDir);
    error_log('Génération PDF - Chemin complet: ' . $filepath);
    error_log('Génération PDF - Répertoire existe: ' . (is_dir($uploadDir) ? 'Oui' : 'Non'));
    error_log('Génération PDF - Répertoire accessible en écriture: ' . (is_writable($uploadDir) ? 'Oui' : 'Non'));
    
    // Vérifier que le répertoire existe avant de sauvegarder
    if (!is_dir($uploadDir)) {
        error_log('ERREUR: Le répertoire n\'existe pas: ' . $uploadDir);
        throw new RuntimeException('Le répertoire de stockage n\'existe pas: ' . $uploadDir);
    }
    
    if (!is_writable($uploadDir)) {
        error_log('ERREUR: Le répertoire n\'est pas accessible en écriture: ' . $uploadDir);
        throw new RuntimeException('Le répertoire de stockage n\'est pas accessible en écriture: ' . $uploadDir);
    }
    
    // Sauvegarder le PDF
    try {
        $pdf->Output($filepath, 'F');
        error_log('Génération PDF - Output() appelé avec succès');
    } catch (Exception $e) {
        error_log('Erreur lors de la sauvegarde du PDF: ' . $e->getMessage());
        error_log('Erreur trace: ' . $e->getTraceAsString());
        throw new RuntimeException('Erreur lors de la sauvegarde du PDF: ' . $e->getMessage());
    }
    
    // Attendre un peu pour que le fichier soit complètement écrit
    usleep(100000); // 100ms
    
    // Vérifier que le fichier a bien été créé
    $maxRetries = 5;
    $retryCount = 0;
    while (!file_exists($filepath) && $retryCount < $maxRetries) {
        usleep(200000); // 200ms
        $retryCount++;
        error_log('Tentative ' . $retryCount . ': Vérification de l\'existence du fichier: ' . $filepath);
    }
    
    if (!file_exists($filepath)) {
        error_log('ERREUR: Le fichier PDF n\'existe pas après sauvegarde: ' . $filepath);
        error_log('Répertoire parent: ' . dirname($filepath));
        error_log('Répertoire parent existe: ' . (is_dir(dirname($filepath)) ? 'Oui' : 'Non'));
        error_log('Liste des fichiers dans le répertoire:');
        if (is_dir(dirname($filepath))) {
            $files = @scandir(dirname($filepath));
            if ($files) {
                error_log('  - ' . implode("\n  - ", array_slice($files, 2))); // Exclure . et ..
            }
        }
        throw new RuntimeException('Le fichier PDF n\'a pas pu être créé: ' . $filepath);
    }
    
    // Vérifier que le fichier n'est pas vide
    $fileSize = filesize($filepath);
    if ($fileSize === 0) {
        @unlink($filepath); // Supprimer le fichier vide
        error_log('ERREUR: Le fichier PDF créé est vide: ' . $filepath);
        throw new RuntimeException('Le fichier PDF créé est vide: ' . $filepath);
    }
    
    error_log('PDF créé avec succès: ' . $filepath . ' (Taille: ' . $fileSize . ' bytes)');
    error_log('Permissions du fichier: ' . substr(sprintf('%o', fileperms($filepath)), -4));
    
    // Vérifier une dernière fois que le fichier existe et est accessible
    if (!file_exists($filepath) || !is_readable($filepath)) {
        error_log('ERREUR CRITIQUE: Le fichier PDF n\'est pas accessible après création: ' . $filepath);
        throw new RuntimeException('Le fichier PDF créé n\'est pas accessible: ' . $filepath);
    }
    
    // Retourner le chemin relatif pour l'accès web
    $webPath = '/uploads/factures/' . date('Y') . '/' . $filename;
    error_log('Chemin web retourné: ' . $webPath);
    error_log('Chemin absolu du fichier: ' . $filepath);
    error_log('Vérification finale: Le fichier existe réellement: ' . (file_exists($filepath) ? 'OUI' : 'NON'));
    error_log('Vérification finale: Le fichier est lisible: ' . (is_readable($filepath) ? 'OUI' : 'NON'));
    error_log('Vérification finale: Taille du fichier: ' . filesize($filepath) . ' bytes');
    
    // Dernière vérification avant de retourner
    if (!file_exists($filepath) || !is_readable($filepath)) {
        error_log('ERREUR FINALE: Le fichier n\'est pas accessible avant retour');
        throw new RuntimeException('Le fichier PDF créé n\'est pas accessible: ' . $filepath);
    }
    
    return $webPath;
}
?>