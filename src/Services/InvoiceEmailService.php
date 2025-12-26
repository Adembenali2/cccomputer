<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerService;
use App\Mail\MailerException;
use PDO;
use RuntimeException;

/**
 * Service centralisé pour l'envoi automatique de factures par email
 * 
 * Gère :
 * - Envoi automatique après génération de facture
 * - Idempotence (évite double envoi)
 * - Logs dans table email_logs
 * - Gestion d'erreurs avec retry optionnel
 * - Email HTML avec fallback texte
 */
class InvoiceEmailService
{
    private PDO $pdo;
    private array $config;
    private bool $autoSendEnabled;
    private bool $retryEnabled;
    private int $sendDelay;
    
    public function __construct(PDO $pdo, array $appConfig)
    {
        $this->pdo = $pdo;
        $this->config = $appConfig;
        
        // Configuration depuis variables d'environnement avec filter_var pour bool
        $this->autoSendEnabled = filter_var(
            $_ENV['AUTO_SEND_INVOICES'] ?? $appConfig['auto_send_invoices'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $this->retryEnabled = filter_var(
            $_ENV['AUTO_SEND_INVOICES_RETRY'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $this->sendDelay = (int)($_ENV['AUTO_SEND_INVOICES_DELAY'] ?? 0);
    }
    
    /**
     * Envoie automatiquement une facture par email après sa génération
     * 
     * @param int $factureId ID de la facture
     * @param bool $force Forcer l'envoi même si email_envoye = 1
     * @return array ['success' => bool, 'message' => string, 'log_id' => int|null, 'message_id' => string|null]
     * @throws RuntimeException En cas d'erreur critique
     */
    public function sendInvoiceAfterGeneration(int $factureId, bool $force = false): array
    {
        // Vérifier si l'envoi automatique est activé
        if (!$this->autoSendEnabled && !$force) {
            error_log("[InvoiceEmailService] Envoi automatique désactivé (AUTO_SEND_INVOICES=false)");
            return [
                'success' => false,
                'message' => 'Envoi automatique désactivé',
                'log_id' => null,
                'message_id' => null
            ];
        }
        
        // Délai optionnel avant envoi
        if ($this->sendDelay > 0) {
            sleep($this->sendDelay);
        }
        
        $logId = null;
        $pdfPath = null;
        $isTemporaryPdf = false;
        
        try {
            // ============================================
            // ÉTAPE A : Transaction courte - Préparation
            // ============================================
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("
                SELECT 
                    f.id, f.numero, f.date_facture, f.montant_ttc, f.pdf_path, 
                    f.pdf_genere, f.email_envoye, f.date_envoi_email,
                    f.id_client, f.type, f.montant_ht, f.tva,
                    c.raison_sociale as client_nom, 
                    c.email as client_email,
                    c.adresse, c.code_postal, c.ville, c.siret
                FROM factures f
                LEFT JOIN clients c ON f.id_client = c.id
                WHERE f.id = :id
                FOR UPDATE
                LIMIT 1
            ");
            $stmt->execute([':id' => $factureId]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$facture) {
                $this->pdo->rollBack();
                throw new RuntimeException("Facture introuvable: #{$factureId}");
            }
            
            // Vérifier idempotence (éviter double envoi)
            if (!$force && !empty($facture['email_envoye'])) {
                $this->pdo->rollBack();
                error_log("[InvoiceEmailService] Facture #{$factureId} déjà envoyée (email_envoye=1)");
                return [
                    'success' => false,
                    'message' => 'Facture déjà envoyée',
                    'log_id' => null,
                    'message_id' => null
                ];
            }
            
            // Vérifier que le client a un email
            $clientEmail = trim($facture['client_email'] ?? '');
            if (empty($clientEmail) || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $this->pdo->rollBack();
                error_log("[InvoiceEmailService] Email client invalide pour facture #{$factureId}: " . ($clientEmail ?: 'vide'));
                return [
                    'success' => false,
                    'message' => 'Email client invalide ou manquant',
                    'log_id' => null,
                    'message_id' => null
                ];
            }
            
            // Créer l'entrée de log AVANT l'envoi (statut=pending)
            $logId = $this->createEmailLog($factureId, $clientEmail, "Facture {$facture['numero']} - CC Computer");
            
            // COMMIT de la transaction courte (pas de SMTP dans la transaction)
            $this->pdo->commit();
            
            // ============================================
            // ÉTAPE B : Envoi SMTP HORS transaction
            // ============================================
            
            // Vérifier que le PDF existe ou peut être généré
            if (!empty($facture['pdf_path']) && $facture['pdf_genere']) {
                // Essayer de trouver le PDF existant
                try {
                    $pdfPath = MailerService::findPdfPath($facture['pdf_path']);
                } catch (MailerException $e) {
                    error_log("[InvoiceEmailService] PDF introuvable, régénération nécessaire: " . $e->getMessage());
                    $pdfPath = null;
                }
            }
            
            // Si PDF introuvable, régénérer dans /tmp
            if (!$pdfPath) {
                require_once __DIR__ . '/../../API/factures_generate_pdf_content.php';
                
                $client = [
                    'raison_sociale' => $facture['client_nom'] ?? '',
                    'adresse' => $facture['adresse'] ?? '',
                    'code_postal' => $facture['code_postal'] ?? '',
                    'ville' => $facture['ville'] ?? '',
                    'siret' => $facture['siret'] ?? ''
                ];
                
                $tmpDir = sys_get_temp_dir();
                $pdfPath = generateInvoicePdf($this->pdo, $factureId, $facture, $client, $tmpDir);
                $isTemporaryPdf = true;
                error_log("[InvoiceEmailService] PDF régénéré dans /tmp: {$pdfPath}");
            }
            
            // Préparer le service Mailer
            $emailConfig = $this->config['email'] ?? [];
            $mailerService = new MailerService($emailConfig);
            
            // Préparer le sujet et les messages (texte + HTML)
            $sujet = "Facture {$facture['numero']} - CC Computer";
            $textBody = $this->buildEmailBody($facture);
            $htmlBody = $this->buildEmailHtmlBody($facture);
            
            // Envoyer l'email (HORS transaction)
            $messageId = null;
            try {
                $fileName = basename($facture['pdf_path'] ?? 'facture_' . $facture['numero'] . '.pdf');
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                
                $messageId = $mailerService->sendEmailWithPdf(
                    $clientEmail,
                    $sujet,
                    $textBody,
                    $pdfPath,
                    $fileName,
                    $htmlBody
                );
                
                // ============================================
                // ÉTAPE C : Transaction courte - Mise à jour succès
                // ============================================
                $this->pdo->beginTransaction();
                
                // Mettre à jour la facture
                $stmt = $this->pdo->prepare("
                    UPDATE factures 
                    SET email_envoye = 1, date_envoi_email = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $factureId]);
                
                // Mettre à jour le log avec succès
                $stmt = $this->pdo->prepare("
                    UPDATE email_logs 
                    SET statut = 'sent', sent_at = NOW(), message_id = :message_id
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $logId,
                    ':message_id' => $messageId
                ]);
                
                $this->pdo->commit();
                
                // Nettoyer le PDF temporaire
                if ($isTemporaryPdf && file_exists($pdfPath)) {
                    @unlink($pdfPath);
                    error_log("[InvoiceEmailService] PDF temporaire supprimé: {$pdfPath}");
                }
                
                error_log("[InvoiceEmailService] ✅ Facture #{$factureId} envoyée avec succès à {$clientEmail} (Message-ID: {$messageId})");
                
                return [
                    'success' => true,
                    'message' => 'Facture envoyée avec succès',
                    'log_id' => $logId,
                    'message_id' => $messageId,
                    'email' => $clientEmail
                ];
                
            } catch (MailerException $e) {
                // ============================================
                // ÉTAPE D : Transaction courte - Mise à jour échec
                // ============================================
                $errorMessage = $e->getMessage();
                
                // IMPORTANT : Transaction séparée pour mettre à jour le log
                // L'entrée email_logs doit rester même en cas d'erreur
                $this->pdo->beginTransaction();
                
                $stmt = $this->pdo->prepare("
                    UPDATE email_logs 
                    SET statut = 'failed', error_message = :error_message
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $logId,
                    ':error_message' => substr($errorMessage, 0, 1000) // Limiter la taille
                ]);
                
                $this->pdo->commit();
                
                // Nettoyer le PDF temporaire en cas d'erreur
                if ($isTemporaryPdf && file_exists($pdfPath)) {
                    @unlink($pdfPath);
                    error_log("[InvoiceEmailService] PDF temporaire supprimé après erreur: {$pdfPath}");
                }
                
                error_log("[InvoiceEmailService] ❌ Erreur envoi facture #{$factureId}: {$errorMessage}");
                
                return [
                    'success' => false,
                    'message' => 'Erreur lors de l\'envoi: ' . $errorMessage,
                    'log_id' => $logId,
                    'message_id' => null
                ];
            }
            
        } catch (Throwable $e) {
            // Nettoyer toute transaction en cours
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            
            // Si un log a été créé, le marquer comme failed
            if ($logId !== null) {
                try {
                    $this->pdo->beginTransaction();
                    $stmt = $this->pdo->prepare("
                        UPDATE email_logs 
                        SET statut = 'failed', error_message = :error_message
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':id' => $logId,
                        ':error_message' => substr($e->getMessage(), 0, 1000)
                    ]);
                    $this->pdo->commit();
                } catch (Throwable $logError) {
                    error_log("[InvoiceEmailService] ❌ Erreur lors de la mise à jour du log: " . $logError->getMessage());
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }
                }
            }
            
            // Nettoyer le PDF temporaire en cas d'erreur critique
            if ($isTemporaryPdf && $pdfPath && file_exists($pdfPath)) {
                @unlink($pdfPath);
            }
            
            error_log("[InvoiceEmailService] ❌ Erreur critique: " . $e->getMessage());
            error_log("[InvoiceEmailService] Stack trace: " . $e->getTraceAsString());
            
            throw new RuntimeException("Erreur lors de l'envoi automatique de la facture: " . $e->getMessage(), 0, $e);
        }
    }
    
    /**
     * Crée une entrée dans email_logs
     * 
     * @param int|null $factureId ID de la facture
     * @param string $destinataire Email du destinataire
     * @param string $sujet Sujet de l'email
     * @return int ID de l'entrée créée
     */
    private function createEmailLog(?int $factureId, string $destinataire, string $sujet): int
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO email_logs (facture_id, type_email, destinataire, sujet, statut)
            VALUES (:facture_id, 'facture', :destinataire, :sujet, 'pending')
        ");
        $stmt->execute([
            ':facture_id' => $factureId,
            ':destinataire' => $destinataire,
            ':sujet' => $sujet
        ]);
        
        return (int)$this->pdo->lastInsertId();
    }
    
    /**
     * Construit le corps de l'email de facture (version texte)
     * 
     * @param array $facture Données de la facture
     * @return string Corps de l'email (texte)
     */
    private function buildEmailBody(array $facture): string
    {
        $clientNom = $facture['client_nom'] ?? 'Client';
        $numero = $facture['numero'] ?? 'N/A';
        $montantTTC = number_format((float)($facture['montant_ttc'] ?? 0), 2, ',', ' ') . ' €';
        $dateFacture = date('d/m/Y', strtotime($facture['date_facture'] ?? 'now'));
        
        $body = "Bonjour {$clientNom},\n\n";
        $body .= "Veuillez trouver ci-joint la facture {$numero} d'un montant de {$montantTTC} TTC.\n\n";
        $body .= "Date de facturation : {$dateFacture}\n\n";
        $body .= "Cordialement,\n";
        $body .= "CC Computer\n";
        $body .= "Camson Group\n\n";
        $body .= "---\n";
        $body .= "Cet email est envoyé automatiquement. Merci de ne pas y répondre.\n";
        
        return $body;
    }
    
    /**
     * Construit le corps de l'email de facture (version HTML)
     * 
     * @param array $facture Données de la facture
     * @return string Corps de l'email (HTML)
     */
    private function buildEmailHtmlBody(array $facture): string
    {
        $templatePath = __DIR__ . '/../Mail/templates/invoice_email.html';
        
        if (!file_exists($templatePath)) {
            error_log("[InvoiceEmailService] Template HTML introuvable: {$templatePath}, utilisation du texte brut");
            return '';
        }
        
        $template = file_get_contents($templatePath);
        
        // Données pour le template
        $clientNom = htmlspecialchars($facture['client_nom'] ?? 'Client', ENT_QUOTES, 'UTF-8');
        $numero = htmlspecialchars($facture['numero'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $montantTTC = htmlspecialchars(
            number_format((float)($facture['montant_ttc'] ?? 0), 2, ',', ' ') . ' €',
            ENT_QUOTES,
            'UTF-8'
        );
        $dateFacture = htmlspecialchars(
            date('d/m/Y', strtotime($facture['date_facture'] ?? 'now')),
            ENT_QUOTES,
            'UTF-8'
        );
        
        // Variables d'environnement pour le template
        $appUrl = $_ENV['APP_URL'] ?? 'https://cccomputer-production.up.railway.app';
        $brandName = htmlspecialchars('CC Computer', ENT_QUOTES, 'UTF-8');
        $legalName = htmlspecialchars('Camson Group', ENT_QUOTES, 'UTF-8');
        $legalAddress = htmlspecialchars('97, Boulevard Maurice Berteaux - SANNOIS SASU', ENT_QUOTES, 'UTF-8');
        $legalDetails = htmlspecialchars(
            'Siret 947 820 585 00018 RCS Versailles TVA FR81947820585 - www.camsongroup.fr - 01 55 99 00 69',
            ENT_QUOTES,
            'UTF-8'
        );
        
        // Remplacement des placeholders
        $replacements = [
            '{{brand_name}}' => $brandName,
            '{{client_name}}' => $clientNom,
            '{{invoice_number}}' => $numero,
            '{{invoice_date}}' => $dateFacture,
            '{{invoice_total_ttc}}' => $montantTTC,
            '{{site_url}}' => htmlspecialchars($appUrl, ENT_QUOTES, 'UTF-8'),
            '{{legal_name}}' => $legalName,
            '{{legal_address}}' => $legalAddress,
            '{{legal_details}}' => $legalDetails,
        ];
        
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Vérifie si l'envoi automatique est activé
     * 
     * @return bool
     */
    public function isAutoSendEnabled(): bool
    {
        return $this->autoSendEnabled;
    }
}
