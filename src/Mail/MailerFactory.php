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
            // Mapping tls/ssl vers les constantes PHPMailer
            $secure = strtolower($config['smtp_secure'] ?? 'tls');
            if ($secure === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($secure === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } else {
                throw new MailerException('SMTP_SECURE invalide: ' . $secure . ' (doit être "tls" ou "ssl")');
            }
            
            $mail->Port = (int)$config['smtp_port'];
            $mail->CharSet = 'UTF-8';
            
            // Expéditeur
            $mail->setFrom(
                $config['from_email'],
                $config['from_name'] ?? 'Camson Group - Facturation'
            );
            
            // Reply-To
            $mail->addReplyTo(
                $config['reply_to_email'] ?? $config['from_email'],
                $config['from_name'] ?? 'Camson Group - Facturation'
            );
            
            // Options SSL/TLS - En production, on doit vérifier les certificats
            // Ne désactiver la vérification que si explicitement demandé via variable d'env
            $disableVerify = filter_var($_ENV['SMTP_DISABLE_VERIFY'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($disableVerify) {
                error_log('ATTENTION: Vérification SSL/TLS désactivée pour SMTP (SMTP_DISABLE_VERIFY=true)');
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];
            }
            
            // Timeout SMTP (défaut 15 secondes)
            $smtpTimeout = (int)($_ENV['SMTP_TIMEOUT'] ?? 15);
            if ($smtpTimeout < 1 || $smtpTimeout > 300) {
                $smtpTimeout = 15; // Valeur par défaut si invalide
            }
            $mail->Timeout = $smtpTimeout;
            
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

