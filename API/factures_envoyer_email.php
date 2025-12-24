<?php
/**
 * API pour envoyer une facture par email
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Récupération des données
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id']) || empty($data['email'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    $email = trim($data['email']);
    $sujet = !empty($data['sujet']) ? trim($data['sujet']) : '';
    $message = !empty($data['message']) ? trim($data['message']) : '';
    
    // Validation de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'Adresse email invalide'], 400);
    }
    
    // Récupérer la facture avec les infos client
    $stmt = $pdo->prepare("
        SELECT 
            f.id, f.numero, f.date_facture, f.montant_ttc, f.pdf_path, f.pdf_genere,
            f.id_client, f.type, f.date_debut_periode, f.date_fin_periode,
            c.raison_sociale as client_nom, c.email as client_email, c.*
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    // Si le PDF n'existe pas ou n'est pas généré, essayer de le régénérer
    // On va d'abord vérifier si le fichier existe, sinon on régénère
    $shouldRegenerate = empty($facture['pdf_path']) || !$facture['pdf_genere'];
    
    // Si le PDF est marqué comme généré mais qu'on ne le trouve pas, on régénère aussi
    if (!$shouldRegenerate && !empty($facture['pdf_path'])) {
        // Vérifier rapidement si le fichier existe
        $quickCheck = false;
        $quickTestDirs = [
            rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'),
            dirname(__DIR__),
            '/var/www/html',
            '/app'
        ];
        
        foreach ($quickTestDirs as $testDir) {
            if ($testDir && is_dir($testDir)) {
                $testPath = $testDir . $facture['pdf_path'];
                if (file_exists($testPath)) {
                    $quickCheck = true;
                    break;
                }
                $testPath2 = $testDir . '/' . ltrim($facture['pdf_path'], '/');
                if (file_exists($testPath2)) {
                    $quickCheck = true;
                    break;
                }
            }
        }
        
        if (!$quickCheck) {
            error_log("PDF marqué comme généré mais fichier introuvable, régénération nécessaire");
            $shouldRegenerate = true;
        }
    }
    
    if ($shouldRegenerate) {
        // Essayer de régénérer le PDF
        try {
            // Inclure seulement les fonctions nécessaires sans exécuter le code principal
            if (!function_exists('generateFacturePDF')) {
                require_once __DIR__ . '/factures_generer.php';
            }
            
            // Récupérer les lignes de facture
            $stmtLignes = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre");
            $stmtLignes->execute([':id' => $factureId]);
            $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
            
            // Préparer les données pour la génération
            $dataForPDF = [
                'factureClient' => $facture['id_client'],
                'factureDate' => $facture['date_facture'],
                'factureType' => $facture['type'],
                'factureDateDebut' => $facture['date_debut_periode'],
                'factureDateFin' => $facture['date_fin_periode'],
                'lignes' => []
            ];
            
            foreach ($lignes as $ligne) {
                $dataForPDF['lignes'][] = [
                    'description' => $ligne['description'],
                    'type' => $ligne['type'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire_ht'],
                    'total_ht' => $ligne['total_ht']
                ];
            }
            
            // Générer le PDF
            $pdfWebPath = generateFacturePDF($pdo, $factureId, $facture, $dataForPDF);
            
            // Mettre à jour la facture
            $stmt = $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?");
            $stmt->execute([$pdfWebPath, $factureId]);
            
            $facture['pdf_path'] = $pdfWebPath;
            $facture['pdf_genere'] = 1;
            
        } catch (Exception $e) {
            error_log("Erreur lors de la régénération du PDF: " . $e->getMessage());
            jsonResponse(['ok' => false, 'error' => 'Impossible de générer le PDF de la facture. ' . $e->getMessage()], 500);
        }
    }
    
    if (empty($facture['pdf_path'])) {
        jsonResponse(['ok' => false, 'error' => 'Le PDF de la facture n\'existe pas et n\'a pas pu être généré'], 400);
    }
    
    // Chercher le fichier PDF en utilisant la même logique que generateFacturePDF
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
    
    // Nettoyer le chemin PDF (enlever le slash initial si présent)
    $pdfPathRelative = ltrim($facture['pdf_path'], '/');
    
    $pdfPath = null;
    $testedPaths = [];
    
    // Essayer chaque répertoire de base
    foreach ($possibleBaseDirs as $baseDir) {
        // Essayer avec le chemin relatif (sans slash initial)
        $testPath1 = $baseDir . '/' . $pdfPathRelative;
        $testedPaths[] = $testPath1;
        if (file_exists($testPath1) && is_readable($testPath1)) {
            $pdfPath = $testPath1;
            error_log("PDF trouvé (méthode 1): " . $pdfPath);
            break;
        }
        
        // Essayer avec le chemin tel quel (avec slash initial)
        $testPath2 = $baseDir . $facture['pdf_path'];
        $testedPaths[] = $testPath2;
        if (file_exists($testPath2) && is_readable($testPath2)) {
            $pdfPath = $testPath2;
            error_log("PDF trouvé (méthode 2): " . $pdfPath);
            break;
        }
    }
    
    // Si toujours pas trouvé, essayer depuis le répertoire API
    if (!$pdfPath) {
        $testPath3 = __DIR__ . '/..' . $facture['pdf_path'];
        $testedPaths[] = $testPath3;
        if (file_exists($testPath3) && is_readable($testPath3)) {
            $pdfPath = $testPath3;
            error_log("PDF trouvé (méthode 3): " . $pdfPath);
        }
    }
    
    // Si toujours pas trouvé, essayer depuis le répertoire du projet
    if (!$pdfPath) {
        $testPath4 = dirname(__DIR__) . $facture['pdf_path'];
        $testedPaths[] = $testPath4;
        if (file_exists($testPath4) && is_readable($testPath4)) {
            $pdfPath = $testPath4;
            error_log("PDF trouvé (méthode 4): " . $pdfPath);
        }
    }
    
    if (!$pdfPath || !file_exists($pdfPath) || !is_readable($pdfPath)) {
        // Logs détaillés pour déboguer
        error_log("PDF introuvable - Chemin DB: " . $facture['pdf_path']);
        error_log("Base dirs testés: " . implode(', ', $possibleBaseDirs));
        error_log("Chemin relatif nettoyé: " . $pdfPathRelative);
        error_log("Chemins testés: " . implode(', ', $testedPaths));
        
        // Vérifier si le répertoire uploads existe et lister les fichiers
        foreach ($possibleBaseDirs as $baseDir) {
            $uploadsDir = $baseDir . '/uploads/factures';
            error_log("Vérification répertoire uploads: {$uploadsDir} - " . (is_dir($uploadsDir) ? 'EXISTE' : 'N\'EXISTE PAS'));
            if (is_dir($uploadsDir)) {
                // Essayer avec l'année de la facture
                $yearFromFacture = date('Y', strtotime($facture['date_facture']));
                $yearDir = $uploadsDir . '/' . $yearFromFacture;
                error_log("Vérification répertoire année (depuis facture): {$yearDir} - " . (is_dir($yearDir) ? 'EXISTE' : 'N\'EXISTE PAS'));
                if (is_dir($yearDir)) {
                    $files = scandir($yearDir);
                    $pdfFiles = array_filter($files, function($f) { return $f !== '.' && $f !== '..' && pathinfo($f, PATHINFO_EXTENSION) === 'pdf'; });
                    error_log("Fichiers PDF dans {$yearDir}: " . implode(', ', $pdfFiles));
                }
                // Essayer aussi avec l'année actuelle
                $yearCurrent = date('Y');
                if ($yearCurrent !== $yearFromFacture) {
                    $yearDirCurrent = $uploadsDir . '/' . $yearCurrent;
                    error_log("Vérification répertoire année (actuelle): {$yearDirCurrent} - " . (is_dir($yearDirCurrent) ? 'EXISTE' : 'N\'EXISTE PAS'));
                }
            }
        }
        
        // Si le PDF n'existe pas mais qu'on a un pdf_path, essayer de régénérer
        if (!empty($facture['pdf_path']) && $facture['pdf_genere']) {
            error_log("Tentative de régénération du PDF pour la facture #{$factureId}");
            // La régénération a déjà été tentée plus haut, donc on retourne l'erreur
        }
        
        jsonResponse([
            'ok' => false, 
            'error' => 'Le fichier PDF est introuvable sur le serveur. Chemin enregistré: ' . $facture['pdf_path'] . '. Le fichier a peut-être été supprimé ou déplacé. Veuillez régénérer la facture.',
            'debug' => [
                'pdf_path_db' => $facture['pdf_path'],
                'base_dirs' => $possibleBaseDirs,
                'tested_paths' => $testedPaths,
                'document_root' => $_SERVER['DOCUMENT_ROOT'] ?? 'Non défini',
                'project_dir' => dirname(__DIR__)
            ]
        ], 404);
    }
    
    error_log("PDF trouvé avec succès: " . $pdfPath . " (Taille: " . filesize($pdfPath) . " bytes)");
    
    // Préparer le sujet et le message
    if (empty($sujet)) {
        $sujet = "Facture {$facture['numero']} - CC Computer";
    }
    
    $messageBody = $message;
    if (empty($messageBody)) {
        $messageBody = "Bonjour,\n\n";
        $messageBody .= "Veuillez trouver ci-joint la facture {$facture['numero']} d'un montant de " . number_format($facture['montant_ttc'], 2, ',', ' ') . " € TTC.\n\n";
        $messageBody .= "Cordialement,\nCC Computer";
    } else {
        $messageBody = $message . "\n\n";
        $messageBody .= "Veuillez trouver ci-joint la facture {$facture['numero']} d'un montant de " . number_format($facture['montant_ttc'], 2, ',', ' ') . " € TTC.";
    }
    
    // Vérifier que la fonction mail() est disponible
    if (!function_exists('mail')) {
        error_log('La fonction mail() n\'est pas disponible sur ce serveur');
        jsonResponse(['ok' => false, 'error' => 'La fonction d\'envoi d\'email n\'est pas disponible sur ce serveur'], 500);
    }
    
    // Vérifier la taille du fichier PDF (limite recommandée: 10MB pour les emails)
    $pdfSize = filesize($pdfPath);
    if ($pdfSize > 10 * 1024 * 1024) {
        error_log("PDF trop volumineux: {$pdfSize} bytes");
        jsonResponse(['ok' => false, 'error' => 'Le fichier PDF est trop volumineux pour être envoyé par email (max 10MB)'], 400);
    }
    
    // Lire le contenu du PDF
    $fileContent = @file_get_contents($pdfPath);
    if ($fileContent === false) {
        error_log("Impossible de lire le fichier PDF: {$pdfPath}");
        jsonResponse(['ok' => false, 'error' => 'Impossible de lire le fichier PDF'], 500);
    }
    
    // Envoyer l'email avec pièce jointe
    $boundary = md5(time() . uniqid());
    $headers = "From: CC Computer <noreply@cccomputer.fr>\r\n";
    $headers .= "Reply-To: noreply@cccomputer.fr\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    
    // Corps du message
    $emailBody = "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailBody .= $messageBody . "\r\n\r\n";
    
    // Ajouter la pièce jointe PDF
    $fileContentEncoded = chunk_split(base64_encode($fileContent));
    $fileName = basename($facture['pdf_path']);
    // Nettoyer le nom du fichier pour éviter les problèmes
    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    
    $emailBody .= "--{$boundary}\r\n";
    $emailBody .= "Content-Type: application/pdf; name=\"{$fileName}\"\r\n";
    $emailBody .= "Content-Transfer-Encoding: base64\r\n";
    $emailBody .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n\r\n";
    $emailBody .= $fileContentEncoded . "\r\n";
    $emailBody .= "--{$boundary}--\r\n";
    
    // Capturer les erreurs PHP
    $lastError = null;
    set_error_handler(function($errno, $errstr) use (&$lastError) {
        $lastError = $errstr;
        return true;
    });
    
    // Envoyer l'email
    try {
        $mailSent = @mail($email, $sujet, $emailBody, $headers);
        
        restore_error_handler();
        
        if (!$mailSent) {
            $errorMsg = $lastError ?: 'Erreur inconnue lors de l\'envoi';
            error_log("Erreur envoi email - Destinataire: {$email}, Erreur: {$errorMsg}");
            error_log("Taille email: " . strlen($emailBody) . " bytes, Taille PDF: {$pdfSize} bytes");
            
            // Vérifier si c'est un problème de configuration
            $iniSendmail = ini_get('sendmail_path');
            error_log("Configuration sendmail_path: " . ($iniSendmail ?: 'non configuré'));
            
            // Message d'erreur plus explicite
            $userMessage = 'Erreur lors de l\'envoi de l\'email. ';
            if (empty($iniSendmail)) {
                $userMessage .= 'Le serveur n\'a pas de configuration d\'envoi d\'email. ';
                $userMessage .= 'Sur Railway ou certains hébergeurs, il faut configurer un service SMTP externe (SendGrid, Mailgun, etc.).';
            } else {
                $userMessage .= $errorMsg;
            }
            
            jsonResponse([
                'ok' => false, 
                'error' => $userMessage,
                'debug' => [
                    'sendmail_path' => $iniSendmail,
                    'pdf_size' => $pdfSize,
                    'email_size' => strlen($emailBody),
                    'php_error' => $errorMsg
                ]
            ], 500);
        }
    } catch (Exception $e) {
        restore_error_handler();
        error_log("Exception lors de l'envoi email: " . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Exception lors de l\'envoi: ' . $e->getMessage()], 500);
    }
    
    // Mettre à jour la facture pour indiquer qu'elle a été envoyée
    $stmt = $pdo->prepare("
        UPDATE factures 
        SET email_envoye = 1, date_envoi_email = NOW() 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $factureId]);
    
    jsonResponse([
        'ok' => true,
        'message' => 'Facture envoyée par email avec succès',
        'facture_id' => $factureId,
        'email' => $email
    ]);
    
} catch (PDOException $e) {
    error_log('factures_envoyer_email.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_envoyer_email.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

