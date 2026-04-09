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
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès refusé'], 403);
}

requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$allowedCategories = ['papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre'];

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
$unite = trim((string)($data['unite'] ?? 'unite'));
$contenance = isset($data['contenance']) && $data['contenance'] !== '' ? (int)$data['contenance'] : null;
$numeroSerie = trim((string)($data['numero_serie'] ?? ''));
$adresseMac = trim((string)($data['adresse_mac'] ?? ''));
$cpu = trim((string)($data['cpu'] ?? ''));
$ram = trim((string)($data['ram'] ?? ''));
$stockage = trim((string)($data['stockage'] ?? ''));
$etat = trim((string)($data['etat'] ?? 'neuf'));
$dateAchat = trim((string)($data['date_achat'] ?? ''));
$fournisseur = trim((string)($data['fournisseur'] ?? ''));
$notes = trim((string)($data['notes'] ?? ''));
$photo = trim((string)($data['photo'] ?? ''));
$tailleEcran = trim((string)($data['taille_ecran'] ?? ''));
$resolution = trim((string)($data['resolution'] ?? ''));
$couleurToner = trim((string)($data['couleur_toner'] ?? ''));
$rendementPages = isset($data['rendement_pages']) && $data['rendement_pages'] !== '' ? (int)$data['rendement_pages'] : null;
$grammage = trim((string)($data['grammage'] ?? ''));
$formatPapier = trim((string)($data['format_papier'] ?? ''));
$compteurInitial = isset($data['compteur_initial']) && $data['compteur_initial'] !== '' ? (int)$data['compteur_initial'] : 0;

if ($reference === '' || $designation === '' || !in_array($categorie, $allowedCategories, true)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}
if ($quantite < 0 || $quantiteMin < 0 || $prixUnitaire < 0) {
    jsonResponse(['ok' => false, 'error' => 'Quantités/prix invalides'], 400);
}
$actif = $actif === 1 ? 1 : 0;
if (!in_array($unite, ['unite', 'carton', 'rame'], true)) {
    $unite = 'unite';
}
if (!in_array($etat, ['neuf', 'bon', 'use', 'hs'], true)) {
    $etat = 'neuf';
}
if ($categorie === 'papier') {
    if ($unite === 'unite') {
        $unite = 'carton';
    }
    if ($contenance === null || $contenance <= 0) {
        $contenance = 2500;
    }
}

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
                unite = :unite,
                contenance = :contenance,
                numero_serie = :numero_serie,
                adresse_mac = :adresse_mac,
                cpu = :cpu,
                ram = :ram,
                stockage = :stockage,
                etat = :etat,
                date_achat = :date_achat,
                fournisseur = :fournisseur,
                notes = :notes,
                taille_ecran = :taille_ecran,
                resolution = :resolution,
                couleur_toner = :couleur_toner,
                rendement_pages = :rendement_pages,
                grammage = :grammage,
                format_papier = :format_papier,
                compteur_initial = :compteur_initial,
                photo = :photo,
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
            ':unite' => $unite,
            ':contenance' => $contenance,
            ':numero_serie' => $numeroSerie !== '' ? $numeroSerie : null,
            ':adresse_mac' => $adresseMac !== '' ? $adresseMac : null,
            ':cpu' => $cpu !== '' ? $cpu : null,
            ':ram' => $ram !== '' ? $ram : null,
            ':stockage' => $stockage !== '' ? $stockage : null,
            ':etat' => $etat,
            ':date_achat' => $dateAchat !== '' ? $dateAchat : null,
            ':fournisseur' => $fournisseur !== '' ? $fournisseur : null,
            ':notes' => $notes !== '' ? $notes : null,
            ':taille_ecran' => $tailleEcran !== '' ? $tailleEcran : null,
            ':resolution' => $resolution !== '' ? $resolution : null,
            ':couleur_toner' => $couleurToner !== '' ? $couleurToner : null,
            ':rendement_pages' => $rendementPages,
            ':grammage' => $grammage !== '' ? $grammage : null,
            ':format_papier' => $formatPapier !== '' ? $formatPapier : null,
            ':compteur_initial' => $compteurInitial,
            ':photo' => $photo !== '' ? $photo : null,
        ]);
        enregistrerAction($pdo, currentUserId(), 'stock_article_modifie', "Article stock #{$id} modifié ({$reference})");
        jsonResponse(['ok' => true, 'id' => $id, 'updated' => true]);
    }

    $sql = "
        INSERT INTO stock (
            reference, designation, categorie, marque, modele_compatible, quantite, quantite_min,
            prix_unitaire_ht, emplacement, actif, unite, contenance,
            numero_serie, adresse_mac, cpu, ram, stockage, etat, date_achat, fournisseur, notes, photo
            , taille_ecran, resolution, couleur_toner, rendement_pages, grammage, format_papier, compteur_initial
        ) VALUES (
            :reference, :designation, :categorie, :marque, :modele_compatible, :quantite, :quantite_min,
            :prix_unitaire_ht, :emplacement, :actif, :unite, :contenance,
            :numero_serie, :adresse_mac, :cpu, :ram, :stockage, :etat, :date_achat, :fournisseur, :notes, :photo,
            :taille_ecran, :resolution, :couleur_toner, :rendement_pages, :grammage, :format_papier, :compteur_initial
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
        ':unite' => $unite,
        ':contenance' => $contenance,
        ':numero_serie' => $numeroSerie !== '' ? $numeroSerie : null,
        ':adresse_mac' => $adresseMac !== '' ? $adresseMac : null,
        ':cpu' => $cpu !== '' ? $cpu : null,
        ':ram' => $ram !== '' ? $ram : null,
        ':stockage' => $stockage !== '' ? $stockage : null,
        ':etat' => $etat,
        ':date_achat' => $dateAchat !== '' ? $dateAchat : null,
        ':fournisseur' => $fournisseur !== '' ? $fournisseur : null,
        ':notes' => $notes !== '' ? $notes : null,
        ':taille_ecran' => $tailleEcran !== '' ? $tailleEcran : null,
        ':resolution' => $resolution !== '' ? $resolution : null,
        ':couleur_toner' => $couleurToner !== '' ? $couleurToner : null,
        ':rendement_pages' => $rendementPages,
        ':grammage' => $grammage !== '' ? $grammage : null,
        ':format_papier' => $formatPapier !== '' ? $formatPapier : null,
        ':compteur_initial' => $compteurInitial,
        ':photo' => $photo !== '' ? $photo : null,
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

