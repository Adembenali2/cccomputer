<?php
// API pour créer une livraison et déduire le stock (pour dashboard)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

require_once __DIR__ . '/../includes/historique.php';

function tableExists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) AS cnt
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
        ");
        $stmt->execute([':table' => $table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return ((int)($row['cnt'] ?? 0)) > 0;
    } catch (Throwable $e) {
        return false;
    }
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
requireCsrfToken($data['csrf_token'] ?? null);

// Validation des données
$idClient = isset($data['client_id']) ? (int)$data['client_id'] : 0;
$reference = trim($data['reference'] ?? '');
$adresseLivraison = trim($data['adresse_livraison'] ?? '');
$objet = trim($data['objet'] ?? '');
$idLivreur = isset($data['id_livreur']) ? (int)$data['id_livreur'] : 0;   // peut être 0 => auto-assign
$datePrevue = trim($data['date_prevue'] ?? '');
$commentaire = trim($data['commentaire'] ?? '');

// Données produit (optionnelles - pour déduire le stock et enregistrer pour le client)
$productType = trim($data['product_type'] ?? '');
$productId = isset($data['product_id']) ? (int)$data['product_id'] : 0;
$productQty = isset($data['product_qty']) ? max(1, (int)$data['product_qty']) : 1; // Quantité à déduire et à livrer au client

$errors = [];
if ($idClient <= 0) $errors[] = "ID client invalide";
if (empty($reference)) $errors[] = "Référence obligatoire";
if (empty($adresseLivraison)) $errors[] = "Adresse de livraison obligatoire";
if (empty($objet)) $errors[] = "Objet obligatoire";
// ⚠️ ON NE MET PLUS "Livreur obligatoire" ici, car on va l’auto-sélectionner si besoin
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
    $hasStockModele = columnExists($pdo, 'stock', 'modele');
    $hasStockModeleCompatible = columnExists($pdo, 'stock', 'modele_compatible');
    $stockModeleExpr = $hasStockModele ? 'modele' : ($hasStockModeleCompatible ? 'modele_compatible' : 'NULL');
    $hasLivProductType = columnExists($pdo, 'livraisons', 'product_type');
    $hasLivProductId = columnExists($pdo, 'livraisons', 'product_id');
    $hasLivProductQty = columnExists($pdo, 'livraisons', 'product_qty');
    $useLivProductCols = $hasLivProductType && $hasLivProductId && $hasLivProductQty;
    $userRoleCol = columnExists($pdo, 'utilisateurs', 'Emploi') ? 'Emploi' : (columnExists($pdo, 'utilisateurs', 'emploi') ? 'emploi' : '');
    $userStatusCol = columnExists($pdo, 'utilisateurs', 'statut') ? 'statut' : (columnExists($pdo, 'utilisateurs', 'status') ? 'status' : '');
    $hasMoveCreatedBy = columnExists($pdo, 'stock_mouvements', 'created_by');
    $hasMoveUserId = columnExists($pdo, 'stock_mouvements', 'user_id');
    $hasStockTable = tableExists($pdo, 'stock');
    $hasStockMouvementsTable = tableExists($pdo, 'stock_mouvements');
    
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
    
    // ─────────────────────────────────────────────
    // SÉLECTION / VÉRIFICATION DU LIVREUR
    // ─────────────────────────────────────────────
    // Si aucun id_livreur envoyé, on choisit automatiquement un livreur actif
    // Optimisation: au lieu de RAND() (lent), on prend le premier disponible ou celui avec le moins de livraisons
    if ($idLivreur <= 0) {
        // Sélection optimisée: prendre le livreur avec le moins de livraisons planifiées/en cours
        $whereParts = [];
        if ($userRoleCol !== '') {
            $whereParts[] = "u.`{$userRoleCol}` = 'Livreur'";
        }
        if ($userStatusCol !== '') {
            $whereParts[] = "u.`{$userStatusCol}` = 'actif'";
        }
        $whereSql = $whereParts ? ('WHERE ' . implode(' AND ', $whereParts)) : '';
        $roleSelect = $userRoleCol !== '' ? "u.`{$userRoleCol}` AS Emploi" : "NULL AS Emploi";
        $statusSelect = $userStatusCol !== '' ? "u.`{$userStatusCol}` AS statut" : "NULL AS statut";
        $groupByParts = ['u.id', 'u.nom', 'u.prenom'];
        if ($userRoleCol !== '') {
            $groupByParts[] = "u.`{$userRoleCol}`";
        }
        if ($userStatusCol !== '') {
            $groupByParts[] = "u.`{$userStatusCol}`";
        }
        $groupBySql = implode(', ', $groupByParts);
        $autoLiv = $pdo->prepare("
            SELECT u.id, u.nom, u.prenom, {$roleSelect}, {$statusSelect},
                   COUNT(l.id) AS livraisons_count
            FROM utilisateurs u
            LEFT JOIN livraisons l ON l.id_livreur = u.id 
                AND l.statut IN ('planifiee', 'en_cours')
            {$whereSql}
            GROUP BY {$groupBySql}
            ORDER BY livraisons_count ASC, u.id ASC
            LIMIT 1
        ");
        $autoLiv->execute();
        $livreur = $autoLiv->fetch(PDO::FETCH_ASSOC);
        
        if (!$livreur) {
            $pdo->rollBack();
            jsonResponse([
                'ok'    => false,
                'error' => 'Aucun livreur actif trouvé (Emploi = \"Livreur\").'
            ], 404);
        }
        
        $idLivreur = (int)$livreur['id']; // on force l’ID trouvé
    } else {
        // Si un livreur est fourni, on vérifie qu'il est bien livreur actif
        $whereParts = ["id = :id"];
        if ($userRoleCol !== '') {
            $whereParts[] = "`{$userRoleCol}` = 'Livreur'";
        }
        if ($userStatusCol !== '') {
            $whereParts[] = "`{$userStatusCol}` = 'actif'";
        }
        $checkLivreur = $pdo->prepare("
            SELECT * 
            FROM utilisateurs 
            WHERE " . implode(' AND ', $whereParts) . "
            LIMIT 1
        ");
        $checkLivreur->execute([':id' => $idLivreur]);
        $livreur = $checkLivreur->fetch(PDO::FETCH_ASSOC);
        if (!$livreur) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Livreur introuvable ou inactif. Vérifiez que l\'utilisateur a le rôle \"Livreur\" et est actif.'], 404);
        }
    }
    
    // Double vérification (sécurité supplémentaire)
    if ($userRoleCol !== '' && ($livreur[$userRoleCol] ?? '') !== 'Livreur') {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'L\'utilisateur sélectionné n\'est pas un livreur.'], 400);
    }
    if ($userStatusCol !== '' && ($livreur[$userStatusCol] ?? '') !== 'actif') {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Le livreur sélectionné est inactif.'], 400);
    }
    
    // Si un produit est sélectionné, vérifier le stock et déduire
    $stockLabel = ''; // Initialiser pour utilisation dans l'objet de la livraison
    $stock = null; // Initialiser pour vérification
    
    if ($productType && $productId > 0 && in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) {
        if (!$hasStockTable || !$hasStockMouvementsTable) {
            $pdo->rollBack();
            jsonResponse([
                'ok' => false,
                'error' => 'Tables stock manquantes. Exécute la migration stock (stock et stock_mouvements).'
            ], 500);
        }
        // Vérifier le stock disponible
        $stockCheck = null;
        
        $catWhere = [
            'papier' => "categorie='papier'",
            'toner' => "categorie IN ('toner_noir','toner_cyan','toner_magenta','toner_jaune')",
            'lcd' => "categorie='ecran_lcd'",
            'pc' => "categorie='pc'",
        ][$productType] ?? '';
        $stockCheck = $pdo->prepare("SELECT id, marque, COALESCE({$stockModeleExpr}, designation) AS modele, reference, quantite FROM stock WHERE id = :id AND actif = 1 AND {$catWhere} LIMIT 1");
        $stockCheck->execute([':id' => $productId]);
        $stock = $stockCheck->fetch(PDO::FETCH_ASSOC);
        if ($stock) {
            $stockLabel = trim(($stock['marque'] ?? '') . ' ' . ($stock['modele'] ?? '') . ' (' . ($stock['reference'] ?? '') . ')');
            if ((int)$stock['quantite'] < $productQty) {
                $pdo->rollBack();
                jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Disponible: ' . $stock['quantite']], 400);
            }
            $qteAvant = (int)$stock['quantite'];
            $qteApres = $qteAvant - abs($productQty);
            if ($hasMoveCreatedBy || $hasMoveUserId) {
                $userCol = $hasMoveCreatedBy ? 'created_by' : 'user_id';
                $moveStmt = $pdo->prepare("INSERT INTO stock_mouvements (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, {$userCol}) VALUES (:stock_id, 'livraison', :quantite, :qa, :qn, :motif, :ref, :user_id)");
                $moveStmt->execute([
                    ':stock_id' => $productId,
                    ':quantite' => abs($productQty),
                    ':qa' => $qteAvant,
                    ':qn' => $qteApres,
                    ':motif' => 'Livraison client',
                    ':ref' => $reference . ' (livraison)',
                    ':user_id' => $_SESSION['user_id']
                ]);
            } else {
                $moveStmt = $pdo->prepare("INSERT INTO stock_mouvements (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc) VALUES (:stock_id, 'livraison', :quantite, :qa, :qn, :motif, :ref)");
                $moveStmt->execute([
                    ':stock_id' => $productId,
                    ':quantite' => abs($productQty),
                    ':qa' => $qteAvant,
                    ':qn' => $qteApres,
                    ':motif' => 'Livraison client',
                    ':ref' => $reference . ' (livraison)'
                ]);
            }
            $upd = $pdo->prepare("UPDATE stock SET quantite = quantite - :qte_sub WHERE id = :id AND quantite >= :qte_check");
            $upd->execute([
                ':qte_sub' => abs($productQty),
                ':id' => $productId,
                ':qte_check' => abs($productQty)
            ]);
        }
        
        if (!$stock) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Produit introuvable dans le stock'], 404);
        }
    }
    
    // Construire l'objet de la livraison avec le produit et la quantité si un produit est sélectionné
    $objetFinal = $objet;
    if ($productType && $productId > 0 && isset($stockLabel) && $stockLabel !== '') {
        // Ajouter les détails du produit et la quantité dans l'objet pour le client
        // Format : [Description] - [Produit] (Quantité: X)
        $objetFinal = trim($objet);
        if ($objetFinal !== '') {
            $objetFinal .= ' - ' . $stockLabel . ' (Quantité: ' . $productQty . ')';
        } else {
            $objetFinal = $stockLabel . ' (Quantité: ' . $productQty . ')';
        }
    }
    
    // Insérer la livraison
    $productColumnsSql = '';
    $productValuesSql = '';
    if ($useLivProductCols) {
        $productColumnsSql = ",\n            product_type,\n            product_id,\n            product_qty";
        $productValuesSql = ",\n            :product_type,\n            :product_id,\n            :product_qty";
    }
    $sql = "
        INSERT INTO livraisons (
            id_client,
            id_livreur,
            reference,
            adresse_livraison,
            objet,
            date_prevue,
            commentaire,
            statut{$productColumnsSql}
        ) VALUES (
            :id_client,
            :id_livreur,
            :reference,
            :adresse_livraison,
            :objet,
            :date_prevue,
            :commentaire,
            'planifiee'{$productValuesSql}
        )
    ";
    
    $stmt = $pdo->prepare($sql);
    $params = [
        ':id_client' => $idClient,
        ':id_livreur' => $idLivreur,
        ':reference' => $reference,
        ':adresse_livraison' => $adresseLivraison,
        ':objet' => $objetFinal,
        ':date_prevue' => $datePrevue,
        ':commentaire' => empty($commentaire) ? null : $commentaire,
    ];
    if ($useLivProductCols) {
        $params[':product_type'] = ($productType && $productId > 0 && in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) ? $productType : null;
        $params[':product_id'] = ($productType && $productId > 0 && in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) ? $productId : null;
        $params[':product_qty'] = ($productType && $productId > 0 && in_array($productType, ['papier', 'toner', 'lcd', 'pc'], true)) ? $productQty : null;
    }
    $stmt->execute($params);
    
    $livraisonId = (int)$pdo->lastInsertId();
    
    $pdo->commit();
    
    // Enregistrer dans l'historique
    try {
        $details = sprintf(
            'Livraison créée: %s pour client %s (ID %d), livreur %s %s (ID %d), date prévue: %s', 
            $reference,
            $client['raison_sociale'],
            $idClient, 
            $livreur['prenom'],
            $livreur['nom'],
            $idLivreur, 
            $datePrevue
        );
        if ($productType && $productId > 0) {
            $details .= ', produit: ' . $stockLabel . ' (quantité: ' . $productQty . ')';
        }
        logAction(
            $pdo,
            'livraison',
            'creation',
            'Livraison planifiee — ' . (string)$client['raison_sociale'],
            'Statut: planifiee',
            $livraisonId,
            'livraison.php'
        );
    } catch (Throwable $e) {
        error_log('dashboard_create_delivery.php log error: ' . $e->getMessage());
    }
    
    jsonResponse([
        'ok'           => true,
        'livraison_id' => $livraisonId,
        'message'      => 'Livraison créée avec succès' . ($productType && $productId > 0 ? ' et stock déduit' : '')
    ]);
    
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
