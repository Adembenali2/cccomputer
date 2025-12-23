<?php
/**
 * Script pour afficher/servir un PDF de facture
 * Gère le cas où le fichier n'existe pas et affiche un message d'erreur clair
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

// Récupérer l'ID de la facture depuis l'URL
$factureId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($factureId <= 0) {
    http_response_code(400);
    die('ID de facture invalide');
}

try {
    $pdo = getPdo();
    
    // Récupérer la facture
    $stmt = $pdo->prepare("
        SELECT id, numero, pdf_path, id_client 
        FROM factures 
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        http_response_code(404);
        die('Facture introuvable');
    }
    
    $pdfWebPath = $facture['pdf_path'];
    
    // Si pas de chemin PDF, retourner une erreur
    if (empty($pdfWebPath)) {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PDF introuvable</title></head><body>';
        echo '<h1>PDF introuvable</h1>';
        echo '<p>Aucun PDF associé à la facture <strong>' . htmlspecialchars($facture['numero']) . '</strong>.</p>';
        echo '</body></html>';
        exit;
    }
    
    // Trouver le fichier PDF
    $relativePath = preg_replace('#^/uploads/factures/#', '', $pdfWebPath);
    
    // Tester plusieurs chemins possibles (compatible Railway)
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
    
    $pdfPath = null;
    foreach ($possibleBaseDirs as $baseDir) {
        $testPath = $baseDir . '/uploads/factures/' . $relativePath;
        if (file_exists($testPath) && is_file($testPath)) {
            $pdfPath = $testPath;
            break;
        }
    }
    
    // Si le fichier n'existe pas, afficher un message d'erreur clair
    if (!$pdfPath) {
        error_log('view_facture.php: Fichier PDF non trouvé pour facture ID: ' . $factureId);
        error_log('view_facture.php: Chemin recherché: ' . $pdfWebPath);
        error_log('view_facture.php: Chemins testés:');
        foreach ($possibleBaseDirs as $baseDir) {
            $testPath = $baseDir . '/uploads/factures/' . $relativePath;
            error_log('  - ' . $testPath . ' (existe: ' . (file_exists($testPath) ? 'Oui' : 'Non') . ')');
        }
        
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>PDF introuvable</title>';
        echo '<style>body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }</style>';
        echo '</head><body>';
        echo '<h1>PDF introuvable</h1>';
        echo '<p>Le fichier PDF de la facture <strong>' . htmlspecialchars($facture['numero']) . '</strong> n\'existe pas sur le serveur.</p>';
        echo '<p><strong>Cause probable :</strong> Sur Railway, les fichiers peuvent être perdus lors des redéploiements car le système de fichiers est éphémère.</p>';
        echo '<p><strong>Solution :</strong> Régénérez la facture depuis la section "Générer facture" de la page Paiements.</p>';
        echo '</body></html>';
        exit;
    }
    
    // Vérifier que le fichier est lisible
    if (!is_readable($pdfPath)) {
        http_response_code(500);
        die('Le fichier PDF n\'est pas accessible en lecture');
    }
    
    // Servir le PDF
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="facture_' . $facture['numero'] . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=3600');
    
    readfile($pdfPath);
    exit;
    
} catch (Throwable $e) {
    error_log('view_facture.php error: ' . $e->getMessage());
    error_log('view_facture.php trace: ' . $e->getTraceAsString());
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erreur</title></head><body>';
    echo '<h1>Erreur</h1>';
    echo '<p>Erreur lors de la récupération du PDF: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '</body></html>';
    exit;
}
