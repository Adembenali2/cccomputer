<?php
/**
 * API pour envoyer un reçu de paiement par email au client
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Mail\MailerService;
use App\Mail\BrevoApiMailerService;
use App\Mail\MailerException;

initApi();
requireApiAuth();

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdoOrFail();
    
    // Récupération des données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !is_array($data)) {
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
    }
    
    if (empty($data['paiement_id'])) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id requis'], 400);
    }
    
    $paiementId = (int)$data['paiement_id'];
    
    if ($paiementId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'paiement_id invalide'], 400);
    }
    
    // Récupérer les informations du paiement et du client
    $stmt = $pdo->prepare("
        SELECT 
            p.id,
            p.id_facture,
            p.id_client,
            p.montant,
            p.date_paiement,
            p.mode_paiement,
            p.reference,
            p.commentaire,
            p.recu_path,
            p.recu_genere,
            p.email_envoye,
            p.date_envoi_email,
            c.raison_sociale as client_nom,
            c.email as client_email,
            c.adresse,
            c.code_postal,
            c.ville,
            c.siret,
            f.numero as facture_numero,
            f.date_facture as facture_date
        FROM paiements p
        LEFT JOIN clients c ON p.id_client = c.id
        LEFT JOIN factures f ON p.id_facture = f.id
        WHERE p.id = :paiement_id
        LIMIT 1
    ");
    $stmt->execute([':paiement_id' => $paiementId]);
    $paiement = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$paiement) {
        jsonResponse(['ok' => false, 'error' => 'Paiement introuvable'], 404);
    }
    
    // Vérifier que le client a un email
    if (empty($paiement['client_email'])) {
        jsonResponse(['ok' => false, 'error' => 'Le client n\'a pas d\'adresse email enregistrée'], 400);
    }
    
    // Vérifier que le reçu existe
    if (empty($paiement['recu_path'])) {
        jsonResponse(['ok' => false, 'error' => 'Aucun reçu disponible pour ce paiement'], 400);
    }
    
    // Trouver le chemin du PDF du reçu
    $pdfPath = null;
    $recuPath = $paiement['recu_path'];
    
    // Fonction helper pour trouver le chemin absolu d'un reçu
    $findRecuPath = function($relativePath) {
        $possibleBaseDirs = [];
        
        $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        if ($docRoot !== '' && is_dir($docRoot)) {
            $possibleBaseDirs[] = $docRoot;
        }
        
        $projectDir = dirname(__DIR__);
        if (is_dir($projectDir)) {
            $possibleBaseDirs[] = $projectDir;
        }
        
        if (is_dir('/app')) {
            $possibleBaseDirs[] = '/app';
        }
        if (is_dir('/var/www/html')) {
            $possibleBaseDirs[] = '/var/www/html';
        }
        
        // Normaliser le chemin (enlever le slash initial si présent)
        $normalizedPath = ltrim($relativePath, '/');
        
        foreach ($possibleBaseDirs as $baseDir) {
            $fullPath = rtrim($baseDir, '/') . '/' . $normalizedPath;
            if (file_exists($fullPath) && is_readable($fullPath)) {
                return $fullPath;
            }
        }
        
        return null;
    };
    
    // Essayer de trouver le reçu
    $pdfPath = $findRecuPath($recuPath);
    
    // Si le reçu n'existe pas, essayer de le régénérer
    if (!$pdfPath) {
        error_log('[paiements_envoyer_recu] Reçu introuvable: ' . $recuPath);
        
        // Si le reçu est marqué comme généré, on le régénère
        if ($paiement['recu_genere']) {
            try {
                require_once __DIR__ . '/paiements_generer_recu.php';
                $newRecuPath = generateRecuPDF($pdo, $paiementId);
                $pdfPath = $findRecuPath($newRecuPath);
                
                if (!$pdfPath) {
                    jsonResponse(['ok' => false, 'error' => 'Impossible de trouver le reçu après régénération'], 500);
                }
                
                // Mettre à jour le chemin dans la base
                $stmt = $pdo->prepare("UPDATE paiements SET recu_path = :recu_path WHERE id = :id");
                $stmt->execute([':recu_path' => $newRecuPath, ':id' => $paiementId]);
            } catch (Throwable $e) {
                error_log('[paiements_envoyer_recu] Erreur lors de la régénération: ' . $e->getMessage());
                jsonResponse(['ok' => false, 'error' => 'Erreur lors de la régénération du reçu: ' . $e->getMessage()], 500);
            }
        } else {
            jsonResponse(['ok' => false, 'error' => 'Reçu introuvable et non généré'], 404);
        }
    }
    
    // Préparer le sujet et le message
    $modesPaiement = [
        'virement' => 'Virement bancaire',
        'cb' => 'Carte bancaire',
        'cheque' => 'Chèque',
        'especes' => 'Espèces',
        'autre' => 'Autre'
    ];
    $modePaiementLibelle = $modesPaiement[$paiement['mode_paiement']] ?? $paiement['mode_paiement'];
    
    $sujet = "Reçu de paiement " . $paiement['reference'] . " - CC Computer";
    
    // Message texte
    $textBody = "Bonjour,\n\n";
    $textBody .= "Nous vous confirmons la réception de votre paiement.\n\n";
    $textBody .= "Détails du paiement :\n";
    $textBody .= "- Référence : " . $paiement['reference'] . "\n";
    $textBody .= "- Date : " . date('d/m/Y', strtotime($paiement['date_paiement'])) . "\n";
    $textBody .= "- Montant : " . number_format($paiement['montant'], 2, ',', ' ') . " €\n";
    $textBody .= "- Mode de paiement : " . $modePaiementLibelle . "\n";
    if ($paiement['facture_numero']) {
        $textBody .= "- Facture concernée : " . $paiement['facture_numero'] . "\n";
    }
    if (!empty($paiement['commentaire'])) {
        $textBody .= "- Commentaire : " . $paiement['commentaire'] . "\n";
    }
    $textBody .= "\n";
    $textBody .= "Le reçu de paiement est joint à cet email en pièce jointe.\n\n";
    $textBody .= "Cordialement,\n";
    $textBody .= "L'équipe CC Computer\n";
    $textBody .= "SSS international\n";
    $textBody .= "7, rue pierre brolet\n";
    $textBody .= "93100 Stains";
    
    // Message HTML
    $htmlBody = "<!DOCTYPE html>\n<html>\n<head>\n<meta charset='UTF-8'>\n</head>\n<body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>\n";
    $htmlBody .= "<div style='max-width: 600px; margin: 0 auto; padding: 20px;'>\n";
    $htmlBody .= "<h2 style='color: #2563eb;'>Reçu de paiement</h2>\n";
    $htmlBody .= "<p>Bonjour,</p>\n";
    $htmlBody .= "<p>Nous vous confirmons la réception de votre paiement.</p>\n";
    $htmlBody .= "<div style='background: #f8fafc; padding: 15px; border-radius: 8px; margin: 20px 0;'>\n";
    $htmlBody .= "<h3 style='margin-top: 0; color: #1e293b;'>Détails du paiement</h3>\n";
    $htmlBody .= "<table style='width: 100%; border-collapse: collapse;'>\n";
    $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Référence :</td><td style='padding: 5px 0;'>" . htmlspecialchars($paiement['reference']) . "</td></tr>\n";
    $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Date :</td><td style='padding: 5px 0;'>" . htmlspecialchars(date('d/m/Y', strtotime($paiement['date_paiement']))) . "</td></tr>\n";
    $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Montant :</td><td style='padding: 5px 0;'><strong style='color: #10b981; font-size: 1.1em;'>" . htmlspecialchars(number_format($paiement['montant'], 2, ',', ' ') . " €") . "</strong></td></tr>\n";
    $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Mode de paiement :</td><td style='padding: 5px 0;'>" . htmlspecialchars($modePaiementLibelle) . "</td></tr>\n";
    if ($paiement['facture_numero']) {
        $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Facture concernée :</td><td style='padding: 5px 0;'>" . htmlspecialchars($paiement['facture_numero']) . "</td></tr>\n";
    }
    if (!empty($paiement['commentaire'])) {
        $htmlBody .= "<tr><td style='padding: 5px 0; font-weight: bold;'>Commentaire :</td><td style='padding: 5px 0;'>" . htmlspecialchars($paiement['commentaire']) . "</td></tr>\n";
    }
    $htmlBody .= "</table>\n";
    $htmlBody .= "</div>\n";
    $htmlBody .= "<p>Le reçu de paiement est joint à cet email en pièce jointe.</p>\n";
    $htmlBody .= "<p>Cordialement,<br>\n";
    $htmlBody .= "<strong>L'équipe CC Computer</strong><br>\n";
    $htmlBody .= "SSS international<br>\n";
    $htmlBody .= "7, rue pierre brolet<br>\n";
    $htmlBody .= "93100 Stains</p>\n";
    $htmlBody .= "</div>\n";
    $htmlBody .= "</body>\n</html>\n";
    
    // Envoyer l'email
    $fileName = basename($paiement['recu_path']);
    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    
    $messageId = null;
    try {
        // Utiliser Brevo API si disponible, sinon SMTP
        if (!empty($_ENV['BREVO_API_KEY'])) {
            error_log('[paiements_envoyer_recu] Utilisation de l\'API Brevo pour l\'envoi');
            $brevoService = new BrevoApiMailerService();
            $messageId = $brevoService->sendEmailWithPdf(
                $paiement['client_email'],
                $sujet,
                $textBody,
                $pdfPath,
                $fileName,
                $htmlBody
            );
        } else {
            error_log('[paiements_envoyer_recu] Utilisation de SMTP (fallback)');
            $config = require __DIR__ . '/../config/app.php';
            $emailConfig = $config['email'] ?? [];
            $mailerService = new MailerService($emailConfig);
            $messageId = $mailerService->sendEmailWithPdf(
                $paiement['client_email'],
                $sujet,
                $textBody,
                $pdfPath,
                $fileName,
                $htmlBody
            );
        }
        
        // Mettre à jour le statut d'envoi
        $stmt = $pdo->prepare("
            UPDATE paiements 
            SET email_envoye = 1, 
                date_envoi_email = NOW()
            WHERE id = :id
        ");
        $stmt->execute([':id' => $paiementId]);
        
        jsonResponse([
            'ok' => true,
            'message' => 'Reçu envoyé avec succès',
            'paiement_id' => $paiementId,
            'email' => $paiement['client_email'],
            'message_id' => $messageId
        ]);
        
    } catch (MailerException $e) {
        error_log('[paiements_envoyer_recu] Erreur MailerException: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'envoi de l\'email: ' . $e->getMessage()], 500);
    } catch (Throwable $e) {
        error_log('[paiements_envoyer_recu] Erreur: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
    }
    
} catch (PDOException $e) {
    error_log('[paiements_envoyer_recu] PDOException: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('[paiements_envoyer_recu] Exception: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

