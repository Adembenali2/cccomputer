<?php
/**
 * Template pour le justificatif de paiement PDF
 * 
 * Variables disponibles:
 * - $client: array avec les informations du client
 * - $payment: array avec les informations du paiement
 * - $pdf: instance TCPDF
 */

function printReceiptHeader($pdf, $client, $payment) {
    // En-tête avec logo et informations
    $pdf->SetFont('helvetica', 'B', 20);
    $pdf->SetTextColor(59, 130, 246); // Bleu
    $pdf->Cell(0, 10, 'JUSTIFICATIF DE PAIEMENT', 0, 1, 'C');
    
    $pdf->SetFont('helvetica', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(5);
}

function printReceiptClientInfo($pdf, $client) {
    // Informations client
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'INFORMATIONS CLIENT', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(50, 6, 'Raison sociale:', 0, 0);
    $pdf->Cell(0, 6, htmlspecialchars($client['raison_sociale'] ?? 'N/A'), 0, 1);
    
    $pdf->Cell(50, 6, 'Numéro client:', 0, 0);
    $pdf->Cell(0, 6, htmlspecialchars($client['numero_client'] ?? 'N/A'), 0, 1);
    
    if (!empty($client['adresse'])) {
        $pdf->Cell(50, 6, 'Adresse:', 0, 0);
        $address = htmlspecialchars($client['adresse']);
        if (!empty($client['code_postal'])) {
            $address .= ' ' . htmlspecialchars($client['code_postal']);
        }
        if (!empty($client['ville'])) {
            $address .= ' ' . htmlspecialchars($client['ville']);
        }
        $pdf->Cell(0, 6, $address, 0, 1);
    }
    
    if (!empty($client['email'])) {
        $pdf->Cell(50, 6, 'Email:', 0, 0);
        $pdf->Cell(0, 6, htmlspecialchars($client['email']), 0, 1);
    }
    
    $pdf->Ln(5);
}

function printReceiptPaymentDetails($pdf, $payment) {
    // Détails du paiement
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->Cell(0, 8, 'DÉTAILS DU PAIEMENT', 0, 1);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(50, 6, 'Date de paiement:', 0, 0);
    $paymentDate = !empty($payment['date']) ? date('d/m/Y', strtotime($payment['date'])) : date('d/m/Y');
    $pdf->Cell(0, 6, $paymentDate, 0, 1);
    
    $pdf->Cell(50, 6, 'Montant:', 0, 0);
    $pdf->SetFont('helvetica', 'B', 12);
    $pdf->SetTextColor(16, 185, 129); // Vert
    $amount = number_format($payment['amount'] ?? 0, 2, ',', ' ') . ' €';
    $pdf->Cell(0, 6, $amount, 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetFont('helvetica', '', 10);
    
    $pdf->Cell(50, 6, 'Type de paiement:', 0, 0);
    $typeLabels = [
        'especes' => 'Espèces',
        'cheque' => 'Chèque',
        'virement' => 'Virement'
    ];
    $typeLabel = $typeLabels[$payment['type'] ?? ''] ?? ucfirst($payment['type'] ?? 'N/A');
    $pdf->Cell(0, 6, $typeLabel, 0, 1);
    
    if (!empty($payment['reference'])) {
        $pdf->Cell(50, 6, 'Référence:', 0, 0);
        $pdf->Cell(0, 6, htmlspecialchars($payment['reference']), 0, 1);
    }
    
    if (!empty($payment['iban'])) {
        $pdf->Cell(50, 6, 'IBAN:', 0, 0);
        $pdf->Cell(0, 6, htmlspecialchars($payment['iban']), 0, 1);
    }
    
    if (!empty($payment['notes'])) {
        $pdf->Ln(3);
        $pdf->Cell(50, 6, 'Notes:', 0, 0);
        $pdf->MultiCell(0, 6, htmlspecialchars($payment['notes']), 0, 1);
    }
    
    $pdf->Ln(5);
}

function printReceiptFooter($pdf, $payment) {
    // Pied de page
    $pdf->SetY(-40);
    $pdf->SetFont('helvetica', 'I', 8);
    $pdf->SetTextColor(100, 100, 100);
    $pdf->Cell(0, 5, 'Ce document est un justificatif de paiement émis automatiquement.', 0, 1, 'C');
    $pdf->Cell(0, 5, 'Date d\'émission: ' . date('d/m/Y H:i'), 0, 1, 'C');
    
    if (!empty($payment['receipt_number'])) {
        $pdf->Cell(0, 5, 'Numéro de justificatif: ' . htmlspecialchars($payment['receipt_number']), 0, 1, 'C');
    }
}

