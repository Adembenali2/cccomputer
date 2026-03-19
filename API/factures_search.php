<?php
/**
 * API recherche factures - multi-critères
 * GET ?q=terme (recherche globale)
 *     &nom=... (raison_sociale client)
 *     &prenom=... (prenom_dirigeant)
 *     &email=... (email client)
 *     &date=... (date facture)
 *     &numero_facture=... (numéro facture)
 *     &statut=... (brouillon|envoyee|payee|en_retard|annulee)
 *     &limit=...
 * Retourne : { ok: true, results: [{ id, numero, client_nom, client_prenom, client_email, date_emission, montant_ttc, statut, email_envoye, date_envoi_email }] }
 * Si q vide et aucun filtre : retourne les dernières factures
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$q = trim($_GET['q'] ?? '');
$q = mb_substr($q, 0, 100);
$nom = trim($_GET['nom'] ?? '');
$nom = mb_substr($nom, 0, 100);
$prenom = trim($_GET['prenom'] ?? '');
$prenom = mb_substr($prenom, 0, 100);
$email = trim($_GET['email'] ?? '');
$email = mb_substr($email, 0, 150);
$date = trim($_GET['date'] ?? '');
$numeroFacture = trim($_GET['numero_facture'] ?? '');
$numeroFacture = mb_substr($numeroFacture, 0, 50);
$statut = trim($_GET['statut'] ?? '');
$limit = min(max((int)($_GET['limit'] ?? 25), 5), 100);

try {
    $pdo = getPdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $conditions = [];
    $params = [];

    // Toujours limiter aux factures avec PDF
    $conditions[] = 'f.pdf_path IS NOT NULL';

    // Recherche globale q (nom, prénom, email, numéro, date)
    if ($q !== '') {
        $qLike = '%' . $q . '%';
        $conditions[] = "(
            c.raison_sociale LIKE :q_raison
            OR c.nom_dirigeant LIKE :q_nom
            OR c.prenom_dirigeant LIKE :q_prenom
            OR c.email LIKE :q_email
            OR f.numero LIKE :q_numero
            OR DATE_FORMAT(f.date_facture, '%Y-%m-%d') LIKE :q_date
            OR DATE_FORMAT(f.date_facture, '%d/%m/%Y') LIKE :q_date2
        )";
        $params[':q_raison'] = $qLike;
        $params[':q_nom'] = $qLike;
        $params[':q_prenom'] = $qLike;
        $params[':q_email'] = $qLike;
        $params[':q_numero'] = $qLike;
        $params[':q_date'] = $qLike;
        $params[':q_date2'] = $qLike;
    }

    // Filtres spécifiques
    if ($nom !== '') {
        $conditions[] = 'c.raison_sociale LIKE :nom';
        $params[':nom'] = '%' . $nom . '%';
    }
    if ($prenom !== '') {
        $conditions[] = 'c.prenom_dirigeant LIKE :prenom';
        $params[':prenom'] = '%' . $prenom . '%';
    }
    if ($email !== '') {
        $conditions[] = 'c.email LIKE :email';
        $params[':email'] = '%' . $email . '%';
    }
    if ($date !== '') {
        $conditions[] = 'DATE(f.date_facture) = :date';
        $params[':date'] = $date;
    }
    if ($numeroFacture !== '') {
        $conditions[] = 'f.numero LIKE :numero';
        $params[':numero'] = '%' . $numeroFacture . '%';
    }
    if ($statut !== '' && in_array($statut, ['brouillon', 'en_attente', 'envoyee', 'en_cours', 'en_retard', 'payee', 'annulee'], true)) {
        $conditions[] = 'f.statut = :statut';
        $params[':statut'] = $statut;
    }

    $whereClause = implode(' AND ', $conditions);
    $params[':limit'] = $limit;

    $sql = "
        SELECT 
            f.id,
            f.numero,
            f.date_facture,
            f.montant_ttc,
            f.statut,
            f.email_envoye,
            f.date_envoi_email,
            c.raison_sociale as client_nom,
            c.nom_dirigeant as client_nom_dirigeant,
            c.prenom_dirigeant as client_prenom_dirigeant,
            c.email as client_email,
            c.numero_client as client_code
        FROM factures f
        JOIN clients c ON c.id = f.id_client
        WHERE {$whereClause}
        ORDER BY f.date_facture DESC, f.id DESC
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $results = [];
    foreach ($rows as $r) {
        $dateEmission = $r['date_facture'] ?? '';
        if ($dateEmission) {
            $dateEmission = date('Y-m-d', strtotime($dateEmission));
        }
        $nomComplet = trim($r['client_nom'] ?? '');
        if ($nomComplet === '') {
            $nomComplet = trim(($r['client_nom_dirigeant'] ?? '') . ' ' . ($r['client_prenom_dirigeant'] ?? '')) ?: 'Client inconnu';
        }
        $results[] = [
            'id' => (int)$r['id'],
            'numero' => $r['numero'] ?? '',
            'client_nom' => $nomComplet,
            'client_code' => $r['client_code'] ?? '',
            'client_email' => $r['client_email'] ?? '',
            'date_emission' => $dateEmission,
            'date_facture' => $dateEmission,
            'montant_ttc' => isset($r['montant_ttc']) ? (float)$r['montant_ttc'] : null,
            'statut' => $r['statut'] ?? '',
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
