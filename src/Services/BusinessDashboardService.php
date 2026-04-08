<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * KPI dirigeant issus des données réelles (pas de cache).
 */
final class BusinessDashboardService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getKpis(): array
    {
        $today = date('Y-m-d');
        $startWeek = date('Y-m-d', strtotime('-6 days'));
        $startMonth = date('Y-m-01');

        $st = $this->pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) FROM paiements
            WHERE statut = 'recu' AND date_paiement >= ? AND date_paiement <= ?
        ");
        $st->execute([$startWeek, $today]);
        $caSemaine = (float)$st->fetchColumn();

        $st = $this->pdo->prepare("
            SELECT COALESCE(SUM(montant), 0) FROM paiements
            WHERE statut = 'recu' AND date_paiement >= ? AND date_paiement <= ?
        ");
        $st->execute([$startMonth, $today]);
        $caMois = (float)$st->fetchColumn();

        $st = $this->pdo->query("
            SELECT COUNT(*) as nb, COALESCE(SUM(f.montant_ttc - COALESCE(p.total_paye, 0)), 0) as montant
            FROM factures f
            LEFT JOIN (
                SELECT id_facture, SUM(montant) as total_paye
                FROM paiements WHERE statut = 'recu' GROUP BY id_facture
            ) p ON p.id_facture = f.id
            WHERE f.statut != 'annulee'
            AND (COALESCE(p.total_paye, 0) < f.montant_ttc OR f.montant_ttc = 0)
        ");
        $imp = $st->fetch(PDO::FETCH_ASSOC);
        $nbImpayees = (int)($imp['nb'] ?? 0);
        $montantImpaye = round((float)($imp['montant'] ?? 0), 2);

        $st = $this->pdo->query("
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
        $ret = $st->fetch(PDO::FETCH_ASSOC);
        $nbEnRetard = (int)($ret['nb'] ?? 0);
        $montantEnRetard = round((float)($ret['montant'] ?? 0), 2);

        $st = $this->pdo->query("
            SELECT COALESCE(SUM(f.montant_ttc - COALESCE(p.total_paye, 0)), 0) as montant
            FROM factures f
            LEFT JOIN (
                SELECT id_facture, SUM(montant) as total_paye
                FROM paiements WHERE statut = 'recu' GROUP BY id_facture
            ) p ON p.id_facture = f.id
            WHERE f.statut != 'annulee'
            AND COALESCE(p.total_paye, 0) < f.montant_ttc
            AND CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25') >= CURDATE()
            AND CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25') <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ");
        $prevision7 = round((float)$st->fetchColumn(), 2);

        $savOuverts = 0;
        try {
            $savOuverts = (int)$this->pdo->query("
                SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours')
            ")->fetchColumn();
        } catch (\Throwable) {
        }

        $stockCritique = 0;
        try {
            $stockCritique = (int)$this->pdo->query("
                SELECT COUNT(*) FROM (
                    SELECT paper_id FROM v_paper_stock WHERE qty_stock <= 5
                    UNION ALL
                    SELECT toner_id FROM v_toner_stock WHERE qty_stock <= 3
                ) x
            ")->fetchColumn();
        } catch (\Throwable) {
        }

        $st = $this->pdo->query("
            SELECT c.id, c.raison_sociale, COALESCE(SUM(p.montant), 0) as total
            FROM paiements p
            INNER JOIN factures f ON f.id = p.id_facture
            INNER JOIN clients c ON c.id = f.id_client
            WHERE p.statut = 'recu' AND p.date_paiement >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
            GROUP BY c.id, c.raison_sociale
            ORDER BY total DESC
            LIMIT 5
        ");
        $topClients = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = $this->pdo->query("
            SELECT p.montant, p.date_paiement, c.raison_sociale
            FROM paiements p
            LEFT JOIN factures f ON f.id = p.id_facture
            LEFT JOIN clients c ON c.id = f.id_client
            WHERE p.statut = 'recu'
            ORDER BY p.date_paiement DESC, p.id DESC
            LIMIT 8
        ");
        $encaissementsRecents = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $st = $this->pdo->query("
            SELECT action, details, date_action
            FROM historique
            ORDER BY date_action DESC
            LIMIT 12
        ");
        $activite = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'ca_semaine' => round($caSemaine, 2),
            'ca_mois_calendaire' => round($caMois, 2),
            'factures_impayees_count' => $nbImpayees,
            'factures_impayees_montant' => $montantImpaye,
            'factures_retard_count' => $nbEnRetard,
            'factures_retard_montant' => $montantEnRetard,
            'prevision_encaissement_7j' => $prevision7,
            'sav_ouverts' => $savOuverts,
            'stock_alertes' => $stockCritique,
            'top_clients_90j' => $topClients,
            'encaissements_recents' => $encaissementsRecents,
            'activite_recente' => $activite,
        ];
    }
}
