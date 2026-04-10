<?php
/**
 * API recherche clients par préfixe (autocomplétion)
 * GET ?q=préfixe
 * Retourne : { ok: true, results: [{id, nom}] }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$query = trim($_GET['q'] ?? '');
$query = mb_substr($query, 0, 80);

if ($query === '') {
    jsonResponse(['ok' => true, 'results' => []]);
}

try {
    $pdo = getPdo();
    $prefix = $query . '%';
    $limit = min((int)($_GET['limit'] ?? 15), 25);

    $stmt = $pdo->prepare("
        SELECT id, numero_client, raison_sociale
        FROM clients
        WHERE raison_sociale LIKE :prefix
        ORDER BY raison_sociale ASC
        LIMIT :limit
    ");
    $stmt->bindValue(':prefix', $prefix, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id' => (int)$r['id'],
            'nom' => $r['raison_sociale'] . ($r['numero_client'] ? ' (' . $r['numero_client'] . ')' : '')
        ];
    }

    jsonResponse(['ok' => true, 'results' => $results]);
} catch (PDOException $e) {
    error_log('clients_search.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('clients_search.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
