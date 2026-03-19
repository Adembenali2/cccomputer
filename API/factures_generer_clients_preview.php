<?php
/**
 * API prévisualisation : nombre de clients éligibles pour la génération de factures
 * GET ?date_debut=YYYY-MM-DD&date_fin=YYYY-MM-DD
 * Retourne : { ok: true, nb_eligibles: N }
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$dateDebut = trim($_GET['date_debut'] ?? '');
$dateFin = trim($_GET['date_fin'] ?? '');

try {
    $pdo = getPdo();
    $dateLimite = date('Y-m-d', strtotime('-1 month'));

    // Compter les clients ayant des imprimantes ET un relevé récent (dernier mois)
    $sql = "
        SELECT COUNT(*) as nb FROM (
            SELECT DISTINCT pc.id_client
            FROM photocopieurs_clients pc
            WHERE pc.mac_norm IS NOT NULL AND pc.mac_norm != ''
            AND (
                EXISTS (SELECT 1 FROM compteur_relevee cr WHERE cr.mac_norm = pc.mac_norm AND cr.Timestamp >= :date_limite)
                OR EXISTS (SELECT 1 FROM compteur_relevee_ancien cra WHERE cra.mac_norm = pc.mac_norm AND cra.Timestamp >= :date_limite2)
            )
        ) AS eligible_clients
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':date_limite' => $dateLimite, ':date_limite2' => $dateLimite]);
    $nb = (int)$stmt->fetchColumn();

    jsonResponse(['ok' => true, 'nb_eligibles' => $nb]);
} catch (PDOException $e) {
    error_log('factures_generer_clients_preview.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('factures_generer_clients_preview.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
