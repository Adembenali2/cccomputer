<?php
declare(strict_types=1);

// [Fonctionnalité Stock] API mouvements sur table `stock`
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$pdo = getPdoOrFail();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant', 'Secrétaire'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Accès refusé'], 403);
}

requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$stockId = (int)($data['stock_id'] ?? 0);
$type = trim((string)($data['type_mouvement'] ?? ''));
$quantite = (int)($data['quantite'] ?? 0);
$motif = trim((string)($data['motif'] ?? ''));
$referenceDoc = trim((string)($data['reference_doc'] ?? ''));
$allowed = ['entree', 'sortie', 'ajustement', 'livraison'];

if ($stockId <= 0 || $quantite <= 0 || !in_array($type, $allowed, true)) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT id, reference, categorie, unite, contenance, quantite FROM stock WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $stockId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Article introuvable'], 404);
    }

    $avant = (int)$item['quantite'];
    $apres = $avant;
    if ($type === 'entree') {
        $apres = $avant + $quantite;
    } elseif ($type === 'sortie' || $type === 'livraison') {
        if (($item['unite'] ?? '') === 'carton' && $quantite > $avant) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Stock insuffisant en cartons'], 409);
        }
        $apres = $avant - $quantite;
    } elseif ($type === 'ajustement') {
        $apres = $quantite;
    }

    if ($apres < 0) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant'], 409);
    }

    $stmt = $pdo->prepare("
        INSERT INTO stock_mouvements (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by)
        VALUES (:stock_id, :type_mouvement, :quantite, :quantite_avant, :quantite_apres, :motif, :reference_doc, :created_by)
    ");
    $stmt->execute([
        ':stock_id' => $stockId,
        ':type_mouvement' => $type,
        ':quantite' => $quantite,
        ':quantite_avant' => $avant,
        ':quantite_apres' => $apres,
        ':motif' => $motif !== '' ? $motif : null,
        ':reference_doc' => $referenceDoc !== '' ? $referenceDoc : null,
        ':created_by' => (int)$_SESSION['user_id'],
    ]);

    $pdo->prepare("UPDATE stock SET quantite = :q, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':q' => $apres, ':id' => $stockId]);

    $pdo->commit();
    enregistrerAction($pdo, (int)$_SESSION['user_id'], 'stock_mouvement', "Article #{$stockId} ({$item['reference']}) {$type}: {$avant} -> {$apres}");

    jsonResponse(['ok' => true, 'quantite_avant' => $avant, 'quantite_apres' => $apres], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

