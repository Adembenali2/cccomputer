<?php
/**
 * API statistiques financières pour la page Paiements
 * Mois société : du 20 au 19 du mois suivant (ex: 20 nov - 19 déc)
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/api_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$moisNoms = ['', 'janv', 'févr', 'mars', 'avr', 'mai', 'juin', 'juil', 'août', 'sept', 'oct', 'nov', 'déc'];

try {
    $pdo = getPdo();
    
    $annee = isset($_GET['annee']) ? (int)$_GET['annee'] : (int)date('Y');
    $jour = (int)date('j');
    
    // Période mois société : du 20 au 19 (si jour >= 20 : 20 ce mois → 19 mois suivant ; sinon : 20 mois précédent → 19 ce mois)
    if ($jour >= 20) {
        $debutMois = sprintf('%04d-%02d-20', $annee, (int)date('n'));
        $finMois = date('Y-m-d', strtotime($debutMois . ' +1 month -1 day'));
    } else {
        $prevMonth = (int)date('n') - 1;
        $prevYear = $annee;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }
        $debutMois = sprintf('%04d-%02d-20', $prevYear, $prevMonth);
        $finMois = sprintf('%04d-%02d-19', $annee, (int)date('n'));
    }
    
    // CA année = somme des paiements reçus du 1er jan au 31 déc
    $debutAnnee = sprintf('%04d-01-01', $annee);
    $finAnnee = sprintf('%04d-12-31', $annee);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as ca_annee
        FROM paiements
        WHERE statut = 'recu'
        AND date_paiement >= :debut AND date_paiement <= :fin
    ");
    $stmt->execute([':debut' => $debutAnnee, ':fin' => $finAnnee]);
    $caAnnee = (float)$stmt->fetchColumn();
    
    // CA du mois (période 20-19)
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant), 0) as ca_mois
        FROM paiements
        WHERE statut = 'recu'
        AND date_paiement >= :debut AND date_paiement <= :fin
    ");
    $stmt->execute([':debut' => $debutMois, ':fin' => $finMois]);
    $caMois = (float)$stmt->fetchColumn();
    
    // Libellé période mois (ex: "20 nov - 19 déc")
    $d1 = explode('-', $debutMois);
    $d2 = explode('-', $finMois);
    $moisPeriodeLabel = '20 ' . ($moisNoms[(int)$d1[1]] ?? '') . ' - 19 ' . ($moisNoms[(int)$d2[1]] ?? '');
    
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
    
    // Factures totales : payées vs impayées
    $stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN COALESCE(p.total_paye, 0) >= f.montant_ttc AND f.montant_ttc > 0 THEN 1 ELSE 0 END) as nb_payees,
            SUM(CASE WHEN COALESCE(p.total_paye, 0) < f.montant_ttc OR f.montant_ttc = 0 THEN 1 ELSE 0 END) as nb_impayees
        FROM factures f
        LEFT JOIN (SELECT id_facture, SUM(montant) as total_paye FROM paiements WHERE statut = 'recu' GROUP BY id_facture) p ON p.id_facture = f.id
        WHERE f.statut != 'annulee'
    ");
    $stmt->execute();
    $rowTot = $stmt->fetch(PDO::FETCH_ASSOC);
    $nbPayees = (int)($rowTot['nb_payees'] ?? 0);
    
    jsonResponse([
        'ok' => true,
        'ca_annee' => round($caAnnee, 2),
        'ca_mois' => round($caMois, 2),
        'mois_periode_label' => $moisPeriodeLabel,
        'nb_impayees' => $nbImpayees,
        'montant_impaye' => round($montantImpaye, 2),
        'nb_en_retard' => $nbEnRetard,
        'montant_en_retard' => round($montantEnRetard, 2),
        'nb_payees' => $nbPayees,
        'total_factures' => $nbPayees + $nbImpayees,
        'annee' => $annee
    ]);
    
} catch (PDOException $e) {
    error_log('paiements_finance_stats.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('paiements_finance_stats.php: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}
