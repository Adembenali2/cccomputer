<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Données agrégées pour la fiche client « centre de contrôle ».
 */
final class ClientOverviewService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{
     *   printers: list<array>,
     *   sav: list<array>,
     *   livraisons: list<array>,
     *   factures: list<array>,
     *   paiements: list<array>,
     *   alerts: list<array{level:string,message:string,title?:string}>,
     *   counts: array{sav_ouvert:int,livraisons_actives:int,impayees:int}
     * }
     */
    public function getSnapshot(int $clientId): array
    {
        if ($clientId <= 0) {
            return [
                'printers' => [],
                'sav' => [],
                'livraisons' => [],
                'factures' => [],
                'paiements' => [],
                'alerts' => [],
                'counts' => ['sav_ouvert' => 0, 'livraisons_actives' => 0, 'impayees' => 0],
            ];
        }

        $printers = $this->fetchPrinters($clientId);
        $sav = $this->fetchSavRecent($clientId, 6);
        $livraisons = $this->fetchLivraisonsRecent($clientId, 6);
        $factures = $this->fetchFacturesRecent($clientId, 8);
        $paiements = $this->fetchPaiementsRecent($clientId, 8);

        $savOuvert = $this->countSavOuverts($clientId);
        $livAct = $this->countLivraisonsActives($clientId);
        $impayees = $this->countFacturesImpayees($clientId);

        $alerts = $this->buildAlerts($clientId, $printers, $savOuvert);

        return [
            'printers' => $printers,
            'sav' => $sav,
            'livraisons' => $livraisons,
            'factures' => $factures,
            'paiements' => $paiements,
            'alerts' => $alerts,
            'counts' => [
                'sav_ouvert' => $savOuvert,
                'livraisons_actives' => $livAct,
                'impayees' => $impayees,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    private function fetchPrinters(int $clientId): array
    {
        try {
            $sql = "
                SELECT pc.id, pc.mac_norm, pc.SerialNumber, pc.MacAddress,
                       v.Model, v.Nom, v.`Timestamp` AS last_ts, v.TotalBW, v.TotalColor, v.Status
                FROM photocopieurs_clients pc
                LEFT JOIN v_compteur_last v ON v.mac_norm = pc.mac_norm
                WHERE pc.id_client = ?
                ORDER BY v.`Timestamp` DESC, pc.id DESC
            ";
            $st = $this->pdo->prepare($sql);
            $st->execute([$clientId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['stale'] = OperationalStatusService::releveIsStale($r['last_ts'] ?? null);
                $r['stale_days'] = OperationalStatusService::releveAgeDays($r['last_ts'] ?? null);
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] printers view: ' . $e->getMessage());
            try {
                $st = $this->pdo->prepare("
                    SELECT pc.id, pc.mac_norm, pc.SerialNumber, pc.MacAddress
                    FROM photocopieurs_clients pc
                    WHERE pc.id_client = ?
                    ORDER BY pc.id DESC
                ");
                $st->execute([$clientId]);
                return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            } catch (\Throwable $e2) {
                error_log('[ClientOverviewService] printers fallback: ' . $e2->getMessage());
                return [];
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchSavRecent(int $clientId, int $n): array
    {
        try {
            $st = $this->pdo->prepare("
                SELECT s.id, s.reference, s.statut, s.priorite, s.date_ouverture, s.date_fermeture, s.description
                FROM sav s
                WHERE s.id_client = ?
                ORDER BY s.date_ouverture DESC, s.id DESC
                LIMIT " . (int)$n);
            $st->execute([$clientId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] sav: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchLivraisonsRecent(int $clientId, int $n): array
    {
        try {
            $st = $this->pdo->prepare("
                SELECT l.id, l.reference, l.statut, l.objet, l.date_prevue, l.date_reelle
                FROM livraisons l
                WHERE l.id_client = ?
                ORDER BY l.date_prevue DESC, l.id DESC
                LIMIT " . (int)$n);
            $st->execute([$clientId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] livraisons: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchFacturesRecent(int $clientId, int $n): array
    {
        try {
            $st = $this->pdo->prepare("
                SELECT f.id, f.numero, f.date_facture, f.montant_ttc, f.statut,
                       COALESCE(SUM(CASE WHEN p.statut = 'recu' THEN p.montant ELSE 0 END), 0) AS paye
                FROM factures f
                LEFT JOIN paiements p ON p.id_facture = f.id
                WHERE f.id_client = ? AND f.statut != 'annulee'
                GROUP BY f.id, f.numero, f.date_facture, f.montant_ttc, f.statut
                ORDER BY f.date_facture DESC, f.id DESC
                LIMIT " . (int)$n);
            $st->execute([$clientId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $ttc = (float)($r['montant_ttc'] ?? 0);
                $paye = (float)($r['paye'] ?? 0);
                $r['paye'] = $paye;
                $r['impayee'] = $ttc > 0 && $paye < $ttc;
                $r['paiement_label'] = $ttc <= 0 ? '—' : ($paye >= $ttc ? 'Payée' : ($paye > 0 ? 'Partielle' : 'Impayée'));
                $r['en_retard'] = OperationalStatusService::isFactureOverdueUnpaid($ttc, $paye, (string)($r['date_facture'] ?? ''));
            }
            unset($r);
            return $rows;
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] factures: ' . $e->getMessage());
            return [];
        }
    }

    /** @return list<array<string,mixed>> */
    private function fetchPaiementsRecent(int $clientId, int $n): array
    {
        try {
            $st = $this->pdo->prepare("
                SELECT p.id, p.montant, p.date_paiement, p.mode_paiement, p.statut, p.id_facture, f.numero AS facture_numero
                FROM paiements p
                LEFT JOIN factures f ON f.id = p.id_facture
                WHERE p.id_client = ?
                ORDER BY p.date_paiement DESC, p.id DESC
                LIMIT " . (int)$n);
            $st->execute([$clientId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] paiements: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Nombre de factures non annulées avec solde dû (TTC − paiements « reçus »), toutes périodes confondues.
     */
    private function countFacturesImpayees(int $clientId): int
    {
        try {
            $st = $this->pdo->prepare("
                SELECT COUNT(*) FROM (
                    SELECT f.id
                    FROM factures f
                    LEFT JOIN paiements p ON p.id_facture = f.id
                    WHERE f.id_client = ?
                      AND f.statut NOT IN ('annulee')
                    GROUP BY f.id, f.montant_ttc
                    HAVING f.montant_ttc > 0
                       AND COALESCE(SUM(CASE WHEN p.statut = 'recu' THEN p.montant ELSE 0 END), 0) < f.montant_ttc
                ) t
            ");
            $st->execute([$clientId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] countFacturesImpayees: ' . $e->getMessage());
            return 0;
        }
    }

    /** @return list<array{numero:string,date_facture:string,montant_ttc:float,paye:float}> */
    private function fetchFacturesOverdueUnpaidForClient(int $clientId): array
    {
        try {
            $st = $this->pdo->prepare("
                SELECT f.numero, f.date_facture, f.montant_ttc,
                       COALESCE(SUM(CASE WHEN p.statut = 'recu' THEN p.montant ELSE 0 END), 0) AS paye
                FROM factures f
                LEFT JOIN paiements p ON p.id_facture = f.id
                WHERE f.id_client = ?
                  AND f.statut NOT IN ('annulee')
                GROUP BY f.id, f.numero, f.date_facture, f.montant_ttc
            ");
            $st->execute([$clientId]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $ttc = (float)($r['montant_ttc'] ?? 0);
                $paye = (float)($r['paye'] ?? 0);
                if (OperationalStatusService::isFactureOverdueUnpaid($ttc, $paye, (string)($r['date_facture'] ?? ''))) {
                    $out[] = [
                        'numero' => (string)($r['numero'] ?? ''),
                        'date_facture' => (string)($r['date_facture'] ?? ''),
                        'montant_ttc' => $ttc,
                        'paye' => $paye,
                    ];
                }
            }
            return $out;
        } catch (\Throwable $e) {
            error_log('[ClientOverviewService] fetchFacturesOverdueUnpaidForClient: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * @param list<array> $printers
     * @return list<array{level:string,message:string,title?:string}>
     */
    private function buildAlerts(int $clientId, array $printers, int $savOuvert): array
    {
        $alerts = [];

        if ($savOuvert > 0) {
            $alerts[] = [
                'level' => 'warn',
                'title' => 'SAV',
                'message' => $savOuvert . ' ticket(s) ouvert(s) ou en cours pour ce client.',
            ];
        }

        $overdue = $this->fetchFacturesOverdueUnpaidForClient($clientId);
        $maxLines = 5;
        foreach (array_slice($overdue, 0, $maxLines) as $f) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Facturation',
                'message' => 'Facture ' . ($f['numero'] !== '' ? $f['numero'] : '(sans numéro)')
                    . ' : impayée après échéance (règle du 25 du mois suivant la date de facture).',
            ];
        }
        $more = count($overdue) - $maxLines;
        if ($more > 0) {
            $alerts[] = [
                'level' => 'danger',
                'title' => 'Facturation',
                'message' => '… et ' . $more . ' autre(s) facture(s) en retard (voir Paiements / factures).',
            ];
        }

        if (count($printers) === 0) {
            $alerts[] = [
                'level' => 'info',
                'title' => 'Parc',
                'message' => 'Aucune imprimante rattachée à ce client.',
            ];
        }

        foreach ($printers as $p) {
            if (!empty($p['stale'])) {
                $mac = (string)($p['mac_norm'] ?? '');
                $label = trim((string)($p['Model'] ?? '')) ?: ($mac !== '' ? $mac : '—');
                $alerts[] = [
                    'level' => 'warn',
                    'title' => 'Relevés',
                    'message' => 'Relevé obsolète ou absent pour « ' . $label . ' » (seuil : '
                        . OperationalStatusService::STALE_RELEVE_DAYS . ' jours sans relevé).',
                ];
            }
        }

        try {
            $st = $this->pdo->prepare("
                SELECT MAX(f.date_facture) AS dmax FROM factures f WHERE f.id_client = ? AND f.statut != 'annulee'
            ");
            $st->execute([$clientId]);
            $dmax = $st->fetchColumn();
            if ($dmax && strtotime((string)$dmax) < strtotime('-365 days')) {
                $alerts[] = [
                    'level' => 'info',
                    'title' => 'Activité',
                    'message' => 'Aucune facture enregistrée depuis plus d’un an — vérifier si le client est toujours actif.',
                ];
            }
        } catch (\Throwable) {
        }

        return $alerts;
    }

    private function countSavOuverts(int $clientId): int
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM sav WHERE id_client = ? AND statut IN ('ouvert','en_cours')"
            );
            $st->execute([$clientId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    private function countLivraisonsActives(int $clientId): int
    {
        try {
            $st = $this->pdo->prepare(
                "SELECT COUNT(*) FROM livraisons WHERE id_client = ? AND statut IN ('planifiee','en_cours')"
            );
            $st->execute([$clientId]);
            return (int)$st->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
