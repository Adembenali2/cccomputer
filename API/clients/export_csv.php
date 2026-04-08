<?php
declare(strict_types=1);

// [Fonctionnalité C]
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$statut = (string)($_GET['statut'] ?? 'actif');
if (!in_array($statut, ['actif', 'archive', 'tous'], true)) {
    $statut = 'actif';
}

try {
    $pdo = getPdo();
    $sql = "
      SELECT c.numero_client, c.raison_sociale, c.adresse, c.code_postal, c.ville,
             c.siret, c.numero_tva, c.nom_dirigeant, c.prenom_dirigeant,
             c.telephone1, c.telephone2, c.email, c.offre, c.depot_mode,
             COALESCE(c.statut, 'actif') AS statut, c.date_creation,
             COUNT(DISTINCT pc.id) as nb_machines
      FROM clients c
      LEFT JOIN photocopieurs_clients pc ON pc.id_client = c.id
      WHERE (:statut = 'tous' OR COALESCE(c.statut, 'actif') = :statut)
      GROUP BY c.id
      ORDER BY c.raison_sociale ASC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':statut' => $statut]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    enregistrerAction($pdo, currentUserId(), 'clients_export_csv', 'Export CSV clients statut=' . $statut);

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'clients_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo chr(0xEF) . chr(0xBB) . chr(0xBF);
    $out = fopen('php://output', 'wb');
    fputcsv($out, [
        'N° Client', 'Raison sociale', 'Adresse', 'Code postal', 'Ville',
        'SIRET', 'N° TVA', 'Nom dirigeant', 'Prénom dirigeant', 'Téléphone 1', 'Téléphone 2',
        'Email', 'Offre', 'Mode paiement', 'Statut', 'Date création', 'Nb machines'
    ], ';');

    foreach ($rows as $row) {
        fputcsv($out, [
            (string)($row['numero_client'] ?? ''),
            (string)($row['raison_sociale'] ?? ''),
            (string)($row['adresse'] ?? ''),
            (string)($row['code_postal'] ?? ''),
            (string)($row['ville'] ?? ''),
            (string)($row['siret'] ?? ''),
            (string)($row['numero_tva'] ?? ''),
            (string)($row['nom_dirigeant'] ?? ''),
            (string)($row['prenom_dirigeant'] ?? ''),
            (string)($row['telephone1'] ?? ''),
            (string)($row['telephone2'] ?? ''),
            (string)($row['email'] ?? ''),
            (string)($row['offre'] ?? ''),
            (string)($row['depot_mode'] ?? ''),
            (string)($row['statut'] ?? 'actif'),
            (string)($row['date_creation'] ?? ''),
            (string)($row['nb_machines'] ?? '0'),
        ], ';');
    }
    fclose($out);
    exit;
} catch (Throwable $e) {
    error_log('export_csv.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
