<?php
/**
 * API pour générer un justificatif de paiement en PDF
 * 
 * Paramètres GET:
 * - client_id: ID du client
 * - payment_id: ID du paiement (optionnel, pour récupérer depuis la BDD)
 * 
 * Ou paramètres POST pour génération directe:
 * - client_id, amount, payment_type, payment_date, reference, iban, notes
 */

// Démarrer la session AVANT tout
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die('Non authentifié. Veuillez vous connecter.');
}

// Récupérer les paramètres (GET pour téléchargement, POST pour génération)
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : (isset($_POST['client_id']) ? (int)$_POST['client_id'] : 0);
$payment_id = isset($_GET['payment_id']) ? (int)$_GET['payment_id'] : 0;

// Si données POST, les utiliser directement
$payment_data = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($_POST)) {
    $payment_data = [
        'amount' => isset($_POST['amount']) ? (float)$_POST['amount'] : 0,
        'type' => isset($_POST['payment_type']) ? trim($_POST['payment_type']) : '',
        'date' => isset($_POST['payment_date']) ? trim($_POST['payment_date']) : date('Y-m-d'),
        'reference' => isset($_POST['reference']) ? trim($_POST['reference']) : '',
        'iban' => isset($_POST['iban']) ? trim($_POST['iban']) : '',
        'notes' => isset($_POST['notes']) ? trim($_POST['notes']) : ''
    ];
}

if (!$client_id) {
    http_response_code(400);
    die('ID client manquant');
}

try {
    // Charger TCPDF
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        error_log('Erreur: vendor/autoload.php introuvable');
        http_response_code(500);
        die('Erreur: Dépendances non installées.');
    }
    require_once $vendorPath;
    
    if (!class_exists('TCPDF')) {
        error_log('Erreur: Classe TCPDF introuvable');
        http_response_code(500);
        die('Erreur: Bibliothèque PDF non disponible.');
    }
    
    // Récupérer les informations du client
    $stmt = $pdo->prepare("
        SELECT id, numero_client, raison_sociale, adresse, code_postal, ville, 
               email, telephone1, iban
        FROM clients 
        WHERE id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $client_id]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        http_response_code(404);
        die('Client introuvable');
    }
    
    // Si pas de données POST, récupérer depuis la BDD (si table paiements existe)
    if (!$payment_data && $payment_id > 0) {
        // TODO: Récupérer depuis la table paiements quand elle sera créée
        // Pour l'instant, on utilise les données POST
    }
    
    // Si toujours pas de données, utiliser des valeurs par défaut
    if (!$payment_data) {
        $payment_data = [
            'amount' => 0,
            'type' => 'especes',
            'date' => date('Y-m-d'),
            'reference' => '',
            'iban' => $client['iban'] ?? '',
            'notes' => ''
        ];
    }
    
    // Générer un numéro de justificatif
    $receipt_number = 'JUST-' . date('Ymd') . '-' . str_pad($client_id, 5, '0', STR_PAD_LEFT) . '-' . time();
    $payment_data['receipt_number'] = $receipt_number;
    
    // Générer le PDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    $pdf->SetCreator('CCComputer');
    $pdf->SetAuthor('CCComputer');
    $pdf->SetTitle('Justificatif de Paiement ' . $receipt_number);
    $pdf->SetSubject('Justificatif de Paiement');
    
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    $pdf->AddPage();
    
    // Inclure le template
    $templatePath = __DIR__ . '/../templates/payment_receipt_template.php';
    if (file_exists($templatePath)) {
        include $templatePath;
        
        printReceiptHeader($pdf, $client, $payment_data);
        printReceiptClientInfo($pdf, $client);
        printReceiptPaymentDetails($pdf, $payment_data);
        printReceiptFooter($pdf, $payment_data);
    } else {
        // Template par défaut si le fichier n'existe pas
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, 'Justificatif de Paiement', 0, 1, 'C');
        $pdf->Ln(10);
        $pdf->SetFont('helvetica', '', 12);
        $pdf->Cell(0, 6, 'Client: ' . htmlspecialchars($client['raison_sociale']), 0, 1);
        $pdf->Cell(0, 6, 'Montant: ' . number_format($payment_data['amount'], 2, ',', ' ') . ' €', 0, 1);
        $pdf->Cell(0, 6, 'Date: ' . date('d/m/Y', strtotime($payment_data['date'])), 0, 1);
    }
    
    // Déterminer le mode de sortie
    // Si appelé depuis payment_process.php via require, retourner le contenu
    // Sinon, télécharger directement
    $output_mode = isset($GLOBALS['_GENERATE_RECEIPT_MODE']) && $GLOBALS['_GENERATE_RECEIPT_MODE'] === 'string' 
        ? 'S' 
        : (isset($_GET['download']) && $_GET['download'] === '1' ? 'D' : 'S');
    $filename = 'Justificatif_Paiement_' . $receipt_number . '.pdf';
    
    // Si mode 'S' (string), retourner le contenu pour sauvegarde
    if ($output_mode === 'S') {
        return $pdf->Output($filename, 'S');
    } else {
        // Mode 'D' (download) - téléchargement direct
        $pdf->Output($filename, 'D');
        exit;
    }
    
} catch (Exception $e) {
    error_log('Erreur génération justificatif PDF: ' . $e->getMessage());
    http_response_code(500);
    die('Erreur lors de la génération du PDF: ' . $e->getMessage());
}

