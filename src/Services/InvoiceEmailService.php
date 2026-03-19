<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerService;
use App\Mail\BrevoApiMailerService;
use App\Mail\MailerException;
use PDO;
use RuntimeException;

/**
 * Service centralisé pour l'envoi automatique de factures par email
 * 
 * Gère :
 * - Envoi automatique après génération de facture
 * - Idempotence (évite double envoi) avec mécanisme de "claim" atomique
 * - Protection contre requêtes concurrentes
 * - Protection contre factures bloquées (stuck) en email_envoye=2
 * - Logs dans table email_logs
 * - Gestion d'erreurs avec retry optionnel
 * - Email HTML avec fallback texte
 * 
 * Statut email_envoye :
 * - 0 = non envoyé (disponible pour envoi)
 * - 2 = en cours d'envoi (claimé par une requête)
 * - 1 = envoyé (succès)
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
     * @param bool $force Forcer l'envoi même si email_envoye = 1 (bypass le claim, mais refuse si email_envoye=2)
     * @param string|null $emailOverride Email destinataire (si fourni et valide, remplace l'email client)
     * @param string|null $sujetOverride Sujet personnalisé
     * @param string|null $messageOverride Message additionnel pour le corps
     * @return array ['success' => bool, 'message' => string, 'log_id' => int|null, 'message_id' => string|null]
     * @throws RuntimeException En cas d'erreur critique
     */
    public function sendInvoiceAfterGeneration(
        int $factureId,
        bool $force = false,
        ?string $emailOverride = null,
        ?string $sujetOverride = null,
        ?string $messageOverride = null
    ): array
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
            // ÉTAPE A : Transaction courte - Claim atomique
            // ============================================
            $this->pdo->beginTransaction();
            
            // SELECT avec FOR UPDATE pour verrouiller la ligne
            // ORDER BY requis pour MySQL 8 avec FOR UPDATE + LIMIT
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
                ORDER BY f.id
                LIMIT 1
                FOR UPDATE
            ");
            $stmt->execute([':id' => $factureId]);
            $facture = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$facture) {
                $this->pdo->rollBack();
                throw new RuntimeException("Facture introuvable: #{$factureId}");
            }
            
            $currentStatus = (int)($facture['email_envoye'] ?? 0);
            
            // MÉCANISME DE CLAIM ATOMIQUE
            $claimSuccess = false;
            
            if ($force) {
                // Mode force : on peut envoyer même si déjà envoyé (email_envoye=1)
                // MAIS on refuse si email_envoye=2 (déjà en cours) pour éviter 2 envois simultanés
                if ($currentStatus === 2) {
                    $this->pdo->rollBack();
                    error_log("[InvoiceEmailService] Mode force refusé pour facture #{$factureId} : email_envoye=2 (déjà en cours)");
                    return [
                        'success' => false,
                        'message' => 'Facture déjà en cours d\'envoi. Mode force refusé pour éviter double envoi.',
                        'log_id' => null,
                        'message_id' => null
                    ];
                }
                
                // Si email_envoye=1, on peut forcer un nouvel envoi
                // On met à 2 pour indiquer qu'on est en cours
                $stmt = $this->pdo->prepare("
                    UPDATE factures 
                    SET email_envoye = 2 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $factureId]);
                $claimSuccess = true;
                error_log("[InvoiceEmailService] Mode force activé pour facture #{$factureId} (email_envoye={$currentStatus} → 2)");
            } else {
                // Mode normal : claim atomique uniquement si email_envoye = 0
                if ($currentStatus === 0) {
                    // Tentative de claim normal
                    $stmt = $this->pdo->prepare("
                        UPDATE factures 
                        SET email_envoye = 2 
                        WHERE id = :id AND email_envoye = 0
                    ");
                    $stmt->execute([':id' => $factureId]);
                    
                    if ($stmt->rowCount() > 0) {
                        $claimSuccess = true;
                        error_log("[InvoiceEmailService] ✅ Claim réussi pour facture #{$factureId} (email_envoye=0 → 2)");
                    }
                } elseif ($currentStatus === 2) {
                    // Facture en cours (email_envoye=2) : vérifier si stuck
                    $isStuck = $this->isFactureStuck($factureId);
                    
                    if ($isStuck) {
                        // Facture bloquée : réinitialiser et refaire le claim
                        error_log("[InvoiceEmailService] 🔓 Facture #{$factureId} détectée comme stuck, réinitialisation...");
                        
                        // Remettre email_envoye à 0
                        $stmt = $this->pdo->prepare("
                            UPDATE factures 
                            SET email_envoye = 0 
                            WHERE id = :id
                        ");
                        $stmt->execute([':id' => $factureId]);
                        
                        // Marquer le log pending précédent comme failed
                        $this->markStuckLogAsFailed($factureId);
                        
                        // Refaire le claim
                        $stmt = $this->pdo->prepare("
                            UPDATE factures 
                            SET email_envoye = 2 
                            WHERE id = :id AND email_envoye = 0
                        ");
                        $stmt->execute([':id' => $factureId]);
                        
                        if ($stmt->rowCount() > 0) {
                            $claimSuccess = true;
                            error_log("[InvoiceEmailService] ✅ Claim réussi après réinitialisation stuck pour facture #{$factureId}");
                        } else {
                            // Race condition : une autre requête a pris le claim entre temps
                            $this->pdo->rollBack();
                            error_log("[InvoiceEmailService] ⚠️ Claim échoué après réinitialisation stuck (race condition) pour facture #{$factureId}");
                            return [
                                'success' => false,
                                'message' => 'Facture réinitialisée mais claim échoué (race condition)',
                                'log_id' => null,
                                'message_id' => null
                            ];
                        }
                    } else {
                        // Facture en cours mais pas stuck : refuser
                        $this->pdo->rollBack();
                        error_log("[InvoiceEmailService] Facture #{$factureId} déjà en cours d'envoi (email_envoye=2, pas stuck)");
                        return [
                            'success' => false,
                            'message' => 'Facture déjà en cours d\'envoi par une autre requête',
                            'log_id' => null,
                            'message_id' => null
                        ];
                    }
                } elseif ($currentStatus === 1) {
                    // Facture déjà envoyée
                    $this->pdo->rollBack();
                    error_log("[InvoiceEmailService] Facture #{$factureId} déjà envoyée (email_envoye=1)");
                    return [
                        'success' => false,
                        'message' => 'Facture déjà envoyée',
                        'log_id' => null,
                        'message_id' => null
                    ];
                }
                
                // Si claim échoué (cas inattendu)
                if (!$claimSuccess) {
                    $this->pdo->rollBack();
                    error_log("[InvoiceEmailService] Facture #{$factureId} claim échoué (statut inattendu: {$currentStatus})");
                    return [
                        'success' => false,
                        'message' => 'Impossible de réserver la facture pour envoi',
                        'log_id' => null,
                        'message_id' => null
                    ];
                }
            }
            
            // Déterminer l'email destinataire : override si fourni et valide, sinon email client
            $clientEmail = trim($facture['client_email'] ?? '');
            $destEmail = null;
            if (!empty($emailOverride) && filter_var(trim($emailOverride), FILTER_VALIDATE_EMAIL)) {
                $destEmail = trim($emailOverride);
            } elseif (!empty($clientEmail) && filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
                $destEmail = $clientEmail;
            }
            if (empty($destEmail)) {
                // Remettre email_envoye à 0 en cas d'erreur de validation
                $stmt = $this->pdo->prepare("UPDATE factures SET email_envoye = 0 WHERE id = :id");
                $stmt->execute([':id' => $factureId]);
                $this->pdo->rollBack();
                
                error_log("[InvoiceEmailService] Email invalide pour facture #{$factureId}: " . ($emailOverride ?: $clientEmail ?: 'vide'));
                return [
                    'success' => false,
                    'message' => 'Email destinataire invalide ou manquant',
                    'log_id' => null,
                    'message_id' => null
                ];
            }
            
            $sujetLog = $sujetOverride ?: "Facture {$facture['numero']} - CC Computer";
            // Créer l'entrée de log SEULEMENT si le claim a réussi
            $logId = $this->createEmailLog($factureId, $destEmail, $sujetLog);
            
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
            
            // Préparer le sujet et les messages (texte + HTML)
            $sujet = $sujetOverride ?: "Facture {$facture['numero']} - CC Computer";
            $textBody = $this->buildEmailBody($facture);
            $htmlBody = $this->buildEmailHtmlBody($facture);
            if (!empty($messageOverride)) {
                $msg = trim($messageOverride);
                $textBody .= "\n\n---\n" . $msg;
                $htmlBody .= '<br><br><hr><p>' . nl2br(htmlspecialchars($msg, ENT_QUOTES, 'UTF-8')) . '</p>';
            }
            
            // Envoyer l'email (HORS transaction)
            // Détecter si Brevo API est disponible, sinon fallback SMTP
            $messageId = null;
            try {
                $fileName = basename($facture['pdf_path'] ?? 'facture_' . $facture['numero'] . '.pdf');
                $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
                
                // Utiliser Brevo API si BREVO_API_KEY est défini, sinon SMTP
                if (!empty($_ENV['BREVO_API_KEY'])) {
                    error_log("[InvoiceEmailService] Utilisation de l'API Brevo pour l'envoi");
                    $brevoService = new BrevoApiMailerService();
                    $messageId = $brevoService->sendEmailWithPdf(
                        $destEmail,
                        $sujet,
                        $textBody,
                        $pdfPath,
                        $fileName,
                        $htmlBody
                    );
                } else {
                    error_log("[InvoiceEmailService] Utilisation de SMTP (fallback)");
                    $emailConfig = $this->config['email'] ?? [];
                    $mailerService = new MailerService($emailConfig);
                    $messageId = $mailerService->sendEmailWithPdf(
                        $destEmail,
                        $sujet,
                        $textBody,
                        $pdfPath,
                        $fileName,
                        $htmlBody
                    );
                }
                
                // ============================================
                // ÉTAPE C : Transaction courte - Mise à jour succès
                // ============================================
                $this->pdo->beginTransaction();
                
                // Mettre à jour la facture : email_envoye = 1 (succès) et statut = 'envoyee'
                $stmt = $this->pdo->prepare("
                    UPDATE factures 
                    SET email_envoye = 1, date_envoi_email = NOW(), statut = 'envoyee' 
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
                
                error_log("[InvoiceEmailService] ✅ Facture #{$factureId} envoyée avec succès à {$destEmail} (Message-ID: {$messageId})");
                
                return [
                    'success' => true,
                    'message' => 'Facture envoyée avec succès',
                    'log_id' => $logId,
                    'message_id' => $messageId,
                    'email' => $destEmail
                ];
                
            } catch (MailerException $e) {
                // ============================================
                // ÉTAPE D : Transaction courte - Mise à jour échec
                // ============================================
                $errorMessage = $e->getMessage();
                
                // IMPORTANT : Transaction séparée pour mettre à jour le log et remettre email_envoye à 0
                // Remettre email_envoye à 0 permet le retry
                $this->pdo->beginTransaction();
                
                // Remettre email_envoye à 0 pour permettre retry
                $stmt = $this->pdo->prepare("
                    UPDATE factures 
                    SET email_envoye = 0 
                    WHERE id = :id
                ");
                $stmt->execute([':id' => $factureId]);
                
                // Mettre à jour le log avec échec
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
                
                error_log("[InvoiceEmailService] ❌ Erreur envoi facture #{$factureId}: {$errorMessage} (email_envoye remis à 0 pour retry)");
                
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
                    
                    // Remettre email_envoye à 0 si on avait réussi le claim
                    $stmt = $this->pdo->prepare("UPDATE factures SET email_envoye = 0 WHERE id = :id");
                    $stmt->execute([':id' => $factureId]);
                    
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
     * Vérifie si une facture est bloquée (stuck) en email_envoye=2
     * 
     * Une facture est considérée comme stuck si :
     * - email_envoye = 2
     * - Il existe un email_logs avec statut='pending' pour cette facture
     * - Le log pending a été créé il y a plus de 15 minutes
     * 
     * @param int $factureId ID de la facture
     * @return bool True si la facture est stuck, false sinon
     */
    private function isFactureStuck(int $factureId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                el.id,
                el.created_at,
                TIMESTAMPDIFF(MINUTE, el.created_at, NOW()) as minutes_ago
            FROM email_logs el
            WHERE el.facture_id = :facture_id
              AND el.statut = 'pending'
            ORDER BY el.created_at DESC
            LIMIT 1
        ");
        $stmt->execute([':facture_id' => $factureId]);
        $pendingLog = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$pendingLog) {
            // Pas de log pending : la facture n'est pas stuck
            return false;
        }
        
        $minutesAgo = (int)($pendingLog['minutes_ago'] ?? 0);
        
        // Stuck si le log pending a plus de 15 minutes
        $isStuck = $minutesAgo >= 15;
        
        if ($isStuck) {
            error_log("[InvoiceEmailService] 🔍 Facture #{$factureId} détectée comme stuck : log pending créé il y a {$minutesAgo} minutes");
        }
        
        return $isStuck;
    }
    
    /**
     * Marque les logs pending stuck comme failed
     * 
     * @param int $factureId ID de la facture
     * @return void
     */
    private function markStuckLogAsFailed(int $factureId): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE email_logs 
            SET statut = 'failed', 
                error_message = 'Facture stuck détectée (process crash/timeout), réinitialisée pour retry'
            WHERE facture_id = :facture_id
              AND statut = 'pending'
              AND created_at < NOW() - INTERVAL 15 MINUTE
        ");
        $stmt->execute([':facture_id' => $factureId]);
        
        $rowsAffected = $stmt->rowCount();
        if ($rowsAffected > 0) {
            error_log("[InvoiceEmailService] 📝 {$rowsAffected} log(s) pending marqué(s) comme failed pour facture #{$factureId}");
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
        
        // Récupérer les informations de configuration
        $companyConfig = $this->config['company'] ?? [];
        $companyAddress = $companyConfig['address'] ?? '7 Rue Fraizier, 93210 Saint-Denis';
        $billingContactEmail = $companyConfig['billing_contact_email'] ?? 'facturemail@cccomputer.fr';
        
        $body = "Bonjour {$clientNom},\n\n";
        $body .= "Veuillez trouver ci-joint la facture {$numero} d'un montant de {$montantTTC} TTC.\n\n";
        $body .= "Date de facturation : {$dateFacture}\n\n";
        $body .= "Cordialement,\n";
        $body .= "CC Computer\n\n";
        $body .= "---\n";
        $body .= "CC Computer\n";
        $body .= "{$companyAddress}\n";
        $body .= "Contact : {$billingContactEmail}\n\n";
        $body .= "Cet email est envoyé automatiquement. Merci de ne pas y répondre directement.\n";
        $body .= "Pour toute question, contactez-nous par email.\n";
        
        return $body;
    }
    
    /**
     * Récupère le nom du dirigeant : config > BDD (premier Dirigeant actif) > nom société
     */
    private function getDirectorFullName(): string
    {
        $companyConfig = $this->config['company'] ?? [];
        $fromConfig = trim($companyConfig['director_full_name'] ?? '');
        $placeholder = 'NOM PRENOM DIRIGEANT';

        if ($fromConfig !== '' && stripos($fromConfig, $placeholder) === false) {
            return $fromConfig;
        }

        // Récupérer depuis la BDD : premier utilisateur Dirigeant ou Admin actif
        try {
            $stmt = $this->pdo->prepare("
                SELECT CONCAT(COALESCE(prenom,''), ' ', COALESCE(nom,'')) AS full_name
                FROM utilisateurs
                WHERE Emploi IN ('Dirigeant', 'Admin') AND statut = 'actif'
                ORDER BY FIELD(Emploi, 'Dirigeant', 'Admin')
                LIMIT 1
            ");
            $stmt->execute();
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && trim($row['full_name'] ?? '') !== '') {
                return trim($row['full_name']);
            }
        } catch (Throwable $e) {
            error_log("[InvoiceEmailService] Erreur récupération dirigeant: " . $e->getMessage());
        }

        return $companyConfig['name'] ?? 'CC Computer';
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

        $companyConfig = $this->config['company'] ?? [];
        $companyName = $companyConfig['name'] ?? 'CC Computer';
        $companyAddress = trim($companyConfig['address'] ?? '');
        $billingContactEmail = trim($companyConfig['billing_contact_email'] ?? '');

        // Données facture
        $clientNom = htmlspecialchars(trim($facture['client_nom'] ?? '') ?: 'Client', ENT_QUOTES, 'UTF-8');
        $numero = htmlspecialchars($facture['numero'] ?? 'N/A', ENT_QUOTES, 'UTF-8');
        $montantFormatted = number_format((float)($facture['montant_ttc'] ?? 0), 2, ',', ' ');
        $montantTTC = htmlspecialchars($montantFormatted . ' €', ENT_QUOTES, 'UTF-8');
        $dateFacture = htmlspecialchars(
            date('d/m/Y', strtotime($facture['date_facture'] ?? 'now')),
            ENT_QUOTES,
            'UTF-8'
        );

        // Dirigeant : config ou BDD (jamais de placeholder)
        $directorFullName = htmlspecialchars($this->getDirectorFullName(), ENT_QUOTES, 'UTF-8');
        $companyNameEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');

        // Blocs conditionnels : n'afficher que si la valeur est renseignée
        $addressBlock = $companyAddress
            ? '<p class="footer-line">' . htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $contactBlock = $billingContactEmail
            ? '<p class="footer-line"><strong>Contact :</strong> <a href="mailto:' . htmlspecialchars($billingContactEmail, ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($billingContactEmail, ENT_QUOTES, 'UTF-8') . '</a></p>'
            : '';

        $replacements = [
            '{{header_title}}' => $directorFullName,
            '{{company_name}}' => $companyNameEsc,
            '{{client_name}}' => $clientNom,
            '{{invoice_number}}' => $numero,
            '{{invoice_date}}' => $dateFacture,
            '{{invoice_total_ttc}}' => $montantTTC,
            '{{address_block}}' => $addressBlock,
            '{{contact_block}}' => $contactBlock,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
    
    /**
     * Envoie une facture à une adresse email spécifique (pour envois programmés ou manuels)
     * 
     * @param int $factureId ID de la facture
     * @param string|null $emailOverride Email destinataire (si null, utilise l'email du client)
     * @param string|null $sujetOverride Sujet personnalisé (si null, sujet par défaut)
     * @param string|null $messageOverride Message additionnel à ajouter au corps (si null, pas de message)
     * @return array ['success' => bool, 'message' => string, 'log_id' => int|null, 'message_id' => string|null, 'email' => string|null]
     */
    public function sendInvoiceToEmail(
        int $factureId,
        ?string $emailOverride = null,
        ?string $sujetOverride = null,
        ?string $messageOverride = null
    ): array {
        return $this->sendInvoiceAfterGeneration($factureId, true, $emailOverride, $sujetOverride, $messageOverride);
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
