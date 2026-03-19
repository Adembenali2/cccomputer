<?php
/**
 * API pour supprimer plusieurs factures en une fois
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

requireCsrfToken();

try {
    $pdo = getPdo();
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data || empty($data['facture_ids']) || !is_array($data['facture_ids'])) {
        jsonResponse(['ok' => false, 'error' => 'facture_ids requis (tableau)'], 400);
    }
    
    $ids = array_map('intval', $data['facture_ids']);
    $ids = array_filter($ids, fn($id) => $id > 0);
    
    if (empty($ids)) {
        jsonResponse(['ok' => false, 'error' => 'Aucun ID valide'], 400);
    }
    
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    
    // Vérifier les factures avec paiements
    $stmt = $pdo->prepare("SELECT id, numero FROM factures WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $supprimees = [];
    $erreurs = [];
    
    foreach ($factures as $f) {
        $fid = (int)$f['id'];
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM paiements WHERE id_facture = ?");
        $stmt->execute([$fid]);
        if ((int)$stmt->fetchColumn() > 0) {
            $erreurs[] = "Facture {$f['numero']} : des paiements sont associés";
            continue;
        }
        
        $pdo->prepare("DELETE FROM facture_lignes WHERE id_facture = ?")->execute([$fid]);
        $stmt = $pdo->prepare("DELETE FROM factures WHERE id = ?");
        $stmt->execute([$fid]);
        if ($stmt->rowCount() > 0) {
            $supprimees[] = $fid;
            enregistrerAction($pdo, currentUserId(), 'facture_supprimee', "Facture {$f['numero']} supprimée (ID: {$fid})");
        }
    }
    
    jsonResponse([
        'ok' => true,
        'message' => count($supprimees) . ' facture(s) supprimée(s)',
        'supprimees' => $supprimees,
        'erreurs' => $erreurs
    ]);
    
} catch (PDOException $e) {
    error_log('factures_supprimer_masse.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_supprimer_masse.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
