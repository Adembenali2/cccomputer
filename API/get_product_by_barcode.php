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
    
    $product = null;
    $productType = null;
    
    // Rechercher dans paper_catalog
    $stmt = $pdo->prepare("
        SELECT 
            id,
            'papier' as type,
            marque,
            modele,
            poids,
            CONCAT(marque, ' ', modele) as nom,
            CONCAT('Papier ', marque, ' ', modele, ' - Poids: ', poids) as description,
            barcode
        FROM paper_catalog
        WHERE barcode = :barcode
        LIMIT 1
    ");
    $stmt->execute([':barcode' => $barcode]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($product) {
        // Récupérer la quantité en stock depuis la vue
        $stmt = $pdo->prepare("
            SELECT qty_stock 
            FROM v_paper_stock 
            WHERE paper_id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $product['id']]);
        $qty = (int)($stmt->fetchColumn() ?? 0);
        $product['qty_stock'] = $qty;
        $productType = 'papier';
    } else {
        // Rechercher dans toner_catalog
        $stmt = $pdo->prepare("
            SELECT 
                id,
                'toner' as type,
                marque,
                modele,
                couleur,
                CONCAT(marque, ' ', modele) as nom,
                CONCAT('Toner ', marque, ' ', modele, ' - Couleur: ', couleur) as description,
                barcode
            FROM toner_catalog
            WHERE barcode = :barcode
            LIMIT 1
        ");
        $stmt->execute([':barcode' => $barcode]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            // Récupérer la quantité en stock depuis la vue
            $stmt = $pdo->prepare("
                SELECT qty_stock 
                FROM v_toner_stock 
                WHERE toner_id = :id
                LIMIT 1
            ");
            $stmt->execute([':id' => $product['id']]);
            $qty = (int)($stmt->fetchColumn() ?? 0);
            $product['qty_stock'] = $qty;
            $productType = 'toner';
        } else {
            // Rechercher dans lcd_catalog
            $stmt = $pdo->prepare("
                SELECT 
                    id,
                    'lcd' as type,
                    marque,
                    modele,
                    reference,
                    taille,
                    resolution,
                    CONCAT(marque, ' ', modele) as nom,
                    CONCAT('LCD ', marque, ' ', modele, ' - ', taille, '\" - ', resolution) as description,
                    barcode
                FROM lcd_catalog
                WHERE barcode = :barcode
                LIMIT 1
            ");
            $stmt->execute([':barcode' => $barcode]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                // Récupérer la quantité en stock depuis la vue
                $stmt = $pdo->prepare("
                    SELECT qty_stock 
                    FROM v_lcd_stock 
                    WHERE lcd_id = :id
                    LIMIT 1
                ");
                $stmt->execute([':id' => $product['id']]);
                $qty = (int)($stmt->fetchColumn() ?? 0);
                $product['qty_stock'] = $qty;
                $productType = 'lcd';
            } else {
                // Rechercher dans pc_catalog
                $stmt = $pdo->prepare("
                    SELECT 
                        id,
                        'pc' as type,
                        marque,
                        modele,
                        reference,
                        cpu,
                        ram,
                        CONCAT(marque, ' ', modele) as nom,
                        CONCAT('PC ', marque, ' ', modele, ' - CPU: ', cpu, ' - RAM: ', ram) as description,
                        barcode
                    FROM pc_catalog
                    WHERE barcode = :barcode
                    LIMIT 1
                ");
                $stmt->execute([':barcode' => $barcode]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    // Récupérer la quantité en stock depuis la vue
                    $stmt = $pdo->prepare("
                        SELECT qty_stock 
                        FROM v_pc_stock 
                        WHERE pc_id = :id
                        LIMIT 1
                    ");
                    $stmt->execute([':id' => $product['id']]);
                    $qty = (int)($stmt->fetchColumn() ?? 0);
                    $product['qty_stock'] = $qty;
                    $productType = 'pc';
                }
            }
        }
    }
    
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

