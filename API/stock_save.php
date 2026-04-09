<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès refusé'], 403);
}

$csrf = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
requireCsrfForApi($csrf);

$pdo = getPdoOrFail();

$fields = [
    'id','reference','designation','categorie','marque','modele_compatible','quantite','quantite_min','prix_unitaire_ht',
    'emplacement','actif','unite','contenance','numero_serie','adresse_mac','cpu','ram','stockage',
    'etat','date_achat','fournisseur','notes'
];
$data = [];
foreach ($fields as $f) {
    $data[$f] = $_POST[$f] ?? null;
}

$id = (int)($data['id'] ?? 0);
$reference = trim((string)($data['reference'] ?? ''));
$designation = trim((string)($data['designation'] ?? ''));
$categorie = trim((string)($data['categorie'] ?? 'autre'));

$allowedCategories = ['papier','toner_noir','toner_cyan','toner_magenta','toner_jaune','pc','ecran_lcd','imprimante','piece_detachee','consommable','autre'];
if ($designation === '' || !in_array($categorie, $allowedCategories, true)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}
if ($reference === '') {
    $reference = strtoupper(substr($categorie, 0, 3)) . '-' . date('Ymd-His');
}

$photoPath = trim((string)($_POST['photo_existing'] ?? ''));
if (!empty($_FILES['photo']) && (int)$_FILES['photo']['error'] === UPLOAD_ERR_OK) {
    $tmp = (string)$_FILES['photo']['tmp_name'];
    $size = (int)($_FILES['photo']['size'] ?? 0);
    if ($size > 2 * 1024 * 1024) {
        jsonResponse(['ok' => false, 'error' => 'Photo trop volumineuse (max 2MB)'], 400);
    }
    $mime = (string)(mime_content_type($tmp) ?: '');
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        jsonResponse(['ok' => false, 'error' => 'Type image non autorisé (jpeg/png)'], 400);
    }
    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    $hash = md5_file($tmp . microtime(true));
    $dir = __DIR__ . '/../uploads/stock';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = $hash . '.' . $ext;
    $target = $dir . '/' . $filename;
    if (!move_uploaded_file($tmp, $target)) {
        jsonResponse(['ok' => false, 'error' => 'Échec upload photo'], 500);
    }
    $photoPath = '/uploads/stock/' . $filename;
}

$payload = [
    ':reference' => $reference,
    ':designation' => $designation,
    ':categorie' => $categorie,
    ':marque' => (($v = trim((string)$data['marque'])) !== '' ? $v : null),
    ':modele_compatible' => (($v = trim((string)$data['modele_compatible'])) !== '' ? $v : null),
    ':quantite' => max(0, (int)($data['quantite'] ?? 0)),
    ':quantite_min' => max(0, (int)($data['quantite_min'] ?? 5)),
    ':prix_unitaire_ht' => max(0.0, (float)($data['prix_unitaire_ht'] ?? 0)),
    ':emplacement' => (($v = trim((string)$data['emplacement'])) !== '' ? $v : null),
    ':actif' => ((int)($data['actif'] ?? 1) === 1 ? 1 : 0),
    ':unite' => in_array((string)$data['unite'], ['unite','carton','rame'], true) ? (string)$data['unite'] : 'unite',
    ':contenance' => ($data['contenance'] !== null && $data['contenance'] !== '' ? (int)$data['contenance'] : null),
    ':numero_serie' => (($v = trim((string)$data['numero_serie'])) !== '' ? $v : null),
    ':adresse_mac' => (($v = trim((string)$data['adresse_mac'])) !== '' ? $v : null),
    ':cpu' => (($v = trim((string)$data['cpu'])) !== '' ? $v : null),
    ':ram' => (($v = trim((string)$data['ram'])) !== '' ? $v : null),
    ':stockage' => (($v = trim((string)$data['stockage'])) !== '' ? $v : null),
    ':etat' => in_array((string)$data['etat'], ['neuf','bon','use','hs'], true) ? (string)$data['etat'] : 'neuf',
    ':date_achat' => (($v = trim((string)$data['date_achat'])) !== '' ? $v : null),
    ':fournisseur' => (($v = trim((string)$data['fournisseur'])) !== '' ? $v : null),
    ':notes' => (($v = trim((string)$data['notes'])) !== '' ? $v : null),
    ':photo' => ($photoPath !== '' ? $photoPath : null),
];

try {
    if ($id > 0) {
        $payload[':id'] = $id;
        $sql = "UPDATE stock SET
            reference=:reference, designation=:designation, categorie=:categorie, marque=:marque, modele_compatible=:modele_compatible,
            quantite=:quantite, quantite_min=:quantite_min, prix_unitaire_ht=:prix_unitaire_ht, emplacement=:emplacement, actif=:actif,
            unite=:unite, contenance=:contenance, numero_serie=:numero_serie, adresse_mac=:adresse_mac, cpu=:cpu, ram=:ram, stockage=:stockage,
            etat=:etat, date_achat=:date_achat, fournisseur=:fournisseur, notes=:notes, photo=:photo, updated_at=CURRENT_TIMESTAMP
            WHERE id=:id";
        $pdo->prepare($sql)->execute($payload);
        enregistrerAction($pdo, currentUserId(), 'stock_modifie', "Stock #{$id} modifié");
        jsonResponse(['ok' => true, 'id' => $id, 'updated' => true]);
    }

    $sql = "INSERT INTO stock (
        reference,designation,categorie,marque,modele_compatible,quantite,quantite_min,prix_unitaire_ht,emplacement,actif,
        unite,contenance,numero_serie,adresse_mac,cpu,ram,stockage,etat,date_achat,fournisseur,notes,photo
    ) VALUES (
        :reference,:designation,:categorie,:marque,:modele_compatible,:quantite,:quantite_min,:prix_unitaire_ht,:emplacement,:actif,
        :unite,:contenance,:numero_serie,:adresse_mac,:cpu,:ram,:stockage,:etat,:date_achat,:fournisseur,:notes,:photo
    )";
    $pdo->prepare($sql)->execute($payload);
    $newId = (int)$pdo->lastInsertId();
    enregistrerAction($pdo, currentUserId(), 'stock_cree', "Stock #{$newId} créé");
    jsonResponse(['ok' => true, 'id' => $newId, 'created' => true]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        jsonResponse(['ok' => false, 'error' => 'Référence déjà existante'], 409);
    }
    throw $e;
}

