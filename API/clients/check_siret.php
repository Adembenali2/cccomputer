<?php
declare(strict_types=1);

// [Fonctionnalité B]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['exists' => false, 'error' => 'Méthode non autorisée'], 405);
}

$siret = trim((string)($_GET['siret'] ?? ''));
$excludeId = (int)($_GET['exclude_id'] ?? 0);
if ($siret === '') {
    jsonResponse(['exists' => false], 200);
}

try {
    $pdo = getPdo();
    if ($excludeId > 0) {
        $stmt = $pdo->prepare('SELECT id, raison_sociale, numero_client FROM clients WHERE siret = ? AND id != ? LIMIT 1');
        $stmt->execute([$siret, $excludeId]);
    } else {
        $stmt = $pdo->prepare('SELECT id, raison_sociale, numero_client FROM clients WHERE siret = ? LIMIT 1');
        $stmt->execute([$siret]);
    }
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($client) {
        jsonResponse(['exists' => true, 'client' => $client]);
    }
    jsonResponse(['exists' => false]);
} catch (Throwable $e) {
    error_log('check_siret.php: ' . $e->getMessage());
    jsonResponse(['exists' => false, 'error' => 'Erreur serveur'], 500);
}
