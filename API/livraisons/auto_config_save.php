<?php
declare(strict_types=1);

// [Livraison Auto]
require_once __DIR__ . '/../../includes/api_helpers.php';
require_once __DIR__ . '/../../includes/historique.php';

initApi();
requireApiAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}
requireCsrfToken();
if (!in_array((string)($_SESSION['emploi'] ?? ''), ['Admin', 'Dirigeant'], true)) {
    jsonResponse(['success' => false, 'error' => 'Accès refusé'], 403);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data) || empty($data)) {
    $data = $_POST;
}

$idClient = (int)($data['id_client'] ?? 0);
if ($idClient <= 0) {
    jsonResponse(['success' => false, 'error' => 'id_client invalide'], 400);
}

try {
    $pdo = getPdo();
    $stmt = $pdo->prepare("
      INSERT INTO livraison_auto_config
      (id_client, actif, papier_actif, papier_product_id, papier_seuil, papier_qte_livraison, papier_qte_auto,
       toner_actif, toner_seuil_pct, derniere_verification)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        actif=VALUES(actif),
        papier_actif=VALUES(papier_actif),
        papier_product_id=VALUES(papier_product_id),
        papier_seuil=VALUES(papier_seuil),
        papier_qte_livraison=VALUES(papier_qte_livraison),
        papier_qte_auto=VALUES(papier_qte_auto),
        toner_actif=VALUES(toner_actif),
        toner_seuil_pct=VALUES(toner_seuil_pct),
        derniere_verification=NOW()
    ");
    $stmt->execute([
        $idClient,
        (int)($data['actif'] ?? 0) ? 1 : 0,
        (int)($data['papier_actif'] ?? 0) ? 1 : 0,
        ($data['papier_product_id'] ?? '') !== '' ? (int)$data['papier_product_id'] : null,
        max(1, (int)($data['papier_seuil'] ?? 5)),
        max(1, (int)($data['papier_qte_livraison'] ?? 10)),
        (int)($data['papier_qte_auto'] ?? 1) ? 1 : 0,
        (int)($data['toner_actif'] ?? 0) ? 1 : 0,
        max(5, min(30, (int)($data['toner_seuil_pct'] ?? 10))),
    ]);
    enregistrerAction($pdo, currentUserId(), 'livraison_auto_config_modifiee', 'Config livraison auto client #' . $idClient);
    jsonResponse(['success' => true]);
} catch (Throwable $e) {
    error_log('auto_config_save.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
