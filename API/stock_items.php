<?php
declare(strict_types=1);

// [Fonctionnalité Stock] Liste + création/mise à jour des articles stock
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $q = trim((string)($_GET['q'] ?? ''));
    $categorie = trim((string)($_GET['categorie'] ?? ''));
    $actif = isset($_GET['actif']) ? (int)$_GET['actif'] : null;

    $where = [];
    $params = [];

    if ($q !== '') {
        $where[] = '(s.reference LIKE :q OR s.designation LIKE :q OR COALESCE(s.marque, \'\') LIKE :q OR COALESCE(s.modele_compatible, \'\') LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }
    if ($categorie !== '') {
        $where[] = 's.categorie = :categorie';
        $params[':categorie'] = $categorie;
    }
    if ($actif !== null && ($actif === 0 || $actif === 1)) {
        $where[] = 's.actif = :actif';
        $params[':actif'] = $actif;
    }

    $sql = "
        SELECT s.*
        FROM stock s
        " . (!empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '') . "
        ORDER BY s.designation ASC, s.id DESC
        LIMIT 1000
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    jsonResponse(['ok' => true, 'items' => $items]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$allowedCategories = ['toner_noir','toner_cyan','toner_magenta','toner_jaune','papier','piece_detachee','consommable','autre'];

$id = (int)($data['id'] ?? 0);
$reference = trim((string)($data['reference'] ?? ''));
$designation = trim((string)($data['designation'] ?? ''));
$categorie = trim((string)($data['categorie'] ?? ''));
$marque = trim((string)($data['marque'] ?? ''));
$modeleCompatible = trim((string)($data['modele_compatible'] ?? ''));
$quantite = (int)($data['quantite'] ?? 0);
$quantiteMin = (int)($data['quantite_min'] ?? 5);
$prixUnitaire = (float)($data['prix_unitaire_ht'] ?? 0);
$emplacement = trim((string)($data['emplacement'] ?? ''));
$actif = (int)($data['actif'] ?? 1);

if ($reference === '' || $designation === '' || !in_array($categorie, $allowedCategories, true)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}
if ($quantite < 0 || $quantiteMin < 0 || $prixUnitaire < 0) {
    jsonResponse(['ok' => false, 'error' => 'Quantités/prix invalides'], 400);
}
$actif = $actif === 1 ? 1 : 0;

try {
    if ($id > 0) {
        $sql = "
            UPDATE stock
            SET reference = :reference,
                designation = :designation,
                categorie = :categorie,
                marque = :marque,
                modele_compatible = :modele_compatible,
                quantite = :quantite,
                quantite_min = :quantite_min,
                prix_unitaire_ht = :prix_unitaire_ht,
                emplacement = :emplacement,
                actif = :actif,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id' => $id,
            ':reference' => $reference,
            ':designation' => $designation,
            ':categorie' => $categorie,
            ':marque' => $marque !== '' ? $marque : null,
            ':modele_compatible' => $modeleCompatible !== '' ? $modeleCompatible : null,
            ':quantite' => $quantite,
            ':quantite_min' => $quantiteMin,
            ':prix_unitaire_ht' => $prixUnitaire,
            ':emplacement' => $emplacement !== '' ? $emplacement : null,
            ':actif' => $actif,
        ]);
        enregistrerAction($pdo, currentUserId(), 'stock_article_modifie', "Article stock #{$id} modifié ({$reference})");
        jsonResponse(['ok' => true, 'id' => $id, 'updated' => true]);
    }

    $sql = "
        INSERT INTO stock (
            reference, designation, categorie, marque, modele_compatible, quantite, quantite_min,
            prix_unitaire_ht, emplacement, actif
        ) VALUES (
            :reference, :designation, :categorie, :marque, :modele_compatible, :quantite, :quantite_min,
            :prix_unitaire_ht, :emplacement, :actif
        )
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':reference' => $reference,
        ':designation' => $designation,
        ':categorie' => $categorie,
        ':marque' => $marque !== '' ? $marque : null,
        ':modele_compatible' => $modeleCompatible !== '' ? $modeleCompatible : null,
        ':quantite' => $quantite,
        ':quantite_min' => $quantiteMin,
        ':prix_unitaire_ht' => $prixUnitaire,
        ':emplacement' => $emplacement !== '' ? $emplacement : null,
        ':actif' => $actif,
    ]);
    $newId = (int)$pdo->lastInsertId();

    enregistrerAction($pdo, currentUserId(), 'stock_article_cree', "Article stock #{$newId} créé ({$reference})");
    jsonResponse(['ok' => true, 'id' => $newId, 'created' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonResponse(['ok' => false, 'error' => 'Référence déjà existante'], 409);
    }
    throw $e;
}

