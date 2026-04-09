<?php
declare(strict_types=1);

// [Fonctionnalité Stock] Création + listing des mouvements de stock
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

initApi();
requireApiAuth();

$pdo = getPdoOrFail();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stockId = (int)($_GET['stock_id'] ?? 0);
    if ($stockId <= 0) {
        jsonResponse(['ok' => false, 'error' => 'stock_id requis'], 400);
    }

    $stmt = $pdo->prepare("
        SELECT sm.id, sm.stock_id, sm.type_mouvement, sm.quantite, sm.quantite_avant, sm.quantite_apres,
               sm.motif, sm.reference_doc, sm.created_by, sm.created_at,
               u.nom, u.prenom
        FROM stock_mouvements sm
        LEFT JOIN utilisateurs u ON u.id = sm.created_by
        WHERE sm.stock_id = :stock_id
        ORDER BY sm.id DESC
        LIMIT 300
    ");
    $stmt->execute([':stock_id' => $stockId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    jsonResponse(['ok' => true, 'items' => $rows]);
}

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
$qte = (int)($data['quantite'] ?? 0);
$motif = trim((string)($data['motif'] ?? ''));
$referenceDoc = trim((string)($data['reference_doc'] ?? ''));
$allowedTypes = ['entree', 'sortie', 'ajustement', 'livraison'];

if ($stockId <= 0 || !in_array($type, $allowedTypes, true) || $qte <= 0) {
    jsonResponse(['ok' => false, 'error' => 'Paramètres invalides'], 400);
}

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT id, reference, designation, quantite, unite FROM stock WHERE id = :id FOR UPDATE");
    $stmt->execute([':id' => $stockId]);
    $stock = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$stock) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Article stock introuvable'], 404);
    }

    $avant = (int)$stock['quantite'];
    $apres = $avant;
    if ($type === 'entree') {
        $apres = $avant + $qte;
    } elseif ($type === 'sortie' || $type === 'livraison') {
        if (($stock['unite'] ?? '') === 'carton' && $qte > $avant) {
            $pdo->rollBack();
            jsonResponse(['ok' => false, 'error' => 'Stock insuffisant (cartons)'], 409);
        }
        $apres = $avant - $qte;
    } elseif ($type === 'ajustement') {
        // ajustement = quantité finale (valeur absolue)
        $apres = $qte;
    }

    if ($apres < 0) {
        $pdo->rollBack();
        jsonResponse(['ok' => false, 'error' => 'Stock insuffisant pour cette sortie'], 409);
    }

    $stmt = $pdo->prepare("
        INSERT INTO stock_mouvements (
            stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by
        ) VALUES (
            :stock_id, :type_mouvement, :quantite, :quantite_avant, :quantite_apres, :motif, :reference_doc, :created_by
        )
    ");
    $stmt->execute([
        ':stock_id' => $stockId,
        ':type_mouvement' => $type,
        ':quantite' => $qte,
        ':quantite_avant' => $avant,
        ':quantite_apres' => $apres,
        ':motif' => $motif !== '' ? $motif : null,
        ':reference_doc' => $referenceDoc !== '' ? $referenceDoc : null,
        ':created_by' => currentUserId(),
    ]);

    $pdo->prepare("UPDATE stock SET quantite = :quantite, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
        ->execute([':quantite' => $apres, ':id' => $stockId]);

    $pdo->commit();

    enregistrerAction(
        $pdo,
        currentUserId(),
        'stock_mouvement',
        "Mouvement {$type} article #{$stockId} ({$stock['reference']}) : {$avant} -> {$apres}"
    );

    jsonResponse([
        'ok' => true,
        'stock_id' => $stockId,
        'quantite_avant' => $avant,
        'quantite_apres' => $apres,
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $e;
}

