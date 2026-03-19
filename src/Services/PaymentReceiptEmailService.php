<?php
declare(strict_types=1);

namespace App\Services;

use App\Mail\MailerService;
use App\Mail\BrevoApiMailerService;
use App\Mail\MailerException;
use PDO;

/**
 * Service pour l'envoi des reçus de paiement par email
 * Utilise le template professionnel receipt_email.html (logo, style facture)
 */
class PaymentReceiptEmailService
{
    private PDO $pdo;
    private array $config;

    public function __construct(PDO $pdo, array $appConfig)
    {
        $this->pdo = $pdo;
        $this->config = $appConfig;
    }

    /**
     * Envoie le reçu de paiement par email au client
     *
     * @param int $paiementId ID du paiement
     * @param string|null $emailOverride Email destinataire (si null, utilise l'email du client)
     * @return array ['success' => bool, 'message' => string, 'message_id' => string|null]
     */
    public function sendReceipt(int $paiementId, ?string $emailOverride = null): array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                p.id, p.id_facture, p.id_client, p.montant, p.date_paiement,
                p.mode_paiement, p.reference, p.commentaire, p.recu_path, p.recu_genere,
                p.email_envoye, p.date_envoi_email,
                c.raison_sociale as client_nom, c.email as client_email,
                c.adresse, c.code_postal, c.ville, c.siret,
                f.numero as facture_numero, f.date_facture as facture_date
            FROM paiements p
            LEFT JOIN clients c ON p.id_client = c.id
            LEFT JOIN factures f ON p.id_facture = f.id
            WHERE p.id = :paiement_id
            LIMIT 1
        ");
        $stmt->execute([':paiement_id' => $paiementId]);
        $paiement = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$paiement) {
            return ['success' => false, 'message' => 'Paiement introuvable', 'message_id' => null];
        }

        $destEmail = null;
        if (!empty($emailOverride) && filter_var(trim($emailOverride), FILTER_VALIDATE_EMAIL)) {
            $destEmail = trim($emailOverride);
        } elseif (!empty($paiement['client_email']) && filter_var($paiement['client_email'], FILTER_VALIDATE_EMAIL)) {
            $destEmail = trim($paiement['client_email']);
        }

        if (empty($destEmail)) {
            return ['success' => false, 'message' => 'Email destinataire invalide ou manquant', 'message_id' => null];
        }

        $recuPath = $paiement['recu_path'];
        $pdfPath = $recuPath ? $this->findRecuPath($recuPath) : null;

        // Si pas de reçu ou fichier introuvable : régénérer le PDF
        if (!$pdfPath) {
            try {
                require_once dirname(__DIR__, 2) . '/API/paiements_generer_recu.php';
                $newRecuPath = generateRecuPDF($this->pdo, $paiementId);
                $stmt = $this->pdo->prepare("UPDATE paiements SET recu_path = :recu_path, recu_genere = 1 WHERE id = :id");
                $stmt->execute([':recu_path' => $newRecuPath, ':id' => $paiementId]);
                $pdfPath = $this->findRecuPath($newRecuPath);
                $recuPath = $newRecuPath;
            } catch (\Throwable $e) {
                error_log('[PaymentReceiptEmailService] Régénération reçu échouée: ' . $e->getMessage());
                return ['success' => false, 'message' => 'Impossible de générer le reçu: ' . $e->getMessage(), 'message_id' => null];
            }
        }

        if (!$pdfPath) {
            return ['success' => false, 'message' => 'Fichier reçu introuvable sur le serveur', 'message_id' => null];
        }

        $sujet = 'Reçu de paiement ' . $paiement['reference'] . ' - ' . ($this->config['company']['name'] ?? 'CC Computer');
        $textBody = $this->buildTextBody($paiement);
        $htmlBody = $this->buildHtmlBody($paiement);
        $fileName = basename($recuPath ?: 'recu.pdf');
        $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);

        try {
            if (!empty($_ENV['BREVO_API_KEY'])) {
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

            $stmt = $this->pdo->prepare("
                UPDATE paiements SET email_envoye = 1, date_envoi_email = NOW() WHERE id = :id
            ");
            $stmt->execute([':id' => $paiementId]);

            return [
                'success' => true,
                'message' => 'Reçu envoyé avec succès',
                'message_id' => $messageId,
                'email' => $destEmail,
            ];
        } catch (MailerException $e) {
            error_log('[PaymentReceiptEmailService] Erreur envoi: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'message_id' => null];
        }
    }

    /**
     * Envoie l'email "paiement reçu, en attente de validation" (virement/chèque)
     * Pas de pièce jointe - le reçu sera envoyé à la validation
     *
     * @param int $paiementId ID du paiement
     * @return array ['success' => bool, 'message' => string, 'message_id' => string|null]
     */
    public function sendPendingValidationEmail(int $paiementId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.montant, p.date_paiement, p.mode_paiement, p.reference,
                c.raison_sociale as client_nom, c.email as client_email
            FROM paiements p
            LEFT JOIN clients c ON p.id_client = c.id
            WHERE p.id = :paiement_id
            LIMIT 1
        ");
        $stmt->execute([':paiement_id' => $paiementId]);
        $paiement = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$paiement) {
            return ['success' => false, 'message' => 'Paiement introuvable', 'message_id' => null];
        }

        $destEmail = trim($paiement['client_email'] ?? '');
        if (empty($destEmail) || !filter_var($destEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Email destinataire invalide ou manquant', 'message_id' => null];
        }

        $modes = [
            'virement' => 'virement bancaire',
            'cheque' => 'chèque',
            'cb' => 'paiement par carte bancaire',
            'especes' => 'paiement en espèces',
            'autre' => 'paiement',
        ];
        $modeLabel = $modes[$paiement['mode_paiement']] ?? 'paiement';
        $modeLabelCap = ucfirst($modeLabel);

        $companyName = $this->config['company']['name'] ?? 'CC Computer';
        $sujet = "Confirmation de réception de votre {$modeLabelCap} - {$paiement['reference']} - {$companyName}";
        $textBody = $this->buildPendingTextBody($paiement, $modeLabelCap);
        $htmlBody = $this->buildPendingHtmlBody($paiement, $modeLabelCap);

        try {
            if (!empty($_ENV['BREVO_API_KEY'])) {
                $brevoService = new BrevoApiMailerService();
                $messageId = $brevoService->sendEmailWithPdf($destEmail, $sujet, $textBody, null, null, $htmlBody);
            } else {
                $emailConfig = $this->config['email'] ?? [];
                $mailerService = new MailerService($emailConfig);
                $messageId = $mailerService->sendEmail($destEmail, $sujet, $textBody, $htmlBody);
            }

            return [
                'success' => true,
                'message' => 'Email de confirmation envoyé',
                'message_id' => $messageId,
                'email' => $destEmail,
            ];
        } catch (MailerException $e) {
            error_log('[PaymentReceiptEmailService] Erreur envoi pending: ' . $e->getMessage());
            return ['success' => false, 'message' => $e->getMessage(), 'message_id' => null];
        }
    }

    private function buildPendingTextBody(array $paiement, string $modeLabelCap): string
    {
        $company = $this->config['company'] ?? [];
        $companyName = $company['name'] ?? 'CC Computer';
        $clientNom = trim($paiement['client_nom'] ?? '') ?: 'Client';
        $montant = number_format((float)$paiement['montant'], 2, ',', ' ') . ' €';
        $date = date('d/m/Y', strtotime($paiement['date_paiement']));

        $body = "Bonjour {$clientNom},\n\n";
        $body .= "Nous vous confirmons avoir bien reçu votre {$modeLabelCap} d'un montant de {$montant}.\n\n";
        $body .= "Votre paiement est actuellement en attente de validation. ";
        $body .= "Dès que nous aurons confirmé la réception effective des fonds, vous recevrez automatiquement votre reçu de paiement par email.\n\n";
        $body .= "Détails : Référence {$paiement['reference']} - Date {$date} - Montant {$montant}\n\n";
        $body .= "Cordialement,\n{$companyName}\n";

        return $body;
    }

    private function buildPendingHtmlBody(array $paiement, string $modeLabelCap): string
    {
        $templatePath = __DIR__ . '/../Mail/templates/payment_pending_email.html';
        if (!file_exists($templatePath)) {
            return '';
        }

        $template = file_get_contents($templatePath);
        $company = $this->config['company'] ?? [];
        $companyName = $company['name'] ?? 'CC Computer';
        $companyAddress = trim($company['address'] ?? '');
        $billingEmail = trim($company['billing_contact_email'] ?? '');
        $appUrl = rtrim($this->config['app_url'] ?? '', '/');

        $modes = [
            'virement' => 'Virement bancaire',
            'cheque' => 'Chèque',
            'cb' => 'Carte bancaire',
            'especes' => 'Espèces',
            'autre' => 'Autre',
        ];
        $modeLibelle = $modes[$paiement['mode_paiement']] ?? $modeLabelCap;

        $clientNom = htmlspecialchars(trim($paiement['client_nom'] ?? '') ?: 'Client', ENT_QUOTES, 'UTF-8');
        $reference = htmlspecialchars($paiement['reference'] ?? '', ENT_QUOTES, 'UTF-8');
        $montant = htmlspecialchars(number_format((float)$paiement['montant'], 2, ',', ' ') . ' €', ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars(date('d/m/Y', strtotime($paiement['date_paiement'])), ENT_QUOTES, 'UTF-8');
        $companyNameEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');

        $addressBlock = $companyAddress
            ? '<p class="footer-line">' . htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $contactBlock = $billingEmail
            ? '<p class="footer-line"><strong>Contact :</strong> <a href="mailto:' . htmlspecialchars($billingEmail, ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($billingEmail, ENT_QUOTES, 'UTF-8') . '</a></p>'
            : '';

        $logoUrl = $appUrl ? $appUrl . '/assets/logos/logo.png' : '';
        $logoBlock = $logoUrl
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $companyNameEsc . '" class="logo-img" width="140" style="max-width:140px;height:auto;">'
            : '<span style="font-size:24px;font-weight:bold;color:#232f3e;">' . $companyNameEsc . '</span>';

        $replacements = [
            '{{logo_block}}' => $logoBlock,
            '{{company_name}}' => $companyNameEsc,
            '{{client_name}}' => $clientNom,
            '{{receipt_reference}}' => $reference,
            '{{receipt_date}}' => $date,
            '{{receipt_amount}}' => $montant,
            '{{payment_mode}}' => htmlspecialchars($modeLibelle, ENT_QUOTES, 'UTF-8'),
            '{{payment_mode_label}}' => htmlspecialchars($modeLabelCap, ENT_QUOTES, 'UTF-8'),
            '{{address_block}}' => $addressBlock,
            '{{contact_block}}' => $contactBlock,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function buildTextBody(array $paiement): string
    {
        $company = $this->config['company'] ?? [];
        $companyName = $company['name'] ?? 'CC Computer';
        $companyAddress = trim($company['address'] ?? '');
        $billingEmail = trim($company['billing_contact_email'] ?? '');

        $modes = [
            'virement' => 'Virement bancaire',
            'cb' => 'Carte bancaire',
            'cheque' => 'Chèque',
            'especes' => 'Espèces',
            'autre' => 'Autre',
        ];
        $modeLibelle = $modes[$paiement['mode_paiement']] ?? $paiement['mode_paiement'];
        $clientNom = trim($paiement['client_nom'] ?? '') ?: 'Client';
        $montant = number_format((float)$paiement['montant'], 2, ',', ' ') . ' €';
        $date = date('d/m/Y', strtotime($paiement['date_paiement']));

        $body = "Bonjour {$clientNom},\n\n";
        $body .= "Nous vous confirmons la réception de votre paiement.\n\n";
        $body .= "Détails du paiement :\n";
        $body .= "- Référence : {$paiement['reference']}\n";
        $body .= "- Date : {$date}\n";
        $body .= "- Montant : {$montant}\n";
        $body .= "- Mode de paiement : {$modeLibelle}\n";
        if (!empty($paiement['facture_numero'])) {
            $body .= "- Facture concernée : {$paiement['facture_numero']}\n";
        }
        $body .= "\nLe reçu de paiement est joint à cet email.\n\n";
        $body .= "Cordialement,\n{$companyName}\n";
        if ($companyAddress) {
            $body .= "{$companyAddress}\n";
        }
        if ($billingEmail) {
            $body .= "Contact : {$billingEmail}\n";
        }

        return $body;
    }

    private function buildHtmlBody(array $paiement): string
    {
        $templatePath = __DIR__ . '/../Mail/templates/receipt_email.html';
        if (!file_exists($templatePath)) {
            return '';
        }

        $template = file_get_contents($templatePath);
        $company = $this->config['company'] ?? [];
        $companyName = $company['name'] ?? 'CC Computer';
        $companyAddress = trim($company['address'] ?? '');
        $billingEmail = trim($company['billing_contact_email'] ?? '');
        $appUrl = rtrim($this->config['app_url'] ?? '', '/');

        $modes = [
            'virement' => 'Virement bancaire',
            'cb' => 'Carte bancaire',
            'cheque' => 'Chèque',
            'especes' => 'Espèces',
            'autre' => 'Autre',
        ];
        $modeLibelle = $modes[$paiement['mode_paiement']] ?? $paiement['mode_paiement'];

        $clientNom = htmlspecialchars(trim($paiement['client_nom'] ?? '') ?: 'Client', ENT_QUOTES, 'UTF-8');
        $reference = htmlspecialchars($paiement['reference'] ?? '', ENT_QUOTES, 'UTF-8');
        $montant = htmlspecialchars(number_format((float)$paiement['montant'], 2, ',', ' ') . ' €', ENT_QUOTES, 'UTF-8');
        $date = htmlspecialchars(date('d/m/Y', strtotime($paiement['date_paiement'])), ENT_QUOTES, 'UTF-8');
        $companyNameEsc = htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8');

        $addressBlock = $companyAddress
            ? '<p class="footer-line">' . htmlspecialchars($companyAddress, ENT_QUOTES, 'UTF-8') . '</p>'
            : '';
        $contactBlock = $billingEmail
            ? '<p class="footer-line"><strong>Contact :</strong> <a href="mailto:' . htmlspecialchars($billingEmail, ENT_QUOTES, 'UTF-8') . '" style="color:#2563eb;text-decoration:none;">' . htmlspecialchars($billingEmail, ENT_QUOTES, 'UTF-8') . '</a></p>'
            : '';

        $logoUrl = $appUrl ? $appUrl . '/assets/logos/logo.png' : '';
        $logoBlock = $logoUrl
            ? '<img src="' . htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') . '" alt="' . $companyNameEsc . '" class="logo-img" width="140" style="max-width:140px;height:auto;">'
            : '<span style="font-size:24px;font-weight:bold;color:#232f3e;">' . $companyNameEsc . '</span>';

        $invoiceDetailBlock = '';
        if (!empty($paiement['facture_numero'])) {
            $factureNum = htmlspecialchars($paiement['facture_numero'], ENT_QUOTES, 'UTF-8');
            $factureDate = !empty($paiement['facture_date'])
                ? htmlspecialchars(date('d/m/Y', strtotime($paiement['facture_date'])), ENT_QUOTES, 'UTF-8')
                : '';
            $invoiceDetailBlock = '<div class="receipt-detail"><strong>Facture concernée :</strong> ' . $factureNum . '</div>';
            if ($factureDate) {
                $invoiceDetailBlock .= '<div class="receipt-detail"><strong>Date de facture :</strong> ' . $factureDate . '</div>';
            }
        }

        $replacements = [
            '{{logo_block}}' => $logoBlock,
            '{{company_name}}' => $companyNameEsc,
            '{{client_name}}' => $clientNom,
            '{{receipt_reference}}' => $reference,
            '{{receipt_date}}' => $date,
            '{{receipt_amount}}' => $montant,
            '{{payment_mode}}' => htmlspecialchars($modeLibelle, ENT_QUOTES, 'UTF-8'),
            '{{invoice_detail_block}}' => $invoiceDetailBlock,
            '{{address_block}}' => $addressBlock,
            '{{contact_block}}' => $contactBlock,
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    private function findRecuPath(string $relativePath): ?string
    {
        $normalized = str_replace('\\', '/', $relativePath);
        $normalized = preg_replace('#/+#', '/', $normalized);
        $normalized = ltrim($normalized, '/');

        if (strpos($normalized, '../') !== false || strpos($normalized, '..\\') !== false) {
            return null;
        }

        $possibleBaseDirs = [];
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot !== '' && is_dir($docRoot)) {
            $possibleBaseDirs[] = $docRoot;
        }
        $projectDir = dirname(__DIR__, 2);
        if (is_dir($projectDir)) {
            $possibleBaseDirs[] = $projectDir;
        }
        if (is_dir('/app')) {
            $possibleBaseDirs[] = '/app';
        }
        if (is_dir('/var/www/html')) {
            $possibleBaseDirs[] = '/var/www/html';
        }

        foreach ($possibleBaseDirs as $baseDir) {
            $fullPath = rtrim($baseDir, '/') . '/' . $normalized;
            if (file_exists($fullPath) && is_readable($fullPath)) {
                return $fullPath;
            }
        }

        return null;
    }
}
