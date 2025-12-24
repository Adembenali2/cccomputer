<?php
declare(strict_types=1);

namespace App\Mail;

use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Service réutilisable pour l'envoi d'emails via SMTP
 */
class MailerService
{
    private array $config;
    
    public function __construct(array $emailConfig)
    {
        $this->config = $emailConfig;
    }
    
    /**
     * Envoie un email avec un fichier PDF en pièce jointe
     * 
     * @param string $to Adresse email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $body Corps du message (texte)
     * @param string|null $pdfPath Chemin absolu vers le fichier PDF à attacher
     * @param string|null $pdfFileName Nom du fichier PDF (si null, utilise basename($pdfPath))
     * @return void
     * @throws MailerException En cas d'erreur
     */
    public function sendEmailWithPdf(
        string $to,
        string $subject,
        string $body,
        ?string $pdfPath = null,
        ?string $pdfFileName = null
    ): void {
        // Validation de l'email
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new MailerException('Adresse email invalide: ' . $to);
        }
        
        // Créer l'instance PHPMailer
        $mail = MailerFactory::create($this->config);
        
        try {
            // Destinataire
            $mail->addAddress($to);
            
            // Sujet et corps
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body); // Version texte brut
            
            // Attacher le PDF si fourni
            if ($pdfPath !== null) {
                $this->attachPdf($mail, $pdfPath, $pdfFileName);
            }
            
            // Envoyer
            $mail->send();
            
            error_log("Email envoyé avec succès via PHPMailer à {$to} (Sujet: {$subject})");
            
        } catch (PHPMailerException $e) {
            // Ne pas exposer le mot de passe dans les logs/erreurs
            $errorInfo = $mail->ErrorInfo;
            $errorInfo = preg_replace('/password[=:]\s*\S+/i', 'password=***', $errorInfo);
            
            error_log("Erreur PHPMailer lors de l'envoi à {$to}: " . $errorInfo);
            error_log("Exception PHPMailer: " . $e->getMessage());
            
            throw new MailerException(
                'Erreur lors de l\'envoi de l\'email: ' . $this->sanitizeError($mail->ErrorInfo),
                0,
                $e
            );
        }
    }
    
    /**
     * Envoie un email simple (sans pièce jointe)
     * 
     * @param string $to Adresse email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $body Corps du message
     * @return void
     * @throws MailerException En cas d'erreur
     */
    public function sendEmail(string $to, string $subject, string $body): void
    {
        $this->sendEmailWithPdf($to, $subject, $body, null);
    }
    
    /**
     * Attache un fichier PDF à l'email avec validation
     * 
     * @param \PHPMailer\PHPMailer\PHPMailer $mail Instance PHPMailer
     * @param string $pdfPath Chemin absolu vers le PDF
     * @param string|null $fileName Nom du fichier (optionnel)
     * @return void
     * @throws MailerException Si le fichier est invalide
     */
    private function attachPdf(
        \PHPMailer\PHPMailer\PHPMailer $mail,
        string $pdfPath,
        ?string $fileName = null
    ): void {
        // Vérifier que le fichier existe
        if (!file_exists($pdfPath)) {
            throw new MailerException(
                'Le fichier PDF est introuvable: ' . basename($pdfPath)
            );
        }
        
        // Vérifier que le fichier est lisible
        if (!is_readable($pdfPath)) {
            throw new MailerException(
                'Le fichier PDF n\'est pas accessible en lecture: ' . basename($pdfPath)
            );
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($pdfPath, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new MailerException(
                'Le fichier doit être un PDF, extension reçue: ' . $extension
            );
        }
        
        // Vérifier la taille (limite recommandée: 10MB pour les emails)
        $fileSize = filesize($pdfPath);
        $maxSize = 10 * 1024 * 1024; // 10MB
        
        if ($fileSize > $maxSize) {
            throw new MailerException(
                sprintf(
                    'Le fichier PDF est trop volumineux (%s). Taille maximale: %s',
                    $this->formatBytes($fileSize),
                    $this->formatBytes($maxSize)
                )
            );
        }
        
        // Nettoyer le nom du fichier
        if ($fileName === null) {
            $fileName = basename($pdfPath);
        }
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
        
        // Attacher le fichier
        try {
            $mail->addAttachment($pdfPath, $fileName, 'base64', 'application/pdf');
        } catch (PHPMailerException $e) {
            throw new MailerException(
                'Erreur lors de l\'ajout de la pièce jointe: ' . $e->getMessage(),
                0,
                $e
            );
        }
    }
    
    /**
     * Nettoie les messages d'erreur pour ne pas exposer de secrets
     * 
     * @param string $error Message d'erreur brut
     * @return string Message nettoyé
     */
    private function sanitizeError(string $error): string
    {
        // Masquer les mots de passe
        $error = preg_replace('/password[=:]\s*\S+/i', 'password=***', $error);
        $error = preg_replace('/pwd[=:]\s*\S+/i', 'pwd=***', $error);
        
        // Masquer les tokens/keys sensibles
        $error = preg_replace('/key[=:]\s*\S+/i', 'key=***', $error);
        $error = preg_replace('/token[=:]\s*\S+/i', 'token=***', $error);
        
        return $error;
    }
    
    /**
     * Formate une taille en bytes en format lisible
     * 
     * @param int $bytes Taille en bytes
     * @return string Taille formatée
     */
    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        
        return round($bytes, 2) . ' ' . $units[$pow];
    }
    
    /**
     * Trouve le chemin absolu d'un fichier PDF à partir de son chemin relatif
     * Utilise la même logique que generateFacturePDF pour la compatibilité
     * Protection contre path traversal (../../etc/passwd)
     * 
     * @param string $relativePath Chemin relatif du PDF (ex: /uploads/factures/2025/facture_xxx.pdf)
     * @return string Chemin absolu trouvé
     * @throws MailerException Si le fichier est introuvable ou si path traversal détecté
     */
    public static function findPdfPath(string $relativePath): string
    {
        if (empty($relativePath)) {
            throw new MailerException('Le chemin du PDF est vide');
        }
        
        // Protection contre path traversal
        // Normaliser le chemin et vérifier qu'il ne contient pas de ../
        $normalized = str_replace('\\', '/', $relativePath);
        $normalized = preg_replace('#/+#', '/', $normalized); // Supprimer les doubles slashes
        $normalized = ltrim($normalized, '/');
        
        // Vérifier qu'il n'y a pas de path traversal
        if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
            error_log("Tentative de path traversal détectée: " . $relativePath);
            throw new MailerException('Chemin PDF invalide: tentative de path traversal détectée');
        }
        
        // Vérifier que le chemin commence par uploads/factures (sécurité supplémentaire)
        if (!preg_match('#^uploads/factures/#', $normalized)) {
            error_log("Chemin PDF hors du répertoire autorisé: " . $relativePath);
            throw new MailerException('Le fichier PDF doit être dans le répertoire uploads/factures/');
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new MailerException('Le fichier doit être un PDF, extension reçue: ' . $extension);
        }
        
        $possibleBaseDirs = [];
        
        // 1. DOCUMENT_ROOT (le plus fiable)
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot !== '' && is_dir($docRoot)) {
            $possibleBaseDirs[] = $docRoot;
        }
        
        // 2. Répertoire du projet (dirname(__DIR__))
        $projectDir = dirname(__DIR__, 2); // Remonter de src/Mail vers la racine
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
        
        // Essayer chaque répertoire de base
        foreach ($possibleBaseDirs as $baseDir) {
            $testPath = $baseDir . '/' . $normalized;
            
            // Vérifier que le chemin résolu est bien dans le répertoire de base (protection finale)
            $realPath = realpath($testPath);
            $realBase = realpath($baseDir);
            
            if ($realPath && $realBase && strpos($realPath, $realBase) === 0) {
                if (file_exists($realPath) && is_readable($realPath)) {
                    return $realPath;
                }
            }
        }
        
        // Si toujours pas trouvé, essayer depuis le répertoire API
        $apiDir = dirname(__DIR__, 2) . '/API';
        if (is_dir($apiDir)) {
            $testPath = dirname($apiDir) . '/' . $normalized;
            $realPath = realpath($testPath);
            if ($realPath && file_exists($realPath) && is_readable($realPath)) {
                return $realPath;
            }
        }
        
        // Erreur claire si introuvable
        $debugInfo = [
            'chemin_demande' => $relativePath,
            'chemin_normalise' => $normalized,
            'repertoires_testes' => $possibleBaseDirs
        ];
        error_log("PDF introuvable: " . json_encode($debugInfo));
        
        throw new MailerException(
            'Le fichier PDF est introuvable sur le serveur. ' .
            'Chemin enregistré: ' . basename($relativePath) . '. ' .
            'Le fichier a peut-être été supprimé ou déplacé. Veuillez régénérer la facture.'
        );
    }
}

