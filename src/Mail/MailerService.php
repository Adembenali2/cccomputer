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
     * @param string $textBody Corps du message (texte)
     * @param string|null $pdfPath Chemin absolu vers le fichier PDF à attacher
     * @param string|null $pdfFileName Nom du fichier PDF (si null, utilise basename($pdfPath))
     * @param string|null $htmlBody Corps du message HTML (optionnel)
     * @return string Message-ID généré et assigné à l'email
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
        
        // Créer l'instance PHPMailer
        $mail = MailerFactory::create($this->config);
        
        try {
            // Destinataire
            $mail->addAddress($to);
            
            // Sujet
            $mail->Subject = $subject;
            
            // Corps HTML ou texte
            if (!empty($htmlBody)) {
                $mail->isHTML(true);
                $mail->Body = $htmlBody;
                $mail->AltBody = $textBody;
            } else {
                $mail->isHTML(false);
                $mail->Body = $textBody;
                $mail->AltBody = $textBody;
            }
            
            // Générer et assigner un Message-ID unique
            $messageId = $this->generateMessageId();
            $mail->MessageID = $messageId;
            
            // Attacher le PDF si fourni
            if ($pdfPath !== null) {
                $this->attachPdf($mail, $pdfPath, $pdfFileName);
            }
            
            // Envoyer
            $mail->send();
            
            error_log("Email envoyé avec succès via PHPMailer à {$to} (Sujet: {$subject}, Message-ID: {$messageId})");
            
            return $messageId;
            
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
     * Génère un Message-ID unique conforme RFC 5322
     * Format: <timestamp.random@domain>
     * 
     * @return string Message-ID
     */
    private function generateMessageId(): string
    {
        $domain = $_ENV['MAIL_MESSAGE_ID_DOMAIN'] ?? 'cccomputer.fr';
        $timestamp = time();
        $random = bin2hex(random_bytes(8));
        
        return sprintf('<%d.%s@%s>', $timestamp, $random, $domain);
    }
    
    /**
     * Envoie un email simple (sans pièce jointe)
     * 
     * @param string $to Adresse email du destinataire
     * @param string $subject Sujet de l'email
     * @param string $body Corps du message
     * @param string|null $htmlBody Corps HTML optionnel
     * @return string Message-ID généré
     * @throws MailerException En cas d'erreur
     */
    public function sendEmail(string $to, string $subject, string $body, ?string $htmlBody = null): string
    {
        return $this->sendEmailWithPdf($to, $subject, $body, null, null, $htmlBody);
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
     * Trouve le chemin absolu d'un fichier PDF à partir de son chemin relatif ou nom de fichier
     * Utilise la même logique que generateFacturePDF pour la compatibilité
     * Protection contre path traversal (../../etc/passwd)
     * Gère les cas : chemin complet (/uploads/factures/2025/facture_xxx.pdf) ou juste le nom (facture_xxx.pdf)
     * 
     * @param string $relativePath Chemin relatif du PDF ou nom de fichier
     * @return string Chemin absolu trouvé
     * @throws MailerException Si le fichier est introuvable ou si path traversal détecté
     */
    public static function findPdfPath(string $relativePath): string
    {
        if (empty($relativePath)) {
            throw new MailerException('Le chemin du PDF est vide');
        }
        
        $originalPath = $relativePath;
        error_log("findPdfPath() - Recherche PDF: " . $originalPath);
        
        // Protection contre path traversal
        $normalized = str_replace('\\', '/', $relativePath);
        $normalized = preg_replace('#/+#', '/', $normalized);
        $normalized = ltrim($normalized, '/');
        
        // Vérifier qu'il n'y a pas de path traversal
        if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
            error_log("findPdfPath() - Tentative de path traversal détectée: " . $originalPath);
            throw new MailerException('Chemin PDF invalide: tentative de path traversal détectée');
        }
        
        // Vérifier l'extension
        $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
        if ($extension !== 'pdf') {
            throw new MailerException('Le fichier doit être un PDF, extension reçue: ' . $extension);
        }
        
        // Extraire le nom du fichier
        $fileName = basename($normalized);
        
        // Vérifier que le nom du fichier est valide (pas de path traversal)
        if (strpos($fileName, '/') !== false || strpos($fileName, '\\') !== false) {
            error_log("findPdfPath() - Nom de fichier invalide (contient des slashes): " . $fileName);
            throw new MailerException('Nom de fichier PDF invalide');
        }
        
        // Si le chemin ne commence pas par uploads/factures/, c'est probablement juste un nom de fichier
        // On va chercher dans toutes les années possibles
        $searchPaths = [];
        
        if (preg_match('#^uploads/factures/(\d{4})/#', $normalized, $matches)) {
            // Chemin complet avec année
            $year = $matches[1];
            $searchPaths[] = "uploads/factures/{$year}/{$fileName}";
        } elseif (preg_match('#^uploads/factures/#', $normalized)) {
            // Chemin qui commence par uploads/factures/ mais sans année explicite
            // Essayer avec l'année actuelle et les années proches
            $currentYear = date('Y');
            for ($y = $currentYear - 2; $y <= $currentYear + 1; $y++) {
                $searchPaths[] = "uploads/factures/{$y}/{$fileName}";
            }
            // Aussi essayer le chemin tel quel
            $searchPaths[] = $normalized;
        } else {
            // Juste le nom du fichier - chercher dans toutes les années possibles
            $currentYear = date('Y');
            // Chercher dans les 5 dernières années et l'année suivante
            for ($y = $currentYear - 5; $y <= $currentYear + 1; $y++) {
                $searchPaths[] = "uploads/factures/{$y}/{$fileName}";
            }
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
        
        // Log des chemins de recherche
        error_log("findPdfPath() - Nom fichier: " . $fileName);
        error_log("findPdfPath() - Chemins à tester: " . implode(', ', $searchPaths));
        error_log("findPdfPath() - Répertoires de base: " . implode(', ', $possibleBaseDirs));
        
        $testedPaths = [];
        
        // Essayer chaque combinaison répertoire de base + chemin de recherche
        foreach ($possibleBaseDirs as $baseDir) {
            $realBase = realpath($baseDir);
            if (!$realBase) {
                continue;
            }
            
            foreach ($searchPaths as $searchPath) {
                $testPath = $realBase . '/' . $searchPath;
                $testedPaths[] = $testPath;
                
                // Vérifier que le chemin résolu est bien dans le répertoire de base (protection finale)
                $realPath = realpath($testPath);
                
                if ($realPath && strpos($realPath, $realBase) === 0) {
                    if (file_exists($realPath) && is_readable($realPath)) {
                        error_log("findPdfPath() - PDF trouvé: " . $realPath);
                        return $realPath;
                    }
                }
            }
        }
        
        // Log détaillé des chemins testés
        error_log("findPdfPath() - PDF introuvable après " . count($testedPaths) . " tentatives");
        error_log("findPdfPath() - Chemins testés: " . implode("\n  - ", array_slice($testedPaths, 0, 20))); // Limiter à 20 pour éviter les logs trop longs
        
        // Erreur claire si introuvable
        throw new MailerException(
            'Le fichier PDF est introuvable sur le serveur. ' .
            'Nom du fichier: ' . $fileName . '. ' .
            'Le fichier a peut-être été supprimé ou déplacé. Veuillez régénérer la facture.'
        );
    }
}

