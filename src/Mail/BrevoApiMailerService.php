<?php
declare(strict_types=1);

namespace App\Mail;

/**
 * Service pour l'envoi d'emails via l'API Brevo (HTTP)
 * Remplace SMTP pour éviter les timeouts réseau sur Railway
 */
class BrevoApiMailerService
{
    private string $apiKey;
    private string $senderEmail;
    private string $senderName;
    private string $apiEndpoint = 'https://api.brevo.com/v3/smtp/email';
    
    public function __construct()
    {
        $this->apiKey = $_ENV['BREVO_API_KEY'] ?? '';
        $this->senderEmail = $_ENV['BREVO_SENDER_EMAIL'] ?? $_ENV['SMTP_FROM_EMAIL'] ?? '';
        $this->senderName = $_ENV['BREVO_SENDER_NAME'] ?? $_ENV['SMTP_FROM_NAME'] ?? 'CC Computer';
        
        if (empty($this->apiKey)) {
            throw new MailerException('BREVO_API_KEY non définie dans les variables d\'environnement');
        }
        
        if (empty($this->senderEmail)) {
            throw new MailerException('BREVO_SENDER_EMAIL ou SMTP_FROM_EMAIL non définie');
        }
    }
    
    /**
     * Envoie un email avec un fichier PDF en pièce jointe via l'API Brevo
     * 
     * @param string $to Adresse email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $textBody Corps du message (texte)
     * @param string|null $pdfPath Chemin absolu vers le fichier PDF à attacher
     * @param string|null $pdfFileName Nom du fichier PDF
     * @param string|null $htmlBody Corps du message HTML (optionnel)
     * @return string Message-ID retourné par Brevo
     * @throws MailerException En cas d'erreur
     */
    public function sendEmailWithPdf(
        string $to,
        string $subject,
        string $textBody,
        ?string $pdfPath = null,
        ?string $pdfFileName = null,
        ?string $htmlBody = null
    ): string {
        // Validation de l'email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailerException('Adresse email invalide: ' . $to);
        }
        
        // Préparer le payload JSON
        $payload = [
            'sender' => [
                'email' => $this->senderEmail,
                'name' => $this->senderName
            ],
            'to' => [
                [
                    'email' => $to
                ]
            ],
            'subject' => $subject,
            'textContent' => $textBody
        ];
        
        // Ajouter HTML si fourni
        if (!empty($htmlBody)) {
            $payload['htmlContent'] = $htmlBody;
        }
        
        // Ajouter la pièce jointe PDF si fournie
        if ($pdfPath !== null && file_exists($pdfPath)) {
            $pdfContent = file_get_contents($pdfPath);
            if ($pdfContent === false) {
                throw new MailerException('Impossible de lire le fichier PDF: ' . $pdfPath);
            }
            
            $pdfBase64 = base64_encode($pdfContent);
            $fileName = $pdfFileName ?? basename($pdfPath);
            
            $payload['attachment'] = [
                [
                    'name' => $fileName,
                    'content' => $pdfBase64
                ]
            ];
        }
        
        // Envoyer la requête HTTP
        $ch = curl_init($this->apiEndpoint);
        
        if ($ch === false) {
            throw new MailerException('Impossible d\'initialiser cURL pour l\'API Brevo');
        }
        
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        // Gérer les erreurs cURL
        if ($response === false || !empty($curlError)) {
            error_log("[BrevoApiMailerService] Erreur cURL: {$curlError}");
            throw new MailerException('Erreur de connexion à l\'API Brevo: ' . ($curlError ?: 'Connexion échouée'));
        }
        
        // Parser la réponse
        $responseData = json_decode($response, true);
        
        // Gérer les erreurs HTTP
        if ($httpCode >= 400) {
            $errorMessage = 'Erreur API Brevo';
            if (isset($responseData['message'])) {
                $errorMessage .= ': ' . $responseData['message'];
            } elseif (isset($responseData['error'])) {
                $errorMessage .= ': ' . (is_string($responseData['error']) ? $responseData['error'] : json_encode($responseData['error']));
            } else {
                $errorMessage .= " (HTTP {$httpCode})";
            }
            
            error_log("[BrevoApiMailerService] Erreur HTTP {$httpCode}: " . ($responseData['message'] ?? $response));
            throw new MailerException($errorMessage);
        }
        
        // Extraire le messageId de la réponse
        $messageId = null;
        if (isset($responseData['messageId'])) {
            $messageId = (string)$responseData['messageId'];
        } else {
            // Fallback: générer un messageId si Brevo ne le retourne pas
            $messageId = $this->generateMessageId();
        }
        
        error_log("[BrevoApiMailerService] Email envoyé avec succès via API Brevo à {$to} (Sujet: {$subject}, Message-ID: {$messageId})");
        
        return $messageId;
    }
    
    /**
     * Génère un Message-ID unique conforme RFC 5322 (fallback)
     * Format: <timestamp.random@domain>
     */
    private function generateMessageId(): string
    {
        $domain = $_ENV['MAIL_MESSAGE_ID_DOMAIN'] ?? 'cccomputer.fr';
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        
        return "<{$timestamp}.{$random}@{$domain}>";
    }
}

