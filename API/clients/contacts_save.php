<?php
declare(strict_types=1);

// [Fonctionnalité F]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

$idClient = (int)($_POST['id_client'] ?? 0);
$nom = trim((string)($_POST['nom'] ?? ''));
$prenom = trim((string)($_POST['prenom'] ?? ''));
$poste = trim((string)($_POST['poste'] ?? ''));
$telephone = trim((string)($_POST['telephone'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$estPrincipal = (int)($_POST['est_principal'] ?? 0) === 1 ? 1 : 0;

if ($idClient <= 0 || $nom === '' || $prenom === '') {
    jsonResponse(['success' => false, 'error' => 'Paramètres invalides'], 400);
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['success' => false, 'error' => 'Email invalide'], 400);
}

try {
    $pdo = getPdo();
    $pdo->beginTransaction();

    if ($estPrincipal === 1) {
        $resetStmt = $pdo->prepare('UPDATE client_contacts SET est_principal = 0 WHERE id_client = ?');
        $resetStmt->execute([$idClient]);
    }

    $insertStmt = $pdo->prepare('
        INSERT INTO client_contacts (id_client, nom, prenom, poste, telephone, email, est_principal)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $insertStmt->execute([
        $idClient,
        $nom,
        $prenom,
        $poste !== '' ? $poste : null,
        $telephone !== '' ? $telephone : null,
        $email !== '' ? $email : null,
        $estPrincipal,
    ]);

    $newId = (int)$pdo->lastInsertId();
    $pdo->commit();
    jsonResponse(['success' => true, 'id' => $newId]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('contacts_save.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
