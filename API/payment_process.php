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
$receiptPdfPath = null;
$receiptPdfContent = null;
$receiptNumber = null;

try {
    // Charger TCPDF
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (file_exists($vendorPath)) {
        require_once $vendorPath;
    }
    
    if (class_exists('TCPDF')) {
        // Préparer les données pour la génération du PDF
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
            // Créer un dossier local pour les justificatifs (phase de test)
            $localReceiptsDir = __DIR__ . '/../receipts';
            if (!is_dir($localReceiptsDir)) {
                @mkdir($localReceiptsDir, 0755, true);
            }
            
            // Sauvegarder localement
            $receiptNumber = 'JUST-' . date('Ymd') . '-' . str_pad($client_id, 5, '0', STR_PAD_LEFT) . '-' . time();
            $localFilename = $receiptNumber . '.pdf';
            $localPath = $localReceiptsDir . '/' . $localFilename;
            
            if (file_put_contents($localPath, $receiptPdfContent) !== false) {
                @chmod($localPath, 0644);
                
                // Également sauvegarder dans uploads/clients pour l'accès web
                $receiptDir = ensure_upload_dir($client_id);
                $receiptFilename = date('Ymd_His') . '_justificatif_paiement_' . $client_id . '.pdf';
                $receiptPdfPath = $receiptDir . '/' . $receiptFilename;
                
                if (file_put_contents($receiptPdfPath, $receiptPdfContent) !== false) {
                    @chmod($receiptPdfPath, 0644);
                    $receiptPdfPath = '/uploads/clients/' . $client_id . '/' . $receiptFilename;
                }
            }
        }
    }
} catch (Exception $e) {
    error_log('Erreur génération justificatif PDF dans payment_process: ' . $e->getMessage());
    // Ne pas bloquer l'enregistrement du paiement si la génération du PDF échoue
}

// Enregistrer le paiement dans la table paiements
$paymentId = null;
try {
    // Vérifier si la table existe
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = 'paiements'
    ");
    $checkTable->execute();
    $tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
    
    if ($tableExists) {
        $stmt = $pdo->prepare("
            INSERT INTO paiements (
                client_id, montant, type_paiement, date_paiement, 
                reference, iban, notes, 
                justificatif_upload, justificatif_pdf, numero_justificatif, user_id
            ) VALUES (
                :client_id, :montant, :type_paiement, :date_paiement,
                :reference, :iban, :notes,
                :justificatif_upload, :justificatif_pdf, :numero_justificatif, :user_id
            )
        ");
        
        $stmt->execute([
            ':client_id' => $client_id,
            ':montant' => $amount,
            ':type_paiement' => $payment_type,
            ':date_paiement' => $payment_date,
            ':reference' => $reference ?: null,
            ':iban' => $iban ?: null,
            ':notes' => $notes ?: null,
            ':justificatif_upload' => $justificatifPath ?: null,
            ':justificatif_pdf' => $receiptPdfPath ?: null,
            ':numero_justificatif' => $receiptNumber ?: null,
            ':user_id' => $_SESSION['user_id'] ?? null
        ]);
        
        $paymentId = (int)$pdo->lastInsertId();
    }
} catch (PDOException $e) {
    error_log('Erreur enregistrement paiement dans BDD: ' . $e->getMessage());
    // Ne pas bloquer si la table n'existe pas encore
}

jsonResponse([
    'ok' => true,
    'message' => 'Paiement enregistré avec succès',
    'justificatif' => $justificatifPath,
    'receipt_pdf' => $receiptPdfPath,
    'receipt_number' => $receiptNumber,
    'payment_id' => $paymentId,
    'pdf_field' => $availablePdfField
]);

