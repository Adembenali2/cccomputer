<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Synthèse opérationnelle pour le tableau de bord interne (priorités, files, alertes parc/stock).
 */
final class OperationsDashboardService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   counts: array{
     *     sav_ouvert:int,
     *     livraisons_actives:int,
     *     factures_impayees_retard:int,
     *     machines_sans_client:int,
     *     machines_releve_stale:int,
     *     stock_lignes_basses:int
     *   },
     *   sav_queue: list<array<string,mixed>>,
     *   livraisons_queue: list<array<string,mixed>>,
     *   factures_critiques: list<array<string,mixed>>,
     *   paiements_recents: list<array<string,mixed>>,
     *   historique_recents: list<array<string,mixed>>
     * }
     */
    public function getSummary(): array
    {
        $counts = [
            'sav_ouvert' => $this->countSavOuverts(),
            'livraisons_actives' => $this->countLivraisonsActives(),
            'factures_impayees_retard' => 0,
            'machines_sans_client' => OperationalStatusService::countPhotocopieursSansClient($this->pdo),
            'machines_releve_stale' => OperationalStatusService::countMachinesReleveStale($this->pdo),
            'stock_lignes_basses' => OperationalStatusService::countStockLowLines($this->pdo),
        ];

        $facturesCritiques = $this->fetchFacturesCritiques(24);
        $counts['factures_impayees_retard'] = count($facturesCritiques);

        return [
            'counts' => $counts,
            'sav_queue' => $this->fetchSavQueue(12),
            'livraisons_queue' => $this->fetchLivraisonsQueue(12),
            'factures_critiques' => $facturesCritiques,
            'paiements_recents' => $this->fetchPaiementsRecents(10),
            'historique_recents' => $this->fetchHistoriqueRecents(14),
        ];
    }

    private function countSavOuverts(): int
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours')"
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countLivraisonsActives(): int
    {
        try {
            return (int)$this->pdo->query(
                "SELECT COUNT(*) FROM livraisons WHERE statut IN ('planifiee','en_cours')"
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchSavQueue(int $limit): array
    {
        $limit = max(1, min(40, $limit));
        try {
            $sql = "
                SELECT
                    s.id,
                    s.id_client,
                    s.reference,
                    s.statut,
                    s.priorite,
                    s.date_ouverture,
                    s.description,
                    c.raison_sociale AS client_nom
                FROM sav s
                LEFT JOIN clients c ON c.id = s.id_client
                WHERE s.statut IN ('ouvert','en_cours')
                ORDER BY
                    CASE s.priorite
                        WHEN 'urgente' THEN 1
                        WHEN 'haute' THEN 2
                        WHEN 'normale' THEN 3
                        WHEN 'basse' THEN 4
                    END,
                    s.date_ouverture ASC,
                    s.id ASC
                LIMIT {$limit}
            ";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[OperationsDashboardService] sav_queue: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchLivraisonsQueue(int $limit): array
    {
        $limit = max(1, min(40, $limit));
        try {
            $sql = "
                SELECT
                    l.id,
                    l.id_client,
                    l.reference,
                    l.statut,
                    l.date_prevue,
                    l.date_reelle,
                    l.objet,
                    c.raison_sociale AS client_nom,
                    CASE
                        WHEN l.date_prevue < CURDATE() AND l.statut IN ('planifiee','en_cours') THEN 1
                        ELSE 0
                    END AS en_retard
                FROM livraisons l
                LEFT JOIN clients c ON c.id = l.id_client
                WHERE l.statut IN ('planifiee','en_cours')
                ORDER BY en_retard DESC, l.date_prevue ASC, l.id ASC
                LIMIT {$limit}
            ";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[OperationsDashboardService] livraisons_queue: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Factures non annulées, non marquées payées en base, avec solde dû et échéance dépassée (règle 25 du mois).
     *
     * @return list<array<string,mixed>>
     */
    private function fetchFacturesCritiques(int $limit): array
    {
        $limit = max(1, min(60, $limit));
        try {
            $st = $this->pdo->query("
                SELECT
                    f.id,
                    f.id_client,
                    f.numero,
                    f.date_facture,
                    f.montant_ttc,
                    f.statut,
                    c.raison_sociale AS client_nom,
                    COALESCE(SUM(CASE WHEN p.statut = 'recu' THEN p.montant ELSE 0 END), 0) AS paye
                FROM factures f
                LEFT JOIN paiements p ON p.id_facture = f.id
                LEFT JOIN clients c ON c.id = f.id_client
                WHERE f.statut NOT IN ('annulee','payee')
                  AND f.date_facture >= DATE_SUB(CURDATE(), INTERVAL 36 MONTH)
                GROUP BY f.id, f.id_client, f.numero, f.date_facture, f.montant_ttc, f.statut, c.raison_sociale
                ORDER BY f.date_facture ASC
                LIMIT 200
            ");
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $ttc = (float)($r['montant_ttc'] ?? 0);
                $paye = (float)($r['paye'] ?? 0);
                if (!OperationalStatusService::isFactureUnpaid($ttc, $paye)) {
                    continue;
                }
                if (!OperationalStatusService::isFactureOverdueUnpaid($ttc, $paye, (string)($r['date_facture'] ?? ''))) {
                    continue;
                }
                $r['paye'] = $paye;
                $r['reste'] = max(0, $ttc - $paye);
                $out[] = $r;
                if (count($out) >= $limit) {
                    break;
                }
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[OperationsDashboardService] factures_critiques: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchPaiementsRecents(int $limit): array
    {
        $limit = max(1, min(30, $limit));
        try {
            $sql = "
                SELECT
                    p.id,
                    p.id_client,
                    p.montant,
                    p.date_paiement,
                    p.mode_paiement,
                    p.statut,
                    p.id_facture,
                    f.numero AS facture_numero,
                    c.raison_sociale AS client_nom
                FROM paiements p
                LEFT JOIN factures f ON f.id = p.id_facture
                LEFT JOIN clients c ON c.id = p.id_client
                ORDER BY p.date_paiement DESC, p.id DESC
                LIMIT {$limit}
            ";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[OperationsDashboardService] paiements_recents: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchHistoriqueRecents(int $limit): array
    {
        $limit = max(1, min(40, $limit));
        try {
            $sql = "
                SELECT h.id, h.date_action, h.action, h.details,
                       u.nom AS user_nom, u.prenom AS user_prenom
                FROM historique h
                LEFT JOIN utilisateurs u ON u.id = h.user_id
                ORDER BY h.date_action DESC
                LIMIT {$limit}
            ";
            return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[OperationsDashboardService] historique_recents: ' . $e->getMessage());
            return [];
        }
    }
}
