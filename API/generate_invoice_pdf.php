<?php
/**
 * API pour générer une facture en PDF
 * 
 * Paramètres GET:
 * - client_id: ID du client
 * - invoice_number: Numéro de facture
 */

// Initialiser la session AVANT session_config.php
require_once __DIR__ . '/../includes/session_config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die('Non authentifié. Veuillez vous connecter.');
}

// Récupérer les paramètres
$client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;
$invoice_number = isset($_GET['invoice_number']) ? trim($_GET['invoice_number']) : '';

if (!$client_id || !$invoice_number) {
    jsonResponse(['error' => 'Paramètres manquants'], 400);
}

try {
    // Charger la bibliothèque TCPDF
    // Vérifier si vendor/autoload.php existe
    $vendorPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($vendorPath)) {
        error_log('Erreur: vendor/autoload.php introuvable. Assurez-vous que composer install a été exécuté.');
        http_response_code(500);
        die('Erreur: Dépendances non installées. Veuillez contacter l\'administrateur.');
    }
    require_once $vendorPath;
    
    // Charger TCPDF explicitement si nécessaire
    // TCPDF peut nécessiter un chargement explicite selon la version
    $tcpdfPath = __DIR__ . '/../vendor/tecnickcom/tcpdf/tcpdf.php';
    if (file_exists($tcpdfPath)) {
        require_once $tcpdfPath;
    }
    
    // Vérifier que TCPDF est bien chargé
    if (!class_exists('TCPDF')) {
        error_log('Erreur: Classe TCPDF introuvable. Vérifiez que TCPDF est installé via composer.');
        error_log('Chemin vendor: ' . $vendorPath);
        error_log('Chemin TCPDF: ' . $tcpdfPath);
        http_response_code(500);
        die('Erreur: Bibliothèque PDF non disponible. Veuillez contacter l\'administrateur.');
    }
    
    // Récupérer les données depuis la session ou recréer les données mock
    // Pour l'instant, on utilise les données mock (même logique que paiements.php)
    // TODO: Remplacer par une vraie requête à la base de données
    
    $clientNames = [
        "Entreprise ABC", "Société XYZ", "Compagnie DEF", 
        "Groupe GHI", "Corporation JKL", "Firme MNO", 
        "Business PQR", "Company STU", "Organisation VWX", "Institution YZ"
    ];
    
    $client = null;
    $invoice = null;
    
    // Recréer les données du client et de sa facture (mock)
    // En production, récupérer depuis la base de données
    if ($client_id >= 1 && $client_id <= 10) {
        // Générer les factures pour ce client (même logique que paiements.php)
        $invoices = [];
        for ($m = 11; $m >= 0; $m--) {
            $invoiceMonth = date('Y-m', strtotime("-$m months"));
            $invoiceDate = date('Y-m-20', strtotime($invoiceMonth . '-01'));
            $periodStart = date('Y-m-20', strtotime($invoiceMonth . '-01 -1 month'));
            $periodEnd = date('Y-m-20', strtotime($invoiceMonth . '-01'));
            $dueDate = date('Y-m-20', strtotime($invoiceMonth . '-01 +1 month'));
            
            // Générer consommation mock
            $nbPages = rand(1000, 8000);
            $colorPages = rand(100, 2000);
            $nbAmount = $nbPages * 0.03;
            $colorAmount = $colorPages * 0.15;
            
            $invNumber = 'FAC-' . date('Ymd', strtotime($invoiceDate)) . '-' . str_pad($client_id, 5, '0', STR_PAD_LEFT);
            
            $invoices[] = [
                'invoice_number' => $invNumber,
                'invoice_date' => $invoiceDate,
                'due_date' => $dueDate,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'nb_pages' => $nbPages,
                'nb_amount' => round($nbAmount, 2),
                'color_pages' => $colorPages,
                'color_amount' => round($colorAmount, 2),
                'total_pages' => $nbPages + $colorPages,
                'total_amount' => round($nbAmount + $colorAmount, 2),
                'status' => (strtotime($dueDate) < time()) ? 'overdue' : (rand(0, 1) ? 'paid' : 'pending')
            ];
        }
        
        // Trouver la facture demandée
        foreach ($invoices as $inv) {
            if ($inv['invoice_number'] === $invoice_number) {
                $invoice = $inv;
                break;
            }
        }
        
        if ($invoice) {
            $client = [
                'id' => $client_id,
                'name' => $clientNames[$client_id - 1] ?? "Client $client_id",
                'numero_client' => 'C' . str_pad($client_id, 5, '0', STR_PAD_LEFT)
            ];
        }
    }
    
    if (!$client || !$invoice) {
        jsonResponse(['error' => 'Client ou facture introuvable'], 404);
    }
    
    // Charger le modèle de facture
    $templatePath = __DIR__ . '/../templates/invoice_template.php';
    if (!file_exists($templatePath)) {
        // Créer un modèle par défaut si il n'existe pas
        createDefaultInvoiceTemplate($templatePath);
    }
    
    // Générer le PDF
    generateInvoicePDF($client, $invoice, $templatePath);
    
} catch (Exception $e) {
    error_log('Erreur génération facture PDF: ' . $e->getMessage());
    jsonResponse(['error' => 'Erreur lors de la génération du PDF'], 500);
}

/**
 * Créer un modèle de facture par défaut
 */
function createDefaultInvoiceTemplate($path) {
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    
    $template = <<<'TEMPLATE'
<?php
/**
 * Modèle de facture personnalisable
 * 
 * Variables disponibles:
 * - $client: Données du client (name, numero_client, etc.)
 * - $invoice: Données de la facture (invoice_number, invoice_date, period_start, period_end, etc.)
 * - $pdf: Instance TCPDF pour personnaliser le PDF
 */

// Configuration de la page
$pdf->SetCreator('CCComputer');
$pdf->SetAuthor('CCComputer');
$pdf->SetTitle('Facture ' . $invoice['invoice_number']);
$pdf->SetSubject('Facture');

// En-tête personnalisé
function printHeader($pdf, $client, $invoice) {
    // Logo (si disponible)
    $logoPath = __DIR__ . '/../assets/images/logo.png';
    if (file_exists($logoPath)) {
        $pdf->Image($logoPath, 15, 15, 40, 0, 'PNG');
    }
    
    // Informations entreprise
    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->SetXY(60, 15);
    $pdf->Cell(0, 10, 'CCComputer', 0, 1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Adresse de l\'entreprise', 0, 1);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Code postal Ville', 0, 1);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Téléphone: XX XX XX XX XX', 0, 1);
    $pdf->SetX(60);
    $pdf->Cell(0, 5, 'Email: contact@cccomputer.fr', 0, 1);
    
    // Numéro de facture et date
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->SetXY(140, 15);
    $pdf->Cell(50, 10, 'FACTURE', 0, 1, 'R');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetXY(140, 25);
    $pdf->Cell(50, 5, 'N°: ' . $invoice['invoice_number'], 0, 1, 'R');
    $pdf->SetXY(140, 30);
    $pdf->Cell(50, 5, 'Date: ' . date('d/m/Y', strtotime($invoice['invoice_date'])), 0, 1, 'R');
    $pdf->SetXY(140, 35);
    $pdf->Cell(50, 5, 'Échéance: ' . date('d/m/Y', strtotime($invoice['due_date'])), 0, 1, 'R');
}

// Informations client
function printClientInfo($pdf, $client) {
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetXY(15, 55);
    $pdf->Cell(0, 8, 'Facturé à:', 0, 1);
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetXY(15, 63);
    $pdf->Cell(0, 5, $client['name'], 0, 1);
    $pdf->SetXY(15, 68);
    $pdf->Cell(0, 5, 'Client N°: ' . $client['numero_client'], 0, 1);
    // Ajouter d'autres informations client si disponibles
}

// Détails de la facture
function printInvoiceDetails($pdf, $invoice) {
    $y = 85;
    
    // En-tête du tableau
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetXY(15, $y);
    $pdf->Cell(80, 8, 'Période', 1, 0, 'L', true);
    $pdf->Cell(30, 8, 'NB Pages', 1, 0, 'C', true);
    $pdf->Cell(30, 8, 'Couleur Pages', 1, 0, 'C', true);
    $pdf->Cell(35, 8, 'Montant', 1, 1, 'R', true);
    
    $y += 8;
    
    // Période
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY(15, $y);
    $periodStart = date('d/m/Y', strtotime($invoice['period_start']));
    $periodEnd = date('d/m/Y', strtotime($invoice['period_end']));
    $pdf->Cell(80, 8, $periodStart . ' - ' . $periodEnd, 1, 0, 'L');
    $pdf->Cell(30, 8, number_format($invoice['nb_pages'], 0, ',', ' '), 1, 0, 'C');
    $pdf->Cell(30, 8, number_format($invoice['color_pages'], 0, ',', ' '), 1, 0, 'C');
    $pdf->Cell(35, 8, number_format($invoice['total_amount'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $y += 8;
    
    // Détail NB
    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetXY(15, $y);
    $pdf->Cell(80, 6, '  Noir et Blanc (' . number_format($invoice['nb_pages'], 0, ',', ' ') . ' pages)', 1, 0, 'L');
    $pdf->Cell(30, 6, '', 1, 0);
    $pdf->Cell(30, 6, '', 1, 0);
    $pdf->Cell(35, 6, number_format($invoice['nb_amount'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $y += 6;
    
    // Détail Couleur
    $pdf->SetXY(15, $y);
    $pdf->Cell(80, 6, '  Couleur (' . number_format($invoice['color_pages'], 0, ',', ' ') . ' pages)', 1, 0, 'L');
    $pdf->Cell(30, 6, '', 1, 0);
    $pdf->Cell(30, 6, '', 1, 0);
    $pdf->Cell(35, 6, number_format($invoice['color_amount'], 2, ',', ' ') . ' €', 1, 1, 'R');
    
    $y += 6;
    
    // Total
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->SetXY(15, $y);
    $pdf->Cell(140, 8, 'TOTAL TTC', 1, 0, 'R', true);
    $pdf->Cell(35, 8, number_format($invoice['total_amount'], 2, ',', ' ') . ' €', 1, 1, 'R', true);
}

// Pied de page
function printFooter($pdf, $invoice) {
    $pdf->SetY(-40);
    $pdf->SetFont('helvetica', '', 8);
    $pdf->SetTextColor(128, 128, 128);
    $pdf->Cell(0, 5, 'Merci de votre confiance !', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Pour toute question, contactez-nous à contact@cccomputer.fr', 0, 1, 'C');
    
    // Statut de paiement
    $pdf->SetFont('helvetica', 'B', 10);
    $pdf->SetTextColor(0, 0, 0);
    $statusText = '';
    $statusColor = [0, 0, 0];
    if ($invoice['status'] === 'paid') {
        $statusText = 'FACTURE PAYÉE';
        $statusColor = [0, 128, 0];
    } elseif ($invoice['status'] === 'overdue') {
        $statusText = 'FACTURE EN RETARD';
        $statusColor = [255, 0, 0];
    } else {
        $statusText = 'FACTURE EN ATTENTE DE PAIEMENT';
        $statusColor = [255, 165, 0];
    }
    
    $pdf->SetTextColor($statusColor[0], $statusColor[1], $statusColor[2]);
    $pdf->Cell(0, 8, $statusText, 0, 1, 'C');
}

TEMPLATE;
    
    file_put_contents($path, $template);
}

/**
 * Générer le PDF de la facture
 */
function generateInvoicePDF($client, $invoice, $templatePath) {
    // Vérifier que TCPDF est disponible
    if (!class_exists('TCPDF')) {
        error_log('TCPDF class not found. Vendor autoload may not be working.');
        http_response_code(500);
        die('Erreur: Bibliothèque PDF non disponible. Veuillez contacter l\'administrateur.');
    }
    
    // Créer une instance TCPDF
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Configuration de base
    $pdf->SetCreator('CCComputer');
    $pdf->SetAuthor('CCComputer');
    $pdf->SetTitle('Facture ' . $invoice['invoice_number']);
    $pdf->SetSubject('Facture');
    
    // Supprimer les en-têtes et pieds de page par défaut
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    
    // Ajouter une page
    $pdf->AddPage();
    
    // Inclure le modèle personnalisé
    include $templatePath;
    
    // Appeler les fonctions du modèle
    printHeader($pdf, $client, $invoice);
    printClientInfo($pdf, $client);
    printInvoiceDetails($pdf, $invoice);
    printFooter($pdf, $invoice);
    
    // Générer et envoyer le PDF
    $filename = $invoice['invoice_number'] . '.pdf';
    $pdf->Output($filename, 'D'); // 'D' = téléchargement direct
    exit;
}

