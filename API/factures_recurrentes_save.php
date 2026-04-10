<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfForApi();

$pdo = getPdoOrFail();
require_once __DIR__ . '/../includes/parametres.php';
if (!isModuleEnabled($pdo, 'factures_recurrentes')) {
    jsonResponse(['ok' => false, 'error' => 'Module désactivé'], 403);
}

if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
if (class_exists(\App\Services\ProductTier::class)
    && !\App\Services\ProductTier::canUseFeature($pdo, 'module_factures_recurrentes')) {
    jsonResponse(['ok' => false, 'error' => 'Fonction non disponible sur cette offre'], 403);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

$id = isset($data['id']) ? (int)$data['id'] : 0;
$idClient = (int)($data['id_client'] ?? 0);
$libelle = trim((string)($data['libelle'] ?? ''));
$descriptionLigne = trim((string)($data['description_ligne'] ?? ''));
$montantHt = (float)($data['montant_ht'] ?? 0);
$tvaPct = (float)($data['tva_pct'] ?? 20);
$typeFacture = (string)($data['type_facture'] ?? 'Service');
$ligneType = (string)($data['ligne_type'] ?? 'Service');
$frequence = (string)($data['frequence'] ?? 'mensuel');
$jourMois = (int)($data['jour_mois'] ?? 1);
$prochaineEcheance = trim((string)($data['prochaine_echeance'] ?? ''));
$actif = isset($data['actif']) ? (bool)$data['actif'] : true;

$validTypes = ['Consommation', 'Achat', 'Service'];
$validLigne = ['N&B', 'Couleur', 'Service', 'Produit'];
$validFreq = ['mensuel', 'trimestriel', 'annuel'];

if ($idClient <= 0 || $libelle === '' || $descriptionLigne === '' || $montantHt <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Champs obligatoires manquants'], 400);
}
if (!in_array($typeFacture, $validTypes, true)) {
    $typeFacture = 'Service';
}
if (!in_array($ligneType, $validLigne, true)) {
    $ligneType = 'Service';
}
if (!in_array($frequence, $validFreq, true)) {
    $frequence = 'mensuel';
}
$jourMois = max(1, min(28, $jourMois));
if ($prochaineEcheance === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $prochaineEcheance)) {
    jsonResponse(['ok' => false, 'error' => 'Date prochaine_echeance invalide (YYYY-MM-DD)'], 400);
}

$st = $pdo->prepare('SELECT id FROM clients WHERE id = ? LIMIT 1');
$st->execute([$idClient]);
if (!$st->fetch()) {
    jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
}

$userId = (int)($_SESSION['user_id'] ?? 0);

try {
    if ($id > 0) {
        $st = $pdo->prepare('SELECT id FROM factures_recurrentes WHERE id = ? LIMIT 1');
        $st->execute([$id]);
        if (!$st->fetch()) {
            jsonResponse(['ok' => false, 'error' => 'Ligne introuvable'], 404);
        }
        $pdo->prepare("
            UPDATE factures_recurrentes SET
                id_client = ?, libelle = ?, description_ligne = ?, montant_ht = ?, tva_pct = ?,
                type_facture = ?, ligne_type = ?, frequence = ?, jour_mois = ?,
                prochaine_echeance = ?, actif = ?, updated_at = NOW()
            WHERE id = ?
        ")->execute([
            $idClient, $libelle, $descriptionLigne, $montantHt, $tvaPct,
            $typeFacture, $ligneType, $frequence, $jourMois,
            $prochaineEcheance, $actif ? 1 : 0, $id,
        ]);
        jsonResponse(['ok' => true, 'id' => $id]);
    }

    $pdo->prepare("
        INSERT INTO factures_recurrentes (
            id_client, libelle, description_ligne, montant_ht, tva_pct,
            type_facture, ligne_type, frequence, jour_mois, prochaine_echeance, actif, created_by
        ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $idClient, $libelle, $descriptionLigne, $montantHt, $tvaPct,
        $typeFacture, $ligneType, $frequence, $jourMois, $prochaineEcheance, $actif ? 1 : 0, $userId,
    ]);
    jsonResponse(['ok' => true, 'id' => (int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
    error_log('factures_recurrentes_save: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur enregistrement'], 500);
}
