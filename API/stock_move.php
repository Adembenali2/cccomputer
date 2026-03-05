<?php
/**
 * API mouvements stock (universel : papier, toner, lcd, pc)
 * POST : créer un mouvement (entrée/sortie/ajustement)
 * GET  : récupérer les 20 derniers mouvements d'un produit
 *
 * @package CCComputer
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

require_once __DIR__ . '/../includes/historique.php';

$allowedTypes = ['papier', 'toner', 'lcd', 'pc'];
$allowedReasons = ['ajustement', 'achat', 'retour', 'correction'];

$typeMap = [
    'papier' => ['table' => 'paper_moves', 'id_col' => 'paper_id', 'catalog' => 'paper_catalog'],
    'toner'  => ['table' => 'toner_moves', 'id_col' => 'toner_id', 'catalog' => 'toner_catalog'],
    'lcd'    => ['table' => 'lcd_moves', 'id_col' => 'lcd_id', 'catalog' => 'lcd_catalog'],
    'pc'     => ['table' => 'pc_moves', 'id_col' => 'pc_id', 'catalog' => 'pc_catalog'],
];

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// ===================== GET : Liste des mouvements =====================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $type = trim($_GET['type'] ?? '');
    $productId = (int)($_GET['id'] ?? 0);

    if (!in_array($type, $allowedTypes, true) || $productId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'Paramètres type et id requis et valides'], 400);
    }

    $config = $typeMap[$type];
    $table = $config['table'];
    $idCol = $config['id_col'];

    try {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(qty_delta), 0) AS current_stock
            FROM {$table}
            WHERE {$idCol} = ?
        ");
        $stmt->execute([$productId]);
        $currentStock = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT m.id, m.qty_delta, m.reason, m.reference, m.user_id, m.created_at,
                   u.nom, u.prenom
            FROM {$table} m
            LEFT JOIN utilisateurs u ON u.id = m.user_id
            WHERE m.{$idCol} = ?
            ORDER BY m.created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$productId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $moves = [];
        foreach ($rows as $r) {
            $moves[] = [
                'id' => (int) $r['id'],
                'qty_delta' => (int) $r['qty_delta'],
                'reason' => $r['reason'],
                'reference' => $r['reference'] ?? '',
                'user_name' => trim(($r['nom'] ?? '') . ' ' . ($r['prenom'] ?? '')) ?: '—',
                'created_at' => $r['created_at'],
            ];
        }

        jsonResponse([
            'ok' => true,
            'moves' => $moves,
            'current_stock' => $currentStock,
        ], 200);
    } catch (PDOException $e) {
        error_log('stock_move.php GET: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
    }
}

// ===================== POST : Créer un mouvement =====================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true) ?? [];

$type = trim($data['type'] ?? '');
$productId = (int)($data['product_id'] ?? 0);
$qtyDelta = (int)($data['qty_delta'] ?? 0);
$reason = trim($data['reason'] ?? 'ajustement');
$reference = trim($data['reference'] ?? '');
$csrfToken = $data['csrf_token'] ?? '';

if (!in_array($type, $allowedTypes, true) || $productId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Type et product_id requis et valides'], 400);
}

if ($qtyDelta === 0) {
    jsonResponse(['ok' => false, 'error' => 'La quantité ne peut pas être nulle'], 400);
}

if (!in_array($reason, $allowedReasons, true)) {
    jsonResponse(['ok' => false, 'error' => 'Raison invalide. Valeurs autorisées : ajustement, achat, retour, correction'], 400);
}

$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

$config = $typeMap[$type];
$table = $config['table'];
$idCol = $config['id_col'];
$userId = $_SESSION['user_id'] ?? null;

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->beginTransaction();

    $lockSql = "SELECT COALESCE(SUM(qty_delta), 0) AS cur FROM {$table} WHERE {$idCol} = ? FOR UPDATE";
    $stmt = $pdo->prepare($lockSql);
    $stmt->execute([$productId]);
    $cur = (int) $stmt->fetchColumn();

    if ($qtyDelta < 0 && $cur + $qtyDelta < 0) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant. Stock actuel : ' . $cur], 409);
    }

    $insSql = "INSERT INTO {$table} ({$idCol}, qty_delta, reason, reference, user_id) VALUES (?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($insSql);
    $stmt->execute([$productId, $qtyDelta, $reason, $reference ?: null, $userId]);

    $newStock = $cur + $qtyDelta;
    $pdo->commit();

    $actionLabels = [
        'ajustement' => 'Ajustement',
        'achat' => 'Achat',
        'retour' => 'Retour',
        'correction' => 'Correction',
    ];
    $details = sprintf(
        'Mouvement stock %s #%d : %s %d (%s) — Nouveau stock : %d',
        $type,
        $productId,
        $qtyDelta > 0 ? '+' : '',
        $qtyDelta,
        $actionLabels[$reason] ?? $reason,
        $newStock
    );
    if ($reference !== '') {
        $details .= ' — Réf: ' . $reference;
    }
    enregistrerAction($pdo, $userId, 'mouvement_stock_' . $type, $details);

    $response = ['ok' => true, 'new_stock' => $newStock];
    if ($newStock <= 2 && in_array($type, ['papier', 'toner', 'lcd', 'pc'], true)) {
        $response['warning'] = 'Stock faible : ' . $newStock . ' unité(s).';
    }
    jsonResponse($response, 200);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('stock_move.php POST: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
}
