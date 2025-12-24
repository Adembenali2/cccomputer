<?php
declare(strict_types=1);
/**
 * API de test SMTP - Protégée par token (Fallback)
 * 
 * IMPORTANT: Définissez SMTP_TEST_TOKEN dans les variables d'environnement
 * pour protéger cet endpoint en production.
 * 
 * URL: /test_smtp.php (fallback si /API/ est bloqué)
 */

// Buffer de sortie pour capturer toute sortie accidentelle
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Mail\MailerFactory;
use App\Mail\MailerException;

// Accepter GET pour afficher des informations, POST pour envoyer l'email
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonResponse([
        'ok' => true,
        'message' => 'Endpoint de test SMTP disponible',
        'method' => 'POST',
        'required_params' => ['token', 'to'],
        'note' => 'Utilisez POST avec un token valide pour envoyer un email de test',
        'example' => [
            'curl' => 'curl -X POST https://your-domain.com/test_smtp.php -H "Content-Type: application/json" -d \'{"token":"your-token","to":"test@example.com"}\''
        ]
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée. Utilisez POST.'], 405);
}

try {
    // Récupération des données
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || !is_array($data)) {
        jsonResponse(['ok' => false, 'error' => 'Données JSON invalides'], 400);
    }
    
    // Vérification du token - OBLIGATOIRE
    $providedToken = (string)($data['token'] ?? '');
    $expectedToken = (string)($_ENV['SMTP_TEST_TOKEN'] ?? getenv('SMTP_TEST_TOKEN') ?: '');
    
    // Token obligatoire en toutes circonstances
    if (empty($expectedToken)) {
        error_log('[SMTP_TEST] Tentative d\'accès sans token configuré');
        jsonResponse([
            'ok' => false, 
            'error' => 'Endpoint désactivé. Configurez SMTP_TEST_TOKEN dans les variables d\'environnement pour activer le test.'
        ], 403);
    }
    
    // Vérifier le token avec hash_equals pour éviter les attaques par timing
    if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        error_log('[SMTP_TEST] Token invalide fourni');
        jsonResponse([
            'ok' => false, 
            'error' => 'Token invalide'
        ], 403);
    }
    
    // Validation de l'email de destination
    $to = trim((string)($data['to'] ?? ''));
    if (empty($to)) {
        jsonResponse(['ok' => false, 'error' => 'Adresse email de destination requise (paramètre "to")'], 400);
    }
    
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['ok' => false, 'error' => 'Adresse email invalide'], 400);
    }
    
    // Charger la configuration email
    $config = require __DIR__ . '/../config/app.php';
    $emailConfig = $config['email'] ?? [];
    
    // Créer l'instance PHPMailer via MailerFactory
    try {
        $mail = MailerFactory::create($emailConfig);
    } catch (MailerException $e) {
        error_log('[SMTP_TEST] Erreur configuration MailerFactory: ' . $e->getMessage());
        jsonResponse([
            'ok' => false, 
            'error' => 'Configuration SMTP invalide: ' . $e->getMessage()
        ], 500);
    }
    
    // Configurer l'email de test
    $mail->clearAllRecipients();
    $mail->addAddress($to);
    $mail->Subject = 'Test SMTP - Camson Group';
    $mail->isHTML(false);
    $mail->Body = "Ceci est un email de test pour vérifier la configuration SMTP.\n\n";
    $mail->Body .= "Si vous recevez cet email, la configuration SMTP fonctionne correctement.\n\n";
    $mail->Body .= "Date d'envoi: " . date('Y-m-d H:i:s') . "\n";
    
    // Envoyer l'email de test
    try {
        $mail->send();
        
        error_log('[SMTP_TEST] Email de test envoyé avec succès à ' . $to);
        
        jsonResponse([
            'ok' => true,
            'message' => 'Email envoyé',
            'to' => $to,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        // Ne pas exposer les détails sensibles
        $errorInfo = $mail->ErrorInfo;
        $errorInfo = preg_replace('/password[=:]\s*\S+/i', 'password=***', $errorInfo);
        $errorInfo = preg_replace('/pwd[=:]\s*\S+/i', 'pwd=***', $errorInfo);
        
        error_log('[SMTP_TEST] Erreur lors de l\'envoi: ' . $errorInfo);
        error_log('[SMTP_TEST] Exception: ' . $e->getMessage());
        
        jsonResponse([
            'ok' => false,
            'error' => 'Erreur lors de l\'envoi de l\'email (voir logs serveur)'
        ], 500);
    }
    
} catch (Throwable $e) {
    // Ne pas exposer les détails de l'exception au client
    error_log('[SMTP_TEST] Erreur inattendue: ' . $e->getMessage());
    error_log('[SMTP_TEST] Stack trace: ' . $e->getTraceAsString());
    jsonResponse([
        'ok' => false, 
        'error' => 'Erreur inattendue lors du test SMTP'
    ], 500);
}

