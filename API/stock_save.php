<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Secrétaire']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$data = $_POST;
requireCsrfForApi((string)($data['csrf_token'] ?? ''));

$pdo = getPdoOrFail();
$id = (int)($data['id'] ?? 0);
$designation = trim((string)($data['designation'] ?? ''));
$categorie = trim((string)($data['categorie'] ?? ''));
$reference = trim((string)($data['reference'] ?? ''));

if ($designation === '' || $categorie === '') {
    jsonResponse(['success' => false, 'message' => 'Désignation et catégorie requises'], 400);
}

if ($reference === '') {
    $prefixes = [
      'papier'=>'PAP','toner_noir'=>'TON-N','toner_cyan'=>'TON-C',
      'toner_magenta'=>'TON-M','toner_jaune'=>'TON-J','pc'=>'PC',
      'ecran_lcd'=>'LCD','imprimante'=>'IMP'
    ];
    $prefix = $prefixes[$categorie] ?? 'ART';
    $date = date('Ymd');
    $stmt = $pdo->prepare("SELECT reference FROM stock WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1");
    $stmt->execute([$prefix . '-' . $date . '-%']);
    $last = $stmt->fetchColumn();
    $num = $last ? (intval(substr((string)$last, -4)) + 1) : 1;
    $reference = $prefix . '-' . $date . '-' . str_pad((string)$num, 4, '0', STR_PAD_LEFT);
}

$modele = trim((string)($data['modele'] ?? ''));
if ($modele === '') {
    $modele = trim((string)($data['modele_compatible'] ?? ''));
}

$params = [
    ':reference' => $reference,
    ':designation' => $designation,
    ':categorie' => $categorie,
    ':quantite' => (int)($data['quantite'] ?? 0),
    ':quantite_min' => (int)($data['quantite_min'] ?? 5),
    ':unite' => trim((string)($data['unite'] ?? 'unite')) ?: 'unite',
    ':contenance' => ($data['contenance'] ?? '') !== '' ? (int)$data['contenance'] : null,
    ':marque' => trim((string)($data['marque'] ?? '')) ?: null,
    ':modele' => $modele !== '' ? $modele : null,
    ':fournisseur' => trim((string)($data['fournisseur'] ?? '')) ?: null,
    ':prix_unitaire_ht' => (float)($data['prix_unitaire_ht'] ?? 0),
    ':etat' => trim((string)($data['etat'] ?? 'neuf')) ?: 'neuf',
    ':emplacement' => trim((string)($data['emplacement'] ?? '')) ?: null,
    ':date_achat' => trim((string)($data['date_achat'] ?? '')) ?: null,
    ':notes' => trim((string)($data['notes'] ?? '')) ?: null,
    ':numero_serie' => trim((string)($data['numero_serie'] ?? '')) ?: null,
    ':adresse_mac' => trim((string)($data['adresse_mac'] ?? '')) ?: null,
    ':cpu' => trim((string)($data['cpu'] ?? '')) ?: null,
    ':ram' => trim((string)($data['ram'] ?? '')) ?: null,
    ':stockage' => trim((string)($data['stockage'] ?? '')) ?: null,
    ':couleur_toner' => trim((string)($data['couleur_toner'] ?? '')) ?: null,
    ':rendement_pages' => ($data['rendement_pages'] ?? '') !== '' ? (int)$data['rendement_pages'] : null,
    ':taille_ecran' => trim((string)($data['taille_ecran'] ?? '')) ?: null,
    ':resolution' => trim((string)($data['resolution'] ?? '')) ?: null,
    ':grammage' => trim((string)($data['grammage'] ?? '')) ?: null,
    ':format_papier' => trim((string)($data['format_papier'] ?? '')) ?: null,
    ':qr_code' => $reference,
];

if ($id > 0) {
    $sql = "UPDATE stock SET
              reference=:reference, designation=:designation, categorie=:categorie,
              quantite=:quantite, quantite_min=:quantite_min, unite=:unite, contenance=:contenance,
              marque=:marque, modele=:modele, fournisseur=:fournisseur, prix_unitaire_ht=:prix_unitaire_ht,
              etat=:etat, emplacement=:emplacement, date_achat=:date_achat, notes=:notes,
              numero_serie=:numero_serie, adresse_mac=:adresse_mac, cpu=:cpu, ram=:ram, stockage=:stockage,
              couleur_toner=:couleur_toner, rendement_pages=:rendement_pages, taille_ecran=:taille_ecran, resolution=:resolution,
              grammage=:grammage, format_papier=:format_papier, qr_code=:qr_code, updated_at=CURRENT_TIMESTAMP
            WHERE id=:id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params + [':id' => $id]);
    jsonResponse(['success' => true, 'id' => $id, 'reference' => $reference, 'message' => 'Article mis à jour']);
}

$sql = "INSERT INTO stock (
          reference, designation, categorie, quantite, quantite_min, unite, contenance,
          marque, modele, fournisseur, prix_unitaire_ht, etat, emplacement, date_achat, notes,
          numero_serie, adresse_mac, cpu, ram, stockage, couleur_toner, rendement_pages,
          taille_ecran, resolution, grammage, format_papier, qr_code, created_by
        ) VALUES (
          :reference, :designation, :categorie, :quantite, :quantite_min, :unite, :contenance,
          :marque, :modele, :fournisseur, :prix_unitaire_ht, :etat, :emplacement, :date_achat, :notes,
          :numero_serie, :adresse_mac, :cpu, :ram, :stockage, :couleur_toner, :rendement_pages,
          :taille_ecran, :resolution, :grammage, :format_papier, :qr_code, :created_by
        )";
$stmt = $pdo->prepare($sql);
$stmt->execute($params + [':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null]);
$newId = (int)$pdo->lastInsertId();

jsonResponse(['success' => true, 'id' => $newId, 'reference' => $reference, 'message' => 'Article créé']);
