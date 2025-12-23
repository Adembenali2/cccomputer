<?php
/**
 * API pour envoyer une facture par email au client
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
    $pdfPath = __DIR__ . '/..' . $facture['pdf_path'];
    
    if (!file_exists($pdfPath)) {
        jsonResponse(['ok' => false, 'error' => 'Le fichier PDF n\'existe pas sur le serveur'], 404);
    }
    
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
    $pdfContent = file_get_contents($pdfPath);
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
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_envoyer.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

