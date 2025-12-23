<?php
/**
 * API pour envoyer une facture par email au client
 */

// Gestionnaire d'erreurs global pour capturer toutes les erreurs PHP
set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() & $severity) {
        error_log("factures_envoyer.php PHP Error: $message in $file on line $line");
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Erreur PHP: ' . $message
        ]);
        exit;
    }
    return false;
});

// Gestionnaire d'exceptions non capturées
set_exception_handler(function($exception) {
    error_log("factures_envoyer.php Uncaught Exception: " . $exception->getMessage() . " in " . $exception->getFile() . " on line " . $exception->getLine());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur: ' . $exception->getMessage()
    ]);
    exit;
});

try {
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../includes/api_helpers.php';
} catch (Throwable $e) {
    error_log('factures_envoyer.php - Erreur lors du chargement des includes: ' . $e->getMessage());
    error_log('factures_envoyer.php - Trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => false,
        'error' => 'Erreur d\'initialisation: ' . $e->getMessage()
    ]);
    exit;
}

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Récupérer les données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id']) || empty($data['email'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes (facture_id et email requis)'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    $email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
    
    if (!$email) {
        jsonResponse(['ok' => false, 'error' => 'Adresse email invalide'], 400);
    }
    
    // Récupérer la facture avec les informations du client
    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.montant_ttc,
            f.pdf_path,
            c.raison_sociale as client_nom,
            c.email as client_email
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
    
    if (empty($facture['pdf_path'])) {
        jsonResponse(['ok' => false, 'error' => 'Le PDF de la facture n\'existe pas'], 400);
    }
    
    // Chemin complet du fichier PDF
    // Le pdf_path est stocké comme '/uploads/factures/2025/facture_XXX.pdf' (chemin web relatif)
    // On doit le convertir en chemin absolu du système de fichiers
    
    $pdfWebPath = $facture['pdf_path'];
    $pdfPath = null;
    
    // Essayer plusieurs chemins possibles
    // Le pdfWebPath est de la forme: /uploads/factures/2025/facture_XXX.pdf
    // Sur Railway, on doit utiliser le même pattern que factures_generer.php
    
    // Pattern principal (identique à factures_generer.php) - Compatible Railway
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && is_dir($docRoot)) {
        $baseUploadDir = $docRoot . '/uploads';
    } else {
        // Fallback: utiliser le répertoire du projet
        $baseUploadDir = dirname(__DIR__) . '/uploads';
    }
    
    $facturesDir = $baseUploadDir . '/factures';
    
    // Extraire l'année et le nom du fichier depuis le chemin web
    // pdfWebPath = /uploads/factures/2025/facture_XXX.pdf
    // On veut: 2025/facture_XXX.pdf
    $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
    
    $possiblePaths = [
        // Chemin principal (identique à factures_generer.php)
        $facturesDir . '/' . $relativePath,
        // Chemin avec realpath
        realpath($facturesDir) . '/' . $relativePath,
        // Chemin depuis la racine du projet
        dirname(__DIR__) . $pdfWebPath,
        // Chemin depuis la racine du document
        ($_SERVER['DOCUMENT_ROOT'] ?? '') . $pdfWebPath,
        // Chemin relatif depuis le dossier API
        __DIR__ . '/..' . $pdfWebPath,
        // Chemin absolu si déjà absolu
        $pdfWebPath
    ];
    
    // Filtrer les chemins null (realpath peut retourner false)
    $possiblePaths = array_filter($possiblePaths, function($path) {
        return $path !== false && !empty($path);
    });
    
    error_log('Recherche du PDF - chemin web: ' . $pdfWebPath);
    error_log('Recherche du PDF - DOCUMENT_ROOT: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Non défini'));
    error_log('Recherche du PDF - __DIR__: ' . __DIR__);
    error_log('Recherche du PDF - Base upload directory: ' . $baseUploadDir);
    error_log('Recherche du PDF - Base upload dir existe: ' . (is_dir($baseUploadDir) ? 'Oui' : 'Non'));
    error_log('Recherche du PDF - Factures directory: ' . $facturesDir);
    error_log('Recherche du PDF - Factures dir existe: ' . (is_dir($facturesDir) ? 'Oui' : 'Non'));
    error_log('Recherche du PDF - Relative path: ' . $relativePath);
    error_log('Recherche du PDF - Real factures directory: ' . (realpath($facturesDir) !== false ? realpath($facturesDir) : 'Non disponible'));
    
    foreach ($possiblePaths as $testPath) {
        // Nettoyer le chemin
        $testPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $testPath);
        $testPath = preg_replace('/[\/\\\\]+/', DIRECTORY_SEPARATOR, $testPath);
        
        error_log('Test chemin: ' . $testPath . ' - Existe: ' . (file_exists($testPath) ? 'Oui' : 'Non'));
        
        if (file_exists($testPath) && is_file($testPath)) {
            $pdfPath = $testPath;
            break;
        }
    }
    
    if (!$pdfPath) {
        error_log('ERREUR: Le fichier PDF n\'existe pas. Chemins testés:');
        foreach ($possiblePaths as $testPath) {
            error_log('  - ' . $testPath);
        }
        error_log('  - pdf_path dans DB: ' . $pdfWebPath);
        error_log('  - __DIR__: ' . __DIR__);
        error_log('  - DOCUMENT_ROOT: ' . ($_SERVER['DOCUMENT_ROOT'] ?? 'Non défini'));
        
        jsonResponse([
            'ok' => false, 
            'error' => 'Le fichier PDF n\'existe pas sur le serveur. Vérifiez que le fichier a bien été créé lors de la génération de la facture.'
        ], 404);
    }
    
    error_log('PDF trouvé: ' . $pdfPath);
    
    // Préparer l'email
    $subject = 'Facture ' . $facture['numero'] . ' - ' . $facture['client_nom'];
    $message = "
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .header { background-color: #f4f4f4; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
                .content { padding: 20px; }
                .footer { margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 0.9em; color: #666; }
            </style>
        </head>
        <body>
            <div class='header'>
                <h2>Facture " . htmlspecialchars($facture['numero']) . "</h2>
            </div>
            <div class='content'>
                <p>Bonjour,</p>
                <p>Veuillez trouver ci-joint la facture <strong>" . htmlspecialchars($facture['numero']) . "</strong> du " . date('d/m/Y', strtotime($facture['date_facture'])) . ".</p>
                <p><strong>Montant TTC :</strong> " . number_format($facture['montant_ttc'], 2, ',', ' ') . " €</p>
                <p>Merci de votre confiance.</p>
            </div>
            <div class='footer'>
                <p>Cordialement,<br>SSS international<br>7, rue pierre brolet<br>93100 Stains</p>
            </div>
        </body>
        </html>
    ";
    
    // Lire le contenu du PDF
    if (!is_readable($pdfPath)) {
        error_log('ERREUR: Le fichier PDF n\'est pas lisible: ' . $pdfPath);
        jsonResponse(['ok' => false, 'error' => 'Le fichier PDF n\'est pas accessible en lecture'], 500);
    }
    
    $pdfContent = @file_get_contents($pdfPath);
    if ($pdfContent === false) {
        error_log('ERREUR: Impossible de lire le contenu du PDF: ' . $pdfPath);
        jsonResponse(['ok' => false, 'error' => 'Impossible de lire le fichier PDF'], 500);
    }
    
    if (empty($pdfContent)) {
        error_log('ERREUR: Le fichier PDF est vide: ' . $pdfPath);
        jsonResponse(['ok' => false, 'error' => 'Le fichier PDF est vide'], 500);
    }
    
    $pdfBase64 = base64_encode($pdfContent);
    $pdfFilename = 'facture_' . $facture['numero'] . '.pdf';
    
    // Boundary pour les pièces jointes
    $boundary = md5(time());
    
    // Headers pour l'email avec pièce jointe
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "From: SSS international <noreply@camsongroup.fr>\r\n";
    $headers .= "Reply-To: noreply@camsongroup.fr\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n";
    
    // Corps de l'email avec pièce jointe
    $emailBody = "--" . $boundary . "\r\n";
    $emailBody .= "Content-Type: text/html; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailBody .= $message . "\r\n";
    $emailBody .= "--" . $boundary . "\r\n";
    $emailBody .= "Content-Type: application/pdf; name=\"" . $pdfFilename . "\"\r\n";
    $emailBody .= "Content-Transfer-Encoding: base64\r\n";
    $emailBody .= "Content-Disposition: attachment; filename=\"" . $pdfFilename . "\"\r\n\r\n";
    $emailBody .= chunk_split($pdfBase64) . "\r\n";
    $emailBody .= "--" . $boundary . "--";
    
    // Envoyer l'email
    $mailSent = @mail($email, $subject, $emailBody, $headers);
    
    if (!$mailSent) {
        error_log('Erreur lors de l\'envoi de l\'email pour la facture ' . $facture['numero'] . ' à ' . $email);
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'envoi de l\'email. Vérifiez la configuration du serveur mail.'], 500);
    }
    
    // Mettre à jour la facture pour indiquer que l'email a été envoyé
    $stmt = $pdo->prepare("
        UPDATE factures 
        SET email_envoye = 1, 
            date_envoi_email = NOW() 
        WHERE id = :id
    ");
    $stmt->execute([':id' => $factureId]);
    
    // Mettre à jour l'email du client si différent
    if (!empty($facture['client_email']) && $facture['client_email'] !== $email) {
        $stmt = $pdo->prepare("UPDATE clients SET email = :email WHERE id = (SELECT id_client FROM factures WHERE id = :facture_id)");
        $stmt->execute([':email' => $email, ':facture_id' => $factureId]);
    } elseif (empty($facture['client_email'])) {
        // Si le client n'avait pas d'email, l'ajouter
        $stmt = $pdo->prepare("UPDATE clients SET email = :email WHERE id = (SELECT id_client FROM factures WHERE id = :facture_id)");
        $stmt->execute([':email' => $email, ':facture_id' => $factureId]);
    }
    
    jsonResponse([
        'ok' => true,
        'message' => 'Facture envoyée avec succès à ' . $email
    ]);
    
} catch (PDOException $e) {
    error_log('factures_envoyer.php SQL error: ' . $e->getMessage());
    error_log('factures_envoyer.php SQL trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('factures_envoyer.php error: ' . $e->getMessage());
    error_log('factures_envoyer.php error class: ' . get_class($e));
    error_log('factures_envoyer.php trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur: ' . $e->getMessage()], 500);
}

