<?php
/**
 * API recherche globale — clients, factures, SAV, livraisons
 * GET ?q=terme
 * Retourne { ok: true, results: { clients: [], factures: [], sav: [], livraisons: [] } }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$q = trim((string)($_GET['q'] ?? ''));
$q = mb_substr($q, 0, 80);

if (mb_strlen($q) < 2) {
    jsonResponse(['ok' => true, 'results' => ['clients' => [], 'factures' => [], 'sav' => [], 'livraisons' => []]]);
}

$pdo = getPdoOrFail();
$like = '%' . $q . '%';
$results = ['clients' => [], 'factures' => [], 'sav' => [], 'livraisons' => []];

$colExists = static function (PDO $pdoConn, string $table, string $column): bool {
    $stmt = $pdoConn->prepare('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ');
    $stmt->execute([$table, $column]);

    return ((int) $stmt->fetchColumn()) > 0;
};

$clientsStatutSql = $colExists($pdo, 'clients', 'statut') ? " AND statut = 'actif'" : '';

// CLIENTS
try {
    $stmt = $pdo->prepare("
        SELECT id, raison_sociale, numero_client, ville
        FROM clients
        WHERE (raison_sociale LIKE :like OR numero_client LIKE :like2)
          {$clientsStatutSql}
        ORDER BY raison_sociale ASC LIMIT 4
    ");
    $stmt->execute([':like' => $like, ':like2' => $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results['clients'][] = [
            'id' => (int) $r['id'],
            'label' => $r['raison_sociale'] . ($r['numero_client'] ? ' · ' . $r['numero_client'] : ''),
            'sub' => $r['ville'] ?? '',
            'url' => '/public/client_fiche.php?id=' . (int) $r['id'],
        ];
    }
} catch (Throwable $e) {
    error_log('global_search clients: ' . $e->getMessage());
}

// FACTURES
try {
    $stmt = $pdo->prepare("
        SELECT f.id, f.numero, f.statut, f.montant_ttc, c.raison_sociale
        FROM factures f
        LEFT JOIN clients c ON c.id = f.id_client
        WHERE (f.numero LIKE :like OR c.raison_sociale LIKE :like2)
          AND f.statut NOT IN ('annulee','brouillon')
        ORDER BY f.date_facture DESC LIMIT 4
    ");
    $stmt->execute([':like' => $like, ':like2' => $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results['factures'][] = [
            'id' => (int) $r['id'],
            'label' => $r['numero'] . ' · ' . ($r['raison_sociale'] ?? ''),
            'sub' => number_format((float) $r['montant_ttc'], 2, ',', ' ') . ' € — ' . $r['statut'],
            'url' => '/public/factures.php?q=' . rawurlencode((string) $r['numero']),
        ];
    }
} catch (Throwable $e) {
    error_log('global_search factures: ' . $e->getMessage());
}

// SAV
try {
    $stmt = $pdo->prepare("
        SELECT s.id, s.reference, s.statut, s.priorite, c.raison_sociale
        FROM sav s
        LEFT JOIN clients c ON c.id = s.id_client
        WHERE (s.reference LIKE :like OR s.description LIKE :like2 OR c.raison_sociale LIKE :like3)
          AND s.statut NOT IN ('resolu','annule')
        ORDER BY s.date_ouverture DESC LIMIT 4
    ");
    $stmt->execute([':like' => $like, ':like2' => $like, ':like3' => $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $results['sav'][] = [
            'id' => (int) $r['id'],
            'label' => $r['reference'] . ' · ' . ($r['raison_sociale'] ?? ''),
            'sub' => $r['statut'] . ' — ' . ($r['priorite'] ?? ''),
            'url' => '/public/sav.php?ref=' . rawurlencode((string) $r['reference']),
        ];
    }
} catch (Throwable $e) {
    error_log('global_search sav: ' . $e->getMessage());
}

// LIVRAISONS
try {
    $stmt = $pdo->prepare("
        SELECT l.id, l.reference, l.statut, l.objet, c.raison_sociale
        FROM livraisons l
        LEFT JOIN clients c ON c.id = l.id_client
        WHERE (l.reference LIKE :like OR l.objet LIKE :like2 OR c.raison_sociale LIKE :like3)
          AND l.statut NOT IN ('livree','annulee')
        ORDER BY l.date_prevue DESC LIMIT 4
    ");
    $stmt->execute([':like' => $like, ':like2' => $like, ':like3' => $like]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $objet = (string) ($r['objet'] ?? '');
        $results['livraisons'][] = [
            'id' => (int) $r['id'],
            'label' => $r['reference'] . ' · ' . ($r['raison_sociale'] ?? ''),
            'sub' => $r['statut'] . ($objet !== '' ? ' — ' . mb_substr($objet, 0, 40) : ''),
            'url' => '/public/livraison.php?ref=' . rawurlencode((string) $r['reference']),
        ];
    }
} catch (Throwable $e) {
    error_log('global_search livraisons: ' . $e->getMessage());
}

jsonResponse(['ok' => true, 'results' => $results]);
