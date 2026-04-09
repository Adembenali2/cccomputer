<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Secrétaire']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input') ?: '{}';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

requireCsrfForApi((string)($data['csrf_token'] ?? ''));

$pdo = getPdoOrFail();

$id = (int)($data['id'] ?? 0);
$designation = trim((string)($data['designation'] ?? ''));
$categorie = trim((string)($data['categorie'] ?? 'autre'));
$reference = trim((string)($data['reference'] ?? ''));

if ($designation === '') {
    jsonResponse(['success' => false, 'message' => 'Désignation requise'], 400);
}

if ($reference === '') {
    $prefixes = [
      'papier'=>'PAP','toner_noir'=>'TON-N','toner_cyan'=>'TON-C',
      'toner_magenta'=>'TON-M','toner_jaune'=>'TON-J',
      'pc'=>'PC','ecran_lcd'=>'LCD','imprimante'=>'IMP',
      'piece_detachee'=>'PDR','consommable'=>'CSO','autre'=>'ART'
    ];
    $prefix = $prefixes[$categorie] ?? 'ART';
    $date = date('Ymd');
    $stmt = $pdo->prepare("SELECT reference FROM stock WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1");
    $stmt->execute([$prefix . '-' . $date . '-%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (intval(substr((string)$last, -4)) + 1) : 1;
    $reference = $prefix . '-' . $date . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
}

$payload = [
    ':reference' => $reference,
    ':designation' => $designation,
    ':categorie' => $categorie,
    ':marque' => trim((string)($data['marque'] ?? '')) ?: null,
    ':quantite' => (int)($data['quantite'] ?? 0),
    ':quantite_min' => (int)($data['quantite_min'] ?? 5),
    ':prix_unitaire_ht' => (float)($data['prix_unitaire_ht'] ?? 0),
    ':unite' => trim((string)($data['unite'] ?? 'unite')) ?: 'unite',
    ':contenance' => ($data['contenance'] ?? '') !== '' ? (int)$data['contenance'] : null,
    ':etat' => trim((string)($data['etat'] ?? 'neuf')) ?: 'neuf',
    ':emplacement' => trim((string)($data['emplacement'] ?? '')) ?: null,
    ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
    ':numero_serie' => trim((string)($data['numero_serie'] ?? '')) ?: null,
    ':adresse_mac' => trim((string)($data['adresse_mac'] ?? '')) ?: null,
    ':cpu' => trim((string)($data['cpu'] ?? '')) ?: null,
    ':ram' => trim((string)($data['ram'] ?? '')) ?: null,
    ':stockage' => trim((string)($data['stockage'] ?? '')) ?: null,
    ':modele_compatible' => trim((string)($data['modele_compatible'] ?? '')) ?: null,
    ':couleur_toner' => trim((string)($data['couleur_toner'] ?? '')) ?: null,
    ':rendement_pages' => ($data['rendement_pages'] ?? '') !== '' ? (int)$data['rendement_pages'] : null,
    ':taille_ecran' => trim((string)($data['taille_ecran'] ?? '')) ?: null,
    ':resolution' => trim((string)($data['resolution'] ?? '')) ?: null,
    ':grammage' => trim((string)($data['grammage'] ?? '')) ?: null,
];

if ($id > 0) {
    $sql = "UPDATE stock SET
        reference=:reference, designation=:designation, categorie=:categorie, marque=:marque,
        quantite=:quantite, quantite_min=:quantite_min, prix_unitaire_ht=:prix_unitaire_ht,
        unite=:unite, contenance=:contenance, etat=:etat, emplacement=:emplacement, notes=:notes,
        numero_serie=:numero_serie, adresse_mac=:adresse_mac, cpu=:cpu, ram=:ram, stockage=:stockage,
        modele_compatible=:modele_compatible, couleur_toner=:couleur_toner, rendement_pages=:rendement_pages,
        taille_ecran=:taille_ecran, resolution=:resolution, grammage=:grammage,
        updated_at=CURRENT_TIMESTAMP
        WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($payload + [':id' => $id]);
    jsonResponse(['success' => true, 'message' => 'Article modifié', 'id' => $id]);
}

$sql = "INSERT INTO stock
    (reference,designation,categorie,marque,quantite,quantite_min,prix_unitaire_ht,unite,contenance,etat,emplacement,notes,numero_serie,adresse_mac,cpu,ram,stockage,modele_compatible,couleur_toner,rendement_pages,taille_ecran,resolution,grammage)
    VALUES
    (:reference,:designation,:categorie,:marque,:quantite,:quantite_min,:prix_unitaire_ht,:unite,:contenance,:etat,:emplacement,:notes,:numero_serie,:adresse_mac,:cpu,:ram,:stockage,:modele_compatible,:couleur_toner,:rendement_pages,:taille_ecran,:resolution,:grammage)";
$stmt = $pdo->prepare($sql);
$stmt->execute($payload);
$newId = (int)$pdo->lastInsertId();
jsonResponse(['success' => true, 'message' => 'Article créé', 'id' => $newId]);
