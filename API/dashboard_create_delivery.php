<?php
// API pour créer une livraison et déduire le stock (pour dashboard)
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
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
} catch (Throwable $e) {
    error_log('dashboard_create_delivery.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

// Lire les données JSON ou POST
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Si pas de JSON, utiliser POST
if (!is_array($data)) {
    $data = $_POST;
}

if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'Données invalides'], 400);
}

// Vérification CSRF
$csrfToken = $data['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

// Validation des données
$idClient = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$reference = trim($data['reference'] ?? '');
$adresseLivraison = trim($data['adresse_livraison'] ?? '');
$objet = trim($data['objet'] ?? '');
$idLivreur = isset($data['id_livreur']) ? (int)$data['id_livreur'] : 0;
$datePrevue = trim($data['date_prevue'] ?? '');
$commentaire = trim($data['commentaire'] ?? '');

// Données produit (optionnelles - pour déduire le stock)
$productType = trim($data['product_type'] ?? '');
$productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
$productQty = isset($data['product_qty']) ? (int)$data['product_qty'] : 1; // Quantité à déduire

$errors = [];
if ($idClient <= 0) $errors[] = "ID client invalide";
if (empty($reference)) $errors[] = "Référence obligatoire";
if (empty($adresseLivraison)) $errors[] = "Adresse de livraison obligatoire";
if (empty($objet)) $errors[] = "Objet obligatoire";
if ($idLivreur <= 0) $errors[] = "Livreur obligatoire";
if (empty($datePrevue)) $errors[] = "Date prévue obligatoire";

if (!empty($errors)) {
    jsonResponse(['ok' => false, 'error' => implode(', ', $errors)], 400);
}

// Valider la date
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePrevue)) {
    jsonResponse(['ok' => false, 'error' => 'Format de date invalide'], 400);
}

try {
    $pdo->beginTransaction();
    
    // Vérifier que la référence n'existe pas déjà
    $checkRef = $pdo->prepare("SELECT id FROM livraisons WHERE reference = :ref LIMIT 1");
    $checkRef->execute([':ref' => $reference]);
    if ($checkRef->fetch()) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Cette référence existe déjà'], 400);
    }
    
    // Vérifier que le client existe
    $checkClient = $pdo->prepare("SELECT id, raison_sociale FROM clients WHERE id = :id LIMIT 1");
    $checkClient->execute([':id' => $idClient]);
    $client = $checkClient->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
    }
    
    // Vérifier que le livreur existe et est bien un utilisateur avec Emploi = 'Livreur' et statut = 'actif'
    // Le champ Emploi est un ENUM, on vérifie strictement que c'est bien un livreur
    $checkLivreur = $pdo->prepare("
        SELECT id, nom, prenom, Emploi, statut 
        FROM utilisateurs 
        WHERE id = :id 
          AND Emploi = 'Livreur' 
          AND statut = 'actif' 
        LIMIT 1
    ");
    $checkLivreur->execute([':id' => $idLivreur]);
    $livreur = $checkLivreur->fetch(PDO::FETCH_ASSOC);
    if (!$livreur) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Livreur introuvable ou inactif. Vérifiez que l\'utilisateur a le rôle "Livreur" et est actif.'], 404);
    }
    
    // Double vérification que c'est bien un livreur (sécurité supplémentaire)
    if (($livreur['Emploi'] ?? '') !== 'Livreur' || ($livreur['statut'] ?? '') !== 'actif') {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'L\'utilisateur sélectionné n\'est pas un livreur actif.'], 400);
    }
    
    // Si un produit est sélectionné, vérifier le stock et déduire
    if ($productType && $productId > 0 && in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) {
        // Vérifier le stock disponible
        $stockCheck = null;
        $stockLabel = '';
        
        switch ($productType) {
            case 'papier':
                $stockCheck = $pdo->prepare("SELECT paper_id AS id, marque, modele, poids, qty_stock FROM v_paper_stock WHERE paper_id = :id LIMIT 1");
                $stockCheck->execute([':id' => $productId]);
                $stock = $stockCheck->fetch(PDO::FETCH_ASSOC);
                if ($stock) {
                    $stockLabel = trim($stock['marque'] . ' ' . $stock['modele'] . ' ' . $stock['poids']);
                    if ((int)$stock['qty_stock'] < $productQty) {
                        $pdo->rollBack();
                        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Disponible: ' . $stock['qty_stock']], 400);
                    }
                    // Déduire le stock (ajouter un mouvement négatif avec reason='retour' pour sortie de stock)
                    $moveSql = "INSERT INTO paper_moves (paper_id, qty_delta, reason, reference, user_id) VALUES (:paper_id, :qty_delta, 'retour', :ref, :user_id)";
                    $moveStmt = $pdo->prepare($moveSql);
                    $moveStmt->execute([
                        ':paper_id' => $productId,
                        ':qty_delta' => -abs($productQty), // Toujours négatif pour déduire
                        ':ref' => $reference . ' (livraison)',
                        ':user_id' => $_SESSION['user_id']
                    ]);
                }
                break;
                
            case 'toner':
                $stockCheck = $pdo->prepare("SELECT toner_id AS id, marque, modele, couleur, qty_stock FROM v_toner_stock WHERE toner_id = :id LIMIT 1");
                $stockCheck->execute([':id' => $productId]);
                $stock = $stockCheck->fetch(PDO::FETCH_ASSOC);
                if ($stock) {
                    $stockLabel = trim($stock['marque'] . ' ' . $stock['modele'] . ' ' . $stock['couleur']);
                    if ((int)$stock['qty_stock'] < $productQty) {
                        $pdo->rollBack();
                        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Disponible: ' . $stock['qty_stock']], 400);
                    }
                    // Déduire le stock
                    $moveSql = "INSERT INTO toner_moves (toner_id, qty_delta, reason, reference, user_id) VALUES (:toner_id, :qty_delta, 'retour', :ref, :user_id)";
                    $moveStmt = $pdo->prepare($moveSql);
                    $moveStmt->execute([
                        ':toner_id' => $productId,
                        ':qty_delta' => -abs($productQty), // Toujours négatif pour déduire
                        ':ref' => $reference . ' (livraison)',
                        ':user_id' => $_SESSION['user_id']
                    ]);
                }
                break;
                
            case 'lcd':
                $stockCheck = $pdo->prepare("SELECT lcd_id AS id, marque, modele, reference, qty_stock FROM v_lcd_stock WHERE lcd_id = :id LIMIT 1");
                $stockCheck->execute([':id' => $productId]);
                $stock = $stockCheck->fetch(PDO::FETCH_ASSOC);
                if ($stock) {
                    $stockLabel = trim($stock['marque'] . ' ' . $stock['modele'] . ' (' . $stock['reference'] . ')');
                    if ((int)$stock['qty_stock'] < $productQty) {
                        $pdo->rollBack();
                        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Disponible: ' . $stock['qty_stock']], 400);
                    }
                    // Déduire le stock
                    $moveSql = "INSERT INTO lcd_moves (lcd_id, qty_delta, reason, reference, user_id) VALUES (:lcd_id, :qty_delta, 'retour', :ref, :user_id)";
                    $moveStmt = $pdo->prepare($moveSql);
                    $moveStmt->execute([
                        ':lcd_id' => $productId,
                        ':qty_delta' => -abs($productQty), // Toujours négatif pour déduire
                        ':ref' => $reference . ' (livraison)',
                        ':user_id' => $_SESSION['user_id']
                    ]);
                }
                break;
                
            case 'pc':
                $stockCheck = $pdo->prepare("SELECT pc_id AS id, marque, modele, reference, qty_stock FROM v_pc_stock WHERE pc_id = :id LIMIT 1");
                $stockCheck->execute([':id' => $productId]);
                $stock = $stockCheck->fetch(PDO::FETCH_ASSOC);
                if ($stock) {
                    $stockLabel = trim($stock['marque'] . ' ' . $stock['modele'] . ' (' . $stock['reference'] . ')');
                    if ((int)$stock['qty_stock'] < $productQty) {
                        $pdo->rollBack();
                        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Disponible: ' . $stock['qty_stock']], 400);
                    }
                    // Déduire le stock
                    $moveSql = "INSERT INTO pc_moves (pc_id, qty_delta, reason, reference, user_id) VALUES (:pc_id, :qty_delta, 'retour', :ref, :user_id)";
                    $moveStmt = $pdo->prepare($moveSql);
                    $moveStmt->execute([
                        ':pc_id' => $productId,
                        ':qty_delta' => -abs($productQty), // Toujours négatif pour déduire
                        ':ref' => $reference . ' (livraison)',
                        ':user_id' => $_SESSION['user_id']
                    ]);
                }
                break;
        }
        
        if (!$stock) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Produit introuvable dans le stock'], 404);
        }
    }
    
    // Insérer la livraison
    $sql = "
        INSERT INTO livraisons (
            id_client,
            id_livreur,
            reference,
            adresse_livraison,
            objet,
            date_prevue,
            commentaire,
            statut
        ) VALUES (
            :id_client,
            :id_livreur,
            :reference,
            :adresse_livraison,
            :objet,
            :date_prevue,
            :commentaire,
            'planifiee'
        )
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_client' => $idClient,
        ':id_livreur' => $idLivreur,
        ':reference' => $reference,
        ':adresse_livraison' => $adresseLivraison,
        ':objet' => $objet,
        ':date_prevue' => $datePrevue,
        ':commentaire' => empty($commentaire) ? null : $commentaire
    ]);
    
    $livraisonId = (int)$pdo->lastInsertId();
    
    $pdo->commit();
    
    // Enregistrer dans l'historique
    try {
        $details = sprintf('Livraison créée: %s pour client %s (ID %d), livreur %s %s (ID %d), date prévue: %s', 
            $reference, $client['raison_sociale'], $idClient, 
            $livreur['prenom'], $livreur['nom'], $idLivreur, 
            $datePrevue);
        if ($productType && $productId > 0) {
            $details .= ', produit: ' . $stockLabel . ' (quantité: ' . $productQty . ')';
        }
        enregistrerAction($pdo, $_SESSION['user_id'], 'livraison_creee', $details);
    } catch (Throwable $e) {
        error_log('dashboard_create_delivery.php log error: ' . $e->getMessage());
    }
    
    jsonResponse(['ok' => true, 'livraison_id' => $livraisonId, 'message' => 'Livraison créée avec succès' . ($productType && $productId > 0 ? ' et stock déduit' : '')]);
    
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('dashboard_create_delivery.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('dashboard_create_delivery.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

