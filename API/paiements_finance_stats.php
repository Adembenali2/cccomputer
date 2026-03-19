<?php
/**
 * API statistiques financières pour la page Paiements
 * Retourne : CA du mois, factures impayées, montant en retard, etc.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    
    $mois = isset($_GET['mois']) ? (int)$_GET['mois'] : (int)date('n');
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');
    
    $debutMois = sprintf('%04d-%02d-01', $annee, $mois);
    $finMois = date('Y-m-t', strtotime($debutMois));
    
    // CA du mois = somme des paiements reçus ce mois
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as ca_mois
        FROM paiements
        WHERE statut = 'recu'
        AND date_paiement >= :debut AND date_paiement <= :fin
    ");
    $stmt->execute([':debut' => $debutMois, ':fin' => $finMois]);
    $caMois = (float)$stmt->fetchColumn();
    
    // Factures impayées (non payées, non annulées) - total montant restant
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as nb, COALESCE(SUM(f.montant_ttc - COALESCE(p.total_paye, 0)), 0) as montant_restant
        FROM factures f
        LEFT JOIN (
            SELECT id_facture, SUM(montant) as total_paye
            FROM paiements WHERE statut = 'recu' GROUP BY id_facture
        ) p ON p.id_facture = f.id
        WHERE f.statut != 'annulee'
        AND (COALESCE(p.total_paye, 0) < f.montant_ttc OR f.montant_ttc = 0)
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $nbImpayees = (int)($row['nb'] ?? 0);
    $montantImpaye = (float)($row['montant_restant'] ?? 0);
    
    // Factures en retard : échéance (25 du mois) dépassée et non payées
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as nb, COALESCE(SUM(f.montant_ttc - COALESCE(p.total_paye, 0)), 0) as montant
        FROM factures f
        LEFT JOIN (
            SELECT id_facture, SUM(montant) as total_paye
            FROM paiements WHERE statut = 'recu' GROUP BY id_facture
        ) p ON p.id_facture = f.id
        WHERE f.statut != 'annulee'
        AND COALESCE(p.total_paye, 0) < f.montant_ttc
        AND CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25') < CURDATE()
    ");
    $stmt->execute();
    $rowRetard = $stmt->fetch(PDO::FETCH_ASSOC);
    $nbEnRetard = (int)($rowRetard['nb'] ?? 0);
    $montantEnRetard = (float)($rowRetard['montant'] ?? 0);
    
    // Total factures (pour indicateurs)
    $stmt = $pdo->query("SELECT COUNT(*) FROM factures WHERE statut != 'annulee'");
    $totalFactures = (int)$stmt->fetchColumn();
    
    jsonResponse([
        'ok' => true,
        'ca_mois' => round($caMois, 2),
        'nb_impayees' => $nbImpayees,
        'montant_impaye' => round($montantImpaye, 2),
        'nb_en_retard' => $nbEnRetard,
        'montant_en_retard' => round($montantEnRetard, 2),
        'total_factures' => $totalFactures,
        'mois' => $mois,
        'annee' => $annee
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_finance_stats.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_finance_stats.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
