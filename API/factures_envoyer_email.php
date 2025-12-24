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
            f.id, f.numero, f.date_facture, f.montant_ttc, f.pdf_path,
            c.raison_sociale as client_nom, c.email as client_email
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
        jsonResponse(['ok' => false, 'error' => 'Le fichier PDF est introuvable'], 404);
    }
    
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
    
    // Envoyer l'email avec pièce jointe
    $boundary = md5(time());
    $headers = "From: CC Computer <noreply@cccomputer.fr>\r\n";
    $headers .= "Reply-To: noreply@cccomputer.fr\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n";
    
    $emailBody = "--{$boundary}\r\n";
    $emailBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $emailBody .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $emailBody .= $messageBody . "\r\n\r\n";
    
    // Ajouter la pièce jointe PDF
    $fileContent = file_get_contents($pdfPath);
    $fileContentEncoded = chunk_split(base64_encode($fileContent));
    $fileName = basename($facture['pdf_path']);
    
    $emailBody .= "--{$boundary}\r\n";
    $emailBody .= "Content-Type: application/pdf; name=\"{$fileName}\"\r\n";
    $emailBody .= "Content-Transfer-Encoding: base64\r\n";
    $emailBody .= "Content-Disposition: attachment; filename=\"{$fileName}\"\r\n\r\n";
    $emailBody .= $fileContentEncoded . "\r\n";
    $emailBody .= "--{$boundary}--";
    
    // Envoyer l'email
    $mailSent = @mail($email, $sujet, $emailBody, $headers);
    
    if (!$mailSent) {
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'envoi de l\'email'], 500);
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

