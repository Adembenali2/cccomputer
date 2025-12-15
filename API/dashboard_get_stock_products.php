<?php
// API pour récupérer les produits du stock par type (pour dashboard)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200) {
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
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/helpers.php';
} catch (Throwable $e) {
    error_log('dashboard_get_stock_products.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$type = trim($_GET['type'] ?? '');

if (!in_array($type, ['papier', 'toner', 'lcd', 'pc'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Type de produit invalide'], 400);
}

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

try {
    $products = [];
    
    switch ($type) {
        case 'papier':
            $sql = "SELECT paper_id AS id, marque, modele, poids, qty_stock FROM v_paper_stock WHERE qty_stock > 0 ORDER BY marque, modele, poids";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $products[] = [
                    'id' => (int)$r['id'],
                    'type' => 'papier',
                    'label' => trim($r['marque'] . ' ' . $r['modele'] . ' ' . $r['poids']),
                    'marque' => $r['marque'],
                    'modele' => $r['modele'],
                    'poids' => $r['poids'],
                    'qty_stock' => (int)$r['qty_stock']
                ];
            }
            break;
            
        case 'toner':
            $sql = "SELECT toner_id AS id, marque, modele, couleur, qty_stock FROM v_toner_stock WHERE qty_stock > 0 ORDER BY marque, modele, couleur";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $products[] = [
                    'id' => (int)$r['id'],
                    'type' => 'toner',
                    'label' => trim($r['marque'] . ' ' . $r['modele'] . ' ' . $r['couleur']),
                    'marque' => $r['marque'],
                    'modele' => $r['modele'],
                    'couleur' => $r['couleur'],
                    'qty_stock' => (int)$r['qty_stock']
                ];
            }
            break;
            
        case 'lcd':
            $sql = "SELECT lcd_id AS id, marque, reference, modele, taille, qty_stock FROM v_lcd_stock WHERE qty_stock > 0 ORDER BY marque, modele, taille";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $products[] = [
                    'id' => (int)$r['id'],
                    'type' => 'lcd',
                    'label' => trim($r['marque'] . ' ' . $r['modele'] . ' ' . $r['taille'] . '" (' . $r['reference'] . ')'),
                    'marque' => $r['marque'],
                    'reference' => $r['reference'],
                    'modele' => $r['modele'],
                    'taille' => (int)$r['taille'],
                    'qty_stock' => (int)$r['qty_stock']
                ];
            }
            break;
            
        case 'pc':
            $sql = "SELECT pc_id AS id, marque, modele, reference, qty_stock FROM v_pc_stock WHERE qty_stock > 0 ORDER BY marque, modele, reference";
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $products[] = [
                    'id' => (int)$r['id'],
                    'type' => 'pc',
                    'label' => trim($r['marque'] . ' ' . $r['modele'] . ' (' . $r['reference'] . ')'),
                    'marque' => $r['marque'],
                    'modele' => $r['modele'],
                    'reference' => $r['reference'],
                    'qty_stock' => (int)$r['qty_stock']
                ];
            }
            break;
    }
    
    jsonResponse(['ok' => true, 'products' => $products]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_stock_products.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_stock_products.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

