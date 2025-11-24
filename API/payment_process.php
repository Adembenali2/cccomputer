<?php
/**
 * API pour traiter un paiement avec upload de justificatif
 */

ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Démarrer la session
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('payment_process.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Vérifier la méthode
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Vérifier CSRF
$csrfToken = $_POST['csrf_token'] ?? '';
$sessionToken = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($sessionToken) || !hash_equals($sessionToken, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

// Récupérer les données
$client_id = isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0;
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$payment_type = isset($_POST['payment_type']) ? trim($_POST['payment_type']) : '';
$payment_date = isset($_POST['payment_date']) ? trim($_POST['payment_date']) : '';
$reference = isset($_POST['reference']) ? trim($_POST['reference']) : '';
$iban = isset($_POST['iban']) ? trim($_POST['iban']) : '';
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';

// Validation
if ($client_id <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Client invalide'], 400);
}

if ($amount <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Montant invalide'], 400);
}

if (!in_array($payment_type, ['especes', 'cheque', 'virement'])) {
    jsonResponse(['ok' => false, 'error' => 'Type de paiement invalide'], 400);
}

if (empty($payment_date)) {
    jsonResponse(['ok' => false, 'error' => 'Date de paiement requise'], 400);
}

// Validation spécifique selon le type
if ($payment_type === 'virement') {
    if (empty($iban)) {
        jsonResponse(['ok' => false, 'error' => 'IBAN requis pour un virement'], 400);
    }
    if (empty($_FILES['justificatif']['name'])) {
        jsonResponse(['ok' => false, 'error' => 'Justificatif requis pour un virement'], 400);
    }
} elseif ($payment_type === 'cheque') {
    if (empty($_FILES['justificatif']['name'])) {
        jsonResponse(['ok' => false, 'error' => 'Justificatif requis pour un chèque'], 400);
    }
}

// Fonctions helper pour l'upload (reprises de client_fiche.php)
function uploads_base_path(): string {
    $docRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
    if ($docRoot !== '' && is_dir($docRoot)) {
        return $docRoot . '/uploads/clients';
    }
    return dirname(__DIR__) . '/uploads/clients';
}

function ensure_upload_dir(int $id): string {
    $base = uploads_base_path() . '/' . (int)$id;
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    return $base;
}

function safe_filename(string $name): string {
    $name = preg_replace('/[^\w.\-]+/u', '_', $name);
    $name = trim($name, '._ ');
    if ($name === '') $name = 'file';
    return $name;
}

function allowed_ext(string $filename): bool {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
}

function store_upload(array $file, int $id): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if (!is_uploaded_file($file['tmp_name'])) return null;
    if (!allowed_ext($file['name'])) return null;
    
    // Vérification de la taille (max 10MB)
    $maxSize = 10 * 1024 * 1024; // 10MB
    if (($file['size'] ?? 0) > $maxSize) return null;
    
    // Vérification du type MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($mimeType, $allowedMimes, true)) return null;
    
    $dir = ensure_upload_dir($id);
    $base = date('Ymd_His') . '_justificatif_paiement_' . safe_filename($file['name']);
    $destAbs = $dir . '/' . $base;
    if (!move_uploaded_file($file['tmp_name'], $destAbs)) return null;
    
    @chmod($destAbs, 0644);
    
    // Chemin relatif web
    $rel = '/uploads/clients/' . $id . '/' . $base;
    return $rel;
}

// Traitement de l'upload du justificatif si nécessaire
$justificatifPath = null;
if (!empty($_FILES['justificatif']['name'])) {
    $justificatifPath = store_upload($_FILES['justificatif'], $client_id);
    if (!$justificatifPath) {
        jsonResponse(['ok' => false, 'error' => 'Erreur lors de l\'upload du justificatif'], 400);
    }
}

// Récupérer les informations du client pour trouver un champ pdf disponible
$stmt = $pdo->prepare("SELECT pdf1, pdf2, pdf3, pdf4, pdf5, iban FROM clients WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
}

// Trouver le premier champ pdf disponible
$pdfFields = ['pdf1', 'pdf2', 'pdf3', 'pdf4', 'pdf5'];
$availablePdfField = null;
foreach ($pdfFields as $field) {
    if (empty($client[$field])) {
        $availablePdfField = $field;
        break;
    }
}

// Si aucun champ disponible, utiliser pdf5 (écraser le dernier)
if (!$availablePdfField) {
    $availablePdfField = 'pdf5';
}

// Mettre à jour le client avec le justificatif et l'IBAN si nécessaire
$updateFields = [];
$updateValues = [':id' => $client_id];

if ($justificatifPath) {
    $updateFields[] = $availablePdfField . ' = :justificatif';
    $updateValues[':justificatif'] = $justificatifPath;
}

if ($payment_type === 'virement' && !empty($iban)) {
    // Mettre à jour l'IBAN du client si différent
    if (empty($client['iban']) || $client['iban'] !== $iban) {
        $updateFields[] = 'iban = :iban';
        $updateValues[':iban'] = $iban;
    }
}

// Mettre à jour le client si nécessaire
if (!empty($updateFields)) {
    $sql = "UPDATE clients SET " . implode(', ', $updateFields) . " WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($updateValues);
}

// Générer le justificatif de paiement PDF
require_once __DIR__ . '/../includes/email_helper.php';

$receiptPdfPath = null;
$receiptPdfContent = null;

try {
    // Préparer les données pour la génération du PDF
    // Sauvegarder les données POST originales
    $originalPost = $_POST;
    $_POST['client_id'] = $client_id;
    $_POST['amount'] = $amount;
    $_POST['payment_type'] = $payment_type;
    $_POST['payment_date'] = $payment_date;
    $_POST['reference'] = $reference;
    $_POST['iban'] = $iban;
    $_POST['notes'] = $notes;
    
    // Définir une variable globale pour indiquer qu'on est en mode inclusion
    $GLOBALS['_GENERATE_RECEIPT_MODE'] = 'string';
    
    // Capturer la sortie PDF (le fichier retourne le contenu en mode 'S')
    $receiptPdfContent = require __DIR__ . '/generate_payment_receipt.php';
    
    // Restaurer les données POST originales
    $_POST = $originalPost;
    unset($GLOBALS['_GENERATE_RECEIPT_MODE']);
    
    // Si le contenu est une chaîne (mode 'S'), sauvegarder le fichier
    if (is_string($receiptPdfContent) && strlen($receiptPdfContent) > 0) {
        $receiptDir = ensure_upload_dir($client_id);
        $receiptFilename = date('Ymd_His') . '_justificatif_paiement_' . $client_id . '.pdf';
        $receiptPdfPath = $receiptDir . '/' . $receiptFilename;
        
        if (file_put_contents($receiptPdfPath, $receiptPdfContent) !== false) {
            @chmod($receiptPdfPath, 0644);
            $receiptPdfPath = '/uploads/clients/' . $client_id . '/' . $receiptFilename;
            
            // Enregistrer le justificatif dans un champ PDF disponible (si pas déjà fait)
            if (!$justificatifPath) {
                // Trouver un autre champ PDF disponible pour le justificatif généré
                $stmt = $pdo->prepare("SELECT pdf1, pdf2, pdf3, pdf4, pdf5 FROM clients WHERE id = :id LIMIT 1");
                $stmt->execute([':id' => $client_id]);
                $clientPdfs = $stmt->fetch(PDO::FETCH_ASSOC);
                
                $receiptPdfField = null;
                foreach (['pdf1', 'pdf2', 'pdf3', 'pdf4', 'pdf5'] as $field) {
                    if (empty($clientPdfs[$field])) {
                        $receiptPdfField = $field;
                        break;
                    }
                }
                
                if (!$receiptPdfField) {
                    $receiptPdfField = 'pdf5'; // Utiliser le dernier si tous sont remplis
                }
                
                // Mettre à jour le client avec le justificatif généré
                $stmt = $pdo->prepare("UPDATE clients SET {$receiptPdfField} = :receipt WHERE id = :id");
                $stmt->execute([':receipt' => $receiptPdfPath, ':id' => $client_id]);
            }
        }
    }
} catch (Exception $e) {
    error_log('Erreur génération justificatif PDF dans payment_process: ' . $e->getMessage());
    // Ne pas bloquer l'enregistrement du paiement si la génération du PDF échoue
}

// Envoyer l'email de confirmation au client
$emailSent = false;
$emailError = null;

if (!empty($client['email']) && filter_var($client['email'], FILTER_VALIDATE_EMAIL)) {
    try {
        $paymentData = [
            'amount' => $amount,
            'type' => $payment_type,
            'date' => $payment_date,
            'reference' => $reference,
            'iban' => $iban,
            'notes' => $notes
        ];
        
        $emailBody = generatePaymentConfirmationEmailBody($client, $paymentData);
        $emailSubject = 'Confirmation de paiement - ' . number_format($amount, 2, ',', ' ') . ' €';
        
        $attachments = [];
        
        // Ajouter le justificatif PDF en pièce jointe si disponible
        if ($receiptPdfPath && file_exists($_SERVER['DOCUMENT_ROOT'] . $receiptPdfPath)) {
            $attachments[] = [
                'path' => $_SERVER['DOCUMENT_ROOT'] . $receiptPdfPath,
                'name' => 'Justificatif_Paiement_' . date('Ymd_His') . '.pdf'
            ];
        }
        
        // Ajouter le justificatif uploadé si disponible
        if ($justificatifPath && file_exists($_SERVER['DOCUMENT_ROOT'] . $justificatifPath)) {
            $attachments[] = [
                'path' => $_SERVER['DOCUMENT_ROOT'] . $justificatifPath,
                'name' => 'Justificatif_Client_' . basename($justificatifPath)
            ];
        }
        
        $emailResult = sendEmail(
            $client['email'],
            $emailSubject,
            $emailBody,
            $attachments
        );
        
        $emailSent = $emailResult['ok'];
        $emailError = $emailResult['error'];
        
        if (!$emailSent) {
            error_log('Erreur envoi email paiement: ' . $emailError);
        }
    } catch (Exception $e) {
        error_log('Exception envoi email paiement: ' . $e->getMessage());
        $emailError = $e->getMessage();
    }
}

// TODO: Enregistrer le paiement dans une table de paiements
// Pour l'instant, on simule juste l'enregistrement
// En production, créer une table 'paiements' avec les colonnes appropriées

jsonResponse([
    'ok' => true,
    'message' => 'Paiement enregistré avec succès',
    'justificatif' => $justificatifPath,
    'receipt_pdf' => $receiptPdfPath,
    'pdf_field' => $availablePdfField,
    'email_sent' => $emailSent,
    'email_error' => $emailError
]);

