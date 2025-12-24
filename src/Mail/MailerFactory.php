<?php
declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Factory pour créer des instances PHPMailer configurées
 */
class MailerFactory
{
    /**
     * Crée une instance PHPMailer configurée avec les paramètres SMTP
     * 
     * @param array $config Configuration email depuis config/app.php
     * @return PHPMailer Instance configurée
     * @throws MailerException Si la configuration est invalide
     */
    public static function create(array $config): PHPMailer
    {
        // Validation de la configuration
        self::validateConfig($config);
        
        $mail = new PHPMailer(true);
        
        try {
            // Configuration SMTP
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_secure']; // 'tls' ou 'ssl'
            $mail->Port = (int)$config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
            // Expéditeur
            $mail->setFrom(
                $config['from_email'],
                $config['from_name'] ?? 'CC Computer'
            );
            
            // Reply-To
            $mail->addReplyTo(
                $config['reply_to_email'] ?? $config['from_email'],
                $config['from_name'] ?? 'CC Computer'
            );
            
            // Options supplémentaires
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ];
            
        } catch (PHPMailerException $e) {
            throw new MailerException(
                'Erreur lors de la configuration de PHPMailer: ' . $e->getMessage(),
                0,
                $e
            );
        }
        
        return $mail;
    }
    
    /**
     * Valide la configuration SMTP
     * 
     * @param array $config Configuration email
     * @throws MailerException Si la configuration est invalide
     */
    private static function validateConfig(array $config): void
    {
        if (empty($config['smtp_enabled'])) {
            throw new MailerException(
                'SMTP n\'est pas activé. Définissez SMTP_ENABLED=true dans les variables d\'environnement.'
            );
        }
        
        $required = ['smtp_host', 'smtp_username', 'smtp_password'];
        $missing = [];
        
        foreach ($required as $key) {
            if (empty($config[$key])) {
                $missing[] = strtoupper($key);
            }
        }
        
        if (!empty($missing)) {
            error_log('Configuration SMTP incomplète. Variables manquantes: ' . implode(', ', $missing));
            throw new MailerException(
                'Configuration SMTP incomplète. Variables d\'environnement manquantes: ' . implode(', ', $missing) . 
                '. Consultez la documentation pour configurer SMTP correctement.'
            );
        }
        
        // Validation du port
        $port = (int)($config['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            throw new MailerException('Port SMTP invalide: ' . $port);
        }
        
        // Validation de smtp_secure
        $secure = $config['smtp_secure'] ?? 'tls';
        if (!in_array($secure, ['tls', 'ssl'], true)) {
            throw new MailerException('SMTP_SECURE doit être "tls" ou "ssl", reçu: ' . $secure);
        }
    }
}

