<?php
/**
 * Helper pour l'envoi d'emails avec PHPMailer
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Envoyer un email avec PHPMailer
 * 
 * @param string $to Email du destinataire
 * @param string $subject Sujet de l'email
 * @param string $body Corps de l'email (HTML)
 * @param array $attachments Tableau de chemins de fichiers à joindre
 * @param string $fromEmail Email de l'expéditeur (optionnel)
 * @param string $fromName Nom de l'expéditeur (optionnel)
 * @return array ['ok' => bool, 'error' => string|null]
 */
function sendEmail(
    string $to,
    string $subject,
    string $body,
    array $attachments = [],
    string $fromEmail = null,
    string $fromName = null
): array {
    // Charger PHPMailer
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        return ['ok' => false, 'error' => 'PHPMailer non installé'];
    }
    
    require_once $vendorPath;
    
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return ['ok' => false, 'error' => 'Classe PHPMailer introuvable'];
    }
    
    try {
        $mail = new PHPMailer(true);
        
        // Configuration SMTP (à adapter selon votre configuration)
        // Par défaut, utiliser la fonction mail() de PHP
        $mail->isMail(); // Utiliser la fonction mail() de PHP
        
        // Si vous avez un serveur SMTP, décommentez et configurez :
        /*
        $mail->isSMTP();
        $mail->Host = getenv('SMTP_HOST') ?: 'smtp.example.com';
        $mail->SMTPAuth = true;
        $mail->Username = getenv('SMTP_USER') ?: 'user@example.com';
        $mail->Password = getenv('SMTP_PASS') ?: 'password';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = (int)(getenv('SMTP_PORT') ?: 587);
        */
        
        // Expéditeur
        $mail->setFrom(
            $fromEmail ?: (getenv('EMAIL_FROM') ?: 'noreply@cccomputer.com'),
            $fromName ?: (getenv('EMAIL_FROM_NAME') ?: 'CCComputer')
        );
        
        // Destinataire
        $mail->addAddress($to);
        
        // Contenu
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = strip_tags($body); // Version texte brut
        
        // Pièces jointes
        foreach ($attachments as $attachment) {
            if (is_array($attachment)) {
                // Format: ['path' => '/path/to/file', 'name' => 'filename.pdf']
                $path = $attachment['path'] ?? '';
                $name = $attachment['name'] ?? basename($path);
                if (file_exists($path)) {
                    $mail->addAttachment($path, $name);
                }
            } else {
                // Format simple: chemin du fichier
                if (file_exists($attachment)) {
                    $mail->addAttachment($attachment);
                }
            }
        }
        
        // Envoyer
        $mail->send();
        
        return ['ok' => true, 'error' => null];
        
    } catch (Exception $e) {
        error_log('Erreur envoi email: ' . $mail->ErrorInfo);
        return ['ok' => false, 'error' => $mail->ErrorInfo];
    }
}

/**
 * Générer le corps HTML d'un email de confirmation de paiement
 * 
 * @param array $client Informations du client
 * @param array $payment Informations du paiement
 * @return string HTML de l'email
 */
function generatePaymentConfirmationEmailBody(array $client, array $payment): string {
    $date = !empty($payment['date']) ? date('d/m/Y', strtotime($payment['date'])) : date('d/m/Y');
    $amount = number_format($payment['amount'] ?? 0, 2, ',', ' ') . ' €';
    
    $typeLabels = [
        'especes' => 'Espèces',
        'cheque' => 'Chèque',
        'virement' => 'Virement'
    ];
    $typeLabel = $typeLabels[$payment['type'] ?? ''] ?? ucfirst($payment['type'] ?? 'N/A');
    
    $html = '
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Confirmation de Paiement</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
            }
            .header {
                background-color: #3b82f6;
                color: white;
                padding: 20px;
                text-align: center;
                border-radius: 5px 5px 0 0;
            }
            .content {
                background-color: #f8fafc;
                padding: 20px;
                border: 1px solid #e2e8f0;
            }
            .info-box {
                background-color: white;
                padding: 15px;
                margin: 15px 0;
                border-radius: 5px;
                border-left: 4px solid #3b82f6;
            }
            .info-row {
                display: flex;
                justify-content: space-between;
                padding: 8px 0;
                border-bottom: 1px solid #e2e8f0;
            }
            .info-row:last-child {
                border-bottom: none;
            }
            .info-label {
                font-weight: bold;
                color: #64748b;
            }
            .info-value {
                color: #1e293b;
            }
            .amount {
                font-size: 24px;
                font-weight: bold;
                color: #16a34a;
                text-align: center;
                padding: 20px;
            }
            .footer {
                text-align: center;
                padding: 20px;
                color: #64748b;
                font-size: 12px;
            }
        </style>
    </head>
    <body>
        <div class="header">
            <h1>Confirmation de Paiement</h1>
        </div>
        <div class="content">
            <p>Bonjour,</p>
            <p>Nous vous confirmons la réception de votre paiement. Voici les détails :</p>
            
            <div class="info-box">
                <div class="info-row">
                    <span class="info-label">Client :</span>
                    <span class="info-value">' . htmlspecialchars($client['raison_sociale'] ?? 'N/A') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Numéro client :</span>
                    <span class="info-value">' . htmlspecialchars($client['numero_client'] ?? 'N/A') . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Date de paiement :</span>
                    <span class="info-value">' . htmlspecialchars($date) . '</span>
                </div>
                <div class="info-row">
                    <span class="info-label">Type de paiement :</span>
                    <span class="info-value">' . htmlspecialchars($typeLabel) . '</span>
                </div>';
    
    if (!empty($payment['reference'])) {
        $html .= '
                <div class="info-row">
                    <span class="info-label">Référence :</span>
                    <span class="info-value">' . htmlspecialchars($payment['reference']) . '</span>
                </div>';
    }
    
    $html .= '
            </div>
            
            <div class="amount">
                Montant : ' . htmlspecialchars($amount) . '
            </div>
            
            <p>Le justificatif de paiement est joint à cet email en pièce jointe.</p>
            
            <p>Merci pour votre confiance.</p>
            <p>Cordialement,<br>L\'équipe CCComputer</p>
        </div>
        <div class="footer">
            <p>Cet email a été envoyé automatiquement. Merci de ne pas y répondre.</p>
            <p>CCComputer - ' . date('Y') . '</p>
        </div>
    </body>
    </html>';
    
    return $html;
}

