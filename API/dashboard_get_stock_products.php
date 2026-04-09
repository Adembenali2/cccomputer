<?php
// API pour récupérer les produits du stock par type (pour dashboard)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$type = trim($_GET['type'] ?? '');

if (!in_array($type, ['papier', 'toner', 'lcd', 'pc'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Type de produit invalide'], 400);
}

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

try {
    $products = [];
    $hasModele = columnExists($pdo, 'stock', 'modele');
    $hasModeleCompatible = columnExists($pdo, 'stock', 'modele_compatible');
    $modeleExpr = $hasModele ? 's.modele' : ($hasModeleCompatible ? 's.modele_compatible' : 'NULL');
    
    $catWhere = [
        'papier' => "s.categorie = 'papier'",
        'toner' => "s.categorie IN ('toner_noir','toner_cyan','toner_magenta','toner_jaune')",
        'lcd' => "s.categorie = 'ecran_lcd'",
        'pc' => "s.categorie = 'pc'",
    ][$type];
    $sql = "SELECT s.id, s.marque, COALESCE({$modeleExpr}, s.designation) AS modele, s.reference, s.quantite, s.categorie, s.couleur_toner
            FROM stock s
            WHERE s.actif = 1 AND s.quantite > 0 AND {$catWhere}
            ORDER BY s.categorie, s.designation";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $products[] = [
            'id' => (int)$r['id'],
            'type' => $type,
            'label' => trim(($r['marque'] ?? '') . ' ' . ($r['modele'] ?? '') . ' (' . ($r['reference'] ?? '') . ')'),
            'marque' => $r['marque'] ?? '',
            'modele' => $r['modele'] ?? '',
            'reference' => $r['reference'] ?? '',
            'couleur' => $r['couleur_toner'] ?? '',
            'qty_stock' => (int)($r['quantite'] ?? 0)
        ];
    }
    
    jsonResponse(['ok' => true, 'products' => $products]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_stock_products.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_stock_products.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

