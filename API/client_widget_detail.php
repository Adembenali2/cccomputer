<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = getPdoOrFail();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID invalide'], 400);
}

// Client
$stmtClient = $pdo->prepare("
    SELECT id, numero_client, raison_sociale, adresse, code_postal, ville,
           telephone1, telephone2, email, nom_dirigeant, prenom_dirigeant,
           offre, depot_mode, date_creation
    FROM clients WHERE id = ?
");
$stmtClient->execute([$id]);
$client = $stmtClient->fetch(PDO::FETCH_ASSOC);
if (!$client) {
    jsonResponse(['ok' => false, 'error' => 'Client introuvable'], 404);
}

// Livraisons
$stmtLiv = $pdo->prepare("
    SELECT id, reference, objet, adresse_livraison, date_prevue, date_reelle, statut, commentaire
    FROM livraisons
    WHERE id_client = ?
    ORDER BY date_prevue DESC
    LIMIT 10
");
$stmtLiv->execute([$id]);
$livraisons = $stmtLiv->fetchAll(PDO::FETCH_ASSOC);

// SAV
$stmtSav = $pdo->prepare("
    SELECT id, reference, description, date_ouverture, date_intervention_prevue, statut, priorite, type_panne
    FROM sav
    WHERE id_client = ?
    ORDER BY date_ouverture DESC
    LIMIT 10
");
$stmtSav->execute([$id]);
$savList = $stmtSav->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'ok' => true,
    'client' => $client,
    'livraisons' => $livraisons,
    'sav' => $savList,
]);
