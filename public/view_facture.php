<?php
/**
 * Script pour afficher/servir un PDF de facture
 * Régénère automatiquement le PDF s'il n'existe pas
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
    
    // Récupérer la facture avec toutes les données nécessaires
    $stmt = $pdo->prepare("
        SELECT f.*, c.raison_sociale, c.adresse, c.code_postal, c.ville, c.nom_dirigeant, c.prenom_dirigeant
        FROM factures f
        LEFT JOIN clients c ON f.id_client = c.id
        WHERE f.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $factureId]);
    $facture = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$facture) {
        http_response_code(404);
        die('Facture introuvable');
    }
    
    $pdfWebPath = $facture['pdf_path'];
    $pdfPath = null;
    
    // Si un chemin PDF existe, essayer de le trouver
    if (!empty($pdfWebPath)) {
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
        
        foreach ($possibleBaseDirs as $baseDir) {
            $testPath = $baseDir . '/uploads/factures/' . $relativePath;
            if (file_exists($testPath) && is_file($testPath)) {
                $pdfPath = $testPath;
                break;
            }
        }
    }
    
    // Si le fichier n'existe pas, régénérer le PDF automatiquement
    if (!$pdfPath) {
        error_log('view_facture.php: Fichier PDF non trouvé, régénération automatique pour facture ID: ' . $factureId);
        
        // Récupérer les lignes de facture
        $stmtLignes = $pdo->prepare("SELECT * FROM facture_lignes WHERE id_facture = :id ORDER BY ordre ASC");
        $stmtLignes->execute([':id' => $factureId]);
        $lignes = $stmtLignes->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($lignes)) {
            http_response_code(404);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erreur</title></head><body>';
            echo '<h1>Erreur</h1>';
            echo '<p>Impossible de régénérer le PDF : aucune ligne de facture trouvée.</p>';
            echo '</body></html>';
            exit;
        }
        
        // Préparer les données pour la régénération
        $data = [
            'lignes' => []
        ];
        foreach ($lignes as $ligne) {
            $data['lignes'][] = [
                'description' => $ligne['description'],
                'type' => $ligne['type'],
                'quantite' => $ligne['quantite'],
                'prix_unitaire_ht' => $ligne['prix_unitaire_ht']
            ];
        }
        
        // Préparer les données client
        $client = [
            'raison_sociale' => $facture['raison_sociale'] ?? '',
            'adresse' => $facture['adresse'] ?? '',
            'code_postal' => $facture['code_postal'] ?? '',
            'ville' => $facture['ville'] ?? ''
        ];
        
        // Inclure le fichier qui contient la fonction generateFacturePDF
        require_once __DIR__ . '/../API/factures_generer.php';
        
        // Régénérer le PDF
        $newPdfPath = generateFacturePDF($pdo, $factureId, $client, $data);
        
        // Mettre à jour le chemin dans la DB
        $stmt = $pdo->prepare("UPDATE factures SET pdf_path = ? WHERE id = ?");
        $stmt->execute([$newPdfPath, $factureId]);
        
        // Retrouver le fichier régénéré
        $relativePath = preg_replace('#^/uploads/factures/#', '', $newPdfPath);
        foreach ($possibleBaseDirs as $baseDir) {
            $testPath = $baseDir . '/uploads/factures/' . $relativePath;
            if (file_exists($testPath) && is_file($testPath)) {
                $pdfPath = $testPath;
                break;
            }
        }
        
        // Si toujours pas trouvé après régénération, erreur
        if (!$pdfPath) {
            error_log('view_facture.php: Impossible de trouver le PDF après régénération');
            http_response_code(500);
            header('Content-Type: text/html; charset=utf-8');
            echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Erreur</title></head><body>';
            echo '<h1>Erreur</h1>';
            echo '<p>Le PDF a été régénéré mais n\'a pas pu être trouvé sur le serveur.</p>';
            echo '</body></html>';
            exit;
        }
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
