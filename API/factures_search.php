<?php
/**
 * API recherche factures par nom client (raison_sociale) uniquement
 * GET ?q=terme (1-50 caractères)
 * Retourne : { ok: true, results: [{ id, numero, client_nom, date_emission, client_email, email_envoye, date_envoi_email }] }
 * Uniquement les factures avec PDF généré, des clients dont raison_sociale correspond à q.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$query = trim($_GET['q'] ?? '');
$query = mb_substr($query, 0, 50);

if ($query === '') {
    jsonResponse(['ok' => true, 'results' => []]);
}

try {
    $pdo = getPdo();
    $limit = min((int)($_GET['limit'] ?? 15), 25);
    $clientPrefix = $query . '%';

    $stmt = $pdo->prepare("
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.email_envoye,
            f.date_envoi_email,
            c.raison_sociale as client_nom,
            c.email as client_email
        FROM factures f
        JOIN clients c ON c.id = f.id_client
        WHERE f.pdf_path IS NOT NULL
          AND c.raison_sociale LIKE :clientPrefix
        ORDER BY c.raison_sociale ASC, f.date_facture DESC
        LIMIT :limit
    ");
    $stmt->bindValue(':clientPrefix', $clientPrefix, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($rows as $r) {
        $dateEmission = $r['date_facture'] ?? '';
        if ($dateEmission) {
            $dateEmission = date('Y-m-d', strtotime($dateEmission));
        }
        $results[] = [
            'id' => (int)$r['id'],
            'numero' => $r['numero'] ?? '',
            'client_nom' => $r['client_nom'] ?? 'Client inconnu',
            'date_emission' => $dateEmission,
            'client_email' => $r['client_email'] ?? '',
            'email_envoye' => (int)($r['email_envoye'] ?? 0),
            'date_envoi_email' => $r['date_envoi_email'] ?? ''
        ];
    }

    jsonResponse(['ok' => true, 'results' => $results]);
} catch (PDOException $e) {
    error_log('factures_search.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_search.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
