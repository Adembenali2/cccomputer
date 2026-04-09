<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant', 'Secrétaire']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['success' => false, 'message' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input') ?: '{}';
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

requireCsrfForApi((string)($data['csrf_token'] ?? ''));

$stockId = (int)($data['stock_id'] ?? 0);
$type = trim((string)($data['type'] ?? ''));
$quantite = (int)($data['quantite'] ?? 0);
$motif = trim((string)($data['motif'] ?? '')) ?: null;
$referenceDoc = trim((string)($data['reference_doc'] ?? '')) ?: null;

if ($stockId <= 0 || $quantite <= 0 || !in_array($type, ['entree', 'sortie', 'ajustement', 'livraison'], true)) {
    jsonResponse(['success' => false, 'message' => 'Paramètres invalides'], 400);
}

$pdo = getPdoOrFail();
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare("SELECT id, quantite FROM stock WHERE id = ? FOR UPDATE");
    $stmt->execute([$stockId]);
    $article = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$article) {
        throw new RuntimeException('Article introuvable');
    }

    $avant = (int)$article['quantite'];
    $apres = $avant;
    if ($type === 'entree' || $type === 'livraison') {
        $apres = $avant + $quantite;
    } elseif ($type === 'sortie') {
        if ($quantite > $avant) {
            throw new RuntimeException('Stock insuffisant');
        }
        $apres = $avant - $quantite;
    } elseif ($type === 'ajustement') {
        $apres = $quantite;
    }

    $stmt = $pdo->prepare("UPDATE stock SET quantite = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $stmt->execute([$apres, $stockId]);

    $stmt = $pdo->prepare("INSERT INTO stock_mouvements (stock_id, type_mouvement, quantite, quantite_avant, quantite_apres, motif, reference_doc, created_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->execute([$stockId, $type, $quantite, $avant, $apres, $motif, $referenceDoc, (int)($_SESSION['user_id'] ?? 0) ?: null]);

    $pdo->commit();
    jsonResponse(['success' => true, 'quantite_nouvelle' => $apres, 'message' => 'Mouvement enregistré']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'message' => $e->getMessage()], 400);
}
