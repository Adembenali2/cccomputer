<?php
/**
 * API endpoint pour récupérer un produit par son code-barres
 * Recherche dans toutes les tables de catalogues (paper, toner, lcd, pc)
 * 
 * @package CCComputer
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

// Vérifier l'authentification
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Récupérer le code-barres depuis GET ou POST
    $barcode = '';
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $barcode = trim($_GET['barcode'] ?? '');
    } else {
        $raw = file_get_contents('php://input');
        $jsonData = json_decode($raw, true);
        $barcode = trim($jsonData['barcode'] ?? '');
    }
    
    if (empty($barcode)) {
        jsonResponse(['ok' => false, 'error' => 'Code-barres manquant'], 400);
    }
    
    $stmt = $pdo->prepare("
        SELECT id, categorie AS type, marque, modele, reference, cpu, ram, couleur_toner,
               designation AS nom, quantite AS qty_stock, qr_code
        FROM stock
        WHERE actif = 1 AND (qr_code = :barcode OR reference = :barcode)
        LIMIT 1
    ");
    $stmt->execute([':barcode' => $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    $productType = $product['type'] ?? null;
    
    if (!$product) {
        jsonResponse([
            'ok' => false, 
            'error' => 'Produit non trouvé',
            'barcode' => $barcode
        ], 404);
    }
    
    jsonResponse([
        'ok' => true,
        'product' => $product,
        'type' => $productType
    ], 200);
    
} catch (PDOException $e) {
    error_log('get_product_by_barcode.php PDO error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('get_product_by_barcode.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur'], 500);
}

