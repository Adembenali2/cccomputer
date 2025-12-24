<?php
declare(strict_types=1);
/**
 * API pour envoyer une facture par email
 * Utilise MailerService (PHPMailer) pour l'envoi SMTP
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Mail\MailerService;
use App\Mail\MailerException;

// Vérifier que c'est une requête POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    // Récupération des données
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_id']) || empty($data['email'])) {
        jsonResponse(['ok' => false, 'error' => 'Données incomplètes'], 400);
    }
    
    $factureId = (int)$data['facture_id'];
    $email = trim($data['email']);
    $sujet = !empty($data['sujet']) ? trim($data['sujet']) : '';
    $message = !empty($data['message']) ? trim($data['message']) : '';
    
    // Validation de l'email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'Adresse email invalide'], 400);
    }
    
    // Récupérer la facture avec les infos client
    $stmt = $pdo->prepare("
        SELECT 
            f.id, f.numero, f.date_facture, f.montant_ttc, f.pdf_path, f.pdf_genere,
            f.id_client, f.type, f.date_debut_periode, f.date_fin_periode,
            c.raison_sociale as client_nom, c.email as client_email, c.*
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        jsonResponse(['ok' => false, 'error' => 'Facture introuvable'], 404);
    }
    
    // Si le PDF n'existe pas ou n'est pas généré, essayer de le régénérer
    $shouldRegenerate = empty($facture['pdf_path']) || !$facture['pdf_genere'];
    
    // Si le PDF est marqué comme généré mais qu'on ne le trouve pas, on régénère aussi
    if (!$shouldRegenerate && !empty($facture['pdf_path'])) {
        $pdfPath = MailerService::findPdfPath($facture['pdf_path']);
        if (!$pdfPath) {
            error_log("PDF marqué comme généré mais fichier introuvable, régénération nécessaire");
            $shouldRegenerate = true;
        }
    }
    
    if ($shouldRegenerate) {
        // Essayer de régénérer le PDF
        try {
            // Inclure seulement les fonctions nécessaires sans exécuter le code principal
            if (!function_exists('generateFacturePDF')) {
                require_once __DIR__ . '/factures_generer.php';
            }
            
            // Récupérer les lignes de facture
            $stmtLignes = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre");
            $stmtLignes->execute([':id' => $factureId]);
            $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
            
            // Préparer les données pour la génération
            $dataForPDF = [
                'factureClient' => $facture['id_client'],
                'factureDate' => $facture['date_facture'],
                'factureType' => $facture['type'],
                'factureDateDebut' => $facture['date_debut_periode'],
                'factureDateFin' => $facture['date_fin_periode'],
                'lignes' => []
            ];
            
            foreach ($lignes as $ligne) {
                $dataForPDF['lignes'][] = [
                    'description' => $ligne['description'],
                    'type' => $ligne['type'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire_ht'],
                    'total_ht' => $ligne['total_ht']
                ];
            }
            
            // Générer le PDF
            $pdfWebPath = generateFacturePDF($pdo, $factureId, $facture, $dataForPDF);
            
            // Mettre à jour la facture
            $stmt = $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?");
            $stmt->execute([$pdfWebPath, $factureId]);
            
            $facture['pdf_path'] = $pdfWebPath;
            $facture['pdf_genere'] = 1;
            
        } catch (Exception $e) {
            error_log("Erreur lors de la régénération du PDF: " . $e->getMessage());
            jsonResponse(['ok' => false, 'error' => 'Impossible de générer le PDF de la facture. ' . $e->getMessage()], 500);
        }
    }
    
    if (empty($facture['pdf_path'])) {
        jsonResponse(['ok' => false, 'error' => 'Le PDF de la facture n\'existe pas et n\'a pas pu être généré'], 400);
    }
    
    // Trouver le chemin absolu du PDF
    $pdfPath = null;
    try {
        $pdfPath = MailerService::findPdfPath($facture['pdf_path']);
        error_log("PDF trouvé avec succès: " . $pdfPath . " (Taille: " . filesize($pdfPath) . " bytes)");
    } catch (MailerException $e) {
        error_log("PDF introuvable via findPdfPath: " . $e->getMessage());
        error_log("Chemin enregistré dans DB: " . $facture['pdf_path']);
        
        // Fallback: Si le PDF n'existe pas (Railway stockage éphémère), régénérer à la demande
        error_log("Tentative de régénération du PDF à la demande (fallback Railway)");
        
        try {
            // Inclure la fonction de génération si nécessaire
            if (!function_exists('generateFacturePDF')) {
                require_once __DIR__ . '/factures_generer.php';
            }
            
            // Récupérer les lignes de facture
            $stmtLignes = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre");
            $stmtLignes->execute([':id' => $factureId]);
            $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
            
            // Préparer les données pour la génération
            $dataForPDF = [
                'factureClient' => $facture['id_client'],
                'factureDate' => $facture['date_facture'],
                'factureType' => $facture['type'],
                'factureDateDebut' => $facture['date_debut_periode'],
                'factureDateFin' => $facture['date_fin_periode'],
                'lignes' => []
            ];
            
            foreach ($lignes as $ligne) {
                $dataForPDF['lignes'][] = [
                    'description' => $ligne['description'],
                    'type' => $ligne['type'],
                    'quantite' => $ligne['quantite'],
                    'prix_unitaire' => $ligne['prix_unitaire_ht'],
                    'total_ht' => $ligne['total_ht']
                ];
            }
            
            // Générer le PDF (cela va créer le fichier)
            $pdfWebPath = generateFacturePDF($pdo, $factureId, $facture, $dataForPDF);
            
            // Mettre à jour la facture avec le nouveau chemin
            $stmt = $pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?");
            $stmt->execute([$pdfWebPath, $factureId]);
            
            // Essayer de trouver le PDF fraîchement généré
            try {
                $pdfPath = MailerService::findPdfPath($pdfWebPath);
                error_log("PDF régénéré et trouvé: " . $pdfPath);
            } catch (MailerException $e2) {
                error_log("Erreur: PDF régénéré mais toujours introuvable: " . $e2->getMessage());
                // Dernier recours: générer dans /tmp et utiliser directement
                $tmpPath = sys_get_temp_dir() . '/' . basename($pdfWebPath);
                error_log("Tentative de génération dans /tmp: " . $tmpPath);
                
                // Régénérer directement dans /tmp
                // Note: On ne peut pas facilement régénérer sans refaire toute la logique
                // Donc on retourne une erreur claire
                jsonResponse([
                    'ok' => false, 
                    'error' => 'Le PDF a été régénéré mais n\'est toujours pas accessible. ' .
                               'Cela peut indiquer un problème de permissions ou de stockage sur le serveur.'
                ], 500);
            }
            
        } catch (Exception $e) {
            error_log("Erreur lors de la régénération du PDF (fallback): " . $e->getMessage());
            jsonResponse([
                'ok' => false, 
                'error' => 'Impossible de régénérer le PDF: ' . $e->getMessage()
            ], 500);
        }
    }
    
    if (!$pdfPath || !file_exists($pdfPath)) {
        error_log("ERREUR CRITIQUE: PDF introuvable après toutes les tentatives");
        jsonResponse([
            'ok' => false, 
            'error' => 'Le fichier PDF est introuvable et n\'a pas pu être régénéré. Veuillez contacter l\'administrateur.'
        ], 500);
    }
    
    // Charger la configuration email
    $config = require __DIR__ . '/../config/app.php';
    $emailConfig = $config['email'] ?? [];
    
    // Créer le service Mailer
    try {
        $mailerService = new MailerService($emailConfig);
    } catch (MailerException $e) {
        error_log("Erreur configuration MailerService: " . $e->getMessage());
        jsonResponse([
            'ok' => false, 
            'error' => $e->getMessage()
        ], 500);
    }
    
    // Préparer le sujet et le message
    if (empty($sujet)) {
        $sujet = "Facture {$facture['numero']} - CC Computer";
    }
    
    $messageBody = $message;
    if (empty($messageBody)) {
        $messageBody = "Bonjour,\n\n";
        $messageBody .= "Veuillez trouver ci-joint la facture {$facture['numero']} d'un montant de " . number_format($facture['montant_ttc'], 2, ',', ' ') . " € TTC.\n\n";
        $messageBody .= "Cordialement,\nCC Computer";
    } else {
        $messageBody = $message . "\n\n";
        $messageBody .= "Veuillez trouver ci-joint la facture {$facture['numero']} d'un montant de " . number_format($facture['montant_ttc'], 2, ',', ' ') . " € TTC.";
    }
    
    // Nettoyer le nom du fichier PDF
    $fileName = basename($facture['pdf_path']);
    $fileName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fileName);
    
    // Envoyer l'email avec le PDF
    try {
        $mailerService->sendEmailWithPdf($email, $sujet, $messageBody, $pdfPath, $fileName);
        
        // Mettre à jour la facture pour indiquer qu'elle a été envoyée
        $stmt = $pdo->prepare("
            UPDATE factures 
            SET email_envoye = 1, date_envoi_email = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([':id' => $factureId]);
        error_log("Facture #{$factureId} marquée comme envoyée par email");
        
        jsonResponse([
            'ok' => true,
            'message' => 'Facture envoyée par email avec succès',
            'facture_id' => $factureId,
            'email' => $email
        ]);
        
    } catch (MailerException $e) {
        error_log("Erreur MailerService lors de l'envoi: " . $e->getMessage());
        jsonResponse([
            'ok' => false, 
            'error' => $e->getMessage()
        ], 500);
    }
    
} catch (PDOException $e) {
    error_log('factures_envoyer_email.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (MailerException $e) {
    // Erreur déjà sanitée par MailerService
    error_log('factures_envoyer_email.php MailerException: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
} catch (Throwable $e) {
    // Ne pas exposer les détails de l'exception au client
    error_log('factures_envoyer_email.php error: ' . $e->getMessage());
    error_log('factures_envoyer_email.php stack trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue lors de l\'envoi de l\'email'], 500);
}
