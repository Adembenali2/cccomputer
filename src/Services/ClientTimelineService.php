<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Timeline transverse client : relevés, SAV, livraisons, factures, paiements, historique (filtré).
 */
final class ClientTimelineService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return list<array{at:string,sort_key:int,type:string,title:string,summary:string,href:string}>
     */
    public function fetchEvents(int $clientId, int $limit = 80): array
    {
        if ($clientId <= 0) {
            return [];
        }

        $events = [];

        $this->appendReleves($events, $clientId);
        $this->appendSav($events, $clientId);
        $this->appendLivraisons($events, $clientId);
        $this->appendFactures($events, $clientId);
        $this->appendPaiements($events, $clientId);
        $this->appendHistorique($events, $clientId);

        usort($events, static function (array $a, array $b): int {
            return $b['sort_key'] <=> $a['sort_key'];
        });

        return array_slice($events, 0, max(10, min(200, $limit)));
    }

    private function tsKey(string $datetimeOrDate): int
    {
        $t = strtotime($datetimeOrDate);
        return $t !== false ? $t : 0;
    }

    private function macHref(?string $macNorm): string
    {
        $m = trim((string)$macNorm);
        return $m !== '' ? '/public/photocopieurs_details.php?mac=' . rawurlencode($m) : '';
    }

    /** @param list<array> $events */
    private function appendReleves(array &$events, int $clientId): void
    {
        $sqlNew = "
            SELECT cr.`Timestamp` AS ts, cr.mac_norm, cr.Model, cr.Nom, cr.TotalBW, cr.TotalColor
            FROM compteur_relevee cr
            INNER JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm AND pc.id_client = :cid
            ORDER BY cr.`Timestamp` DESC
            LIMIT 40
        ";
        try {
            $st = $this->pdo->prepare($sqlNew);
            $st->execute([':cid' => $clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['ts'] ?? '');
                $model = trim((string)($r['Model'] ?? $r['Nom'] ?? 'Machine'));
                $bw = $r['TotalBW'] ?? '';
                $co = $r['TotalColor'] ?? '';
                $sum = 'BW ' . $bw . ' / Couleur ' . $co;
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'releve',
                    'title' => 'Relevé compteur',
                    'summary' => $model . ' — ' . $sum,
                    'href' => $this->macHref($r['mac_norm'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] releves: ' . $e->getMessage());
        }

        if (!OperationalStatusService::tableExists($this->pdo, 'compteur_relevee_ancien')) {
            return;
        }
        $sqlOld = "
            SELECT cr.`Timestamp` AS ts, cr.mac_norm, cr.Model, cr.Nom, cr.TotalBW, cr.TotalColor
            FROM compteur_relevee_ancien cr
            INNER JOIN photocopieurs_clients pc ON pc.mac_norm = cr.mac_norm AND pc.id_client = :cid
            ORDER BY cr.`Timestamp` DESC
            LIMIT 20
        ";
        try {
            $st = $this->pdo->prepare($sqlOld);
            $st->execute([':cid' => $clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['ts'] ?? '');
                $model = trim((string)($r['Model'] ?? $r['Nom'] ?? 'Machine'));
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'releve_ancien',
                    'title' => 'Relevé (archive)',
                    'summary' => $model,
                    'href' => $this->macHref($r['mac_norm'] ?? null),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] releves ancien: ' . $e->getMessage());
        }
    }

    /** @param list<array> $events */
    private function appendSav(array &$events, int $clientId): void
    {
        try {
            $st = $this->pdo->prepare("
                SELECT id, reference, statut, priorite, date_ouverture, updated_at, created_at, description
                FROM sav
                WHERE id_client = ?
                ORDER BY updated_at DESC, date_ouverture DESC, id DESC
                LIMIT 25
            ");
            $st->execute([$clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['updated_at'] ?? $r['created_at'] ?? $r['date_ouverture'] ?? '');
                $ref = (string)($r['reference'] ?? '');
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'sav',
                    'title' => 'SAV ' . $ref,
                    'summary' => ($r['statut'] ?? '') . ' — ' . mb_substr((string)($r['description'] ?? ''), 0, 120),
                    'href' => '/public/sav.php?client_id=' . $clientId . '&ref=' . rawurlencode($ref),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] sav: ' . $e->getMessage());
        }
    }

    /** @param list<array> $events */
    private function appendLivraisons(array &$events, int $clientId): void
    {
        try {
            $st = $this->pdo->prepare("
                SELECT id, reference, statut, objet, date_prevue, date_reelle, updated_at, created_at
                FROM livraisons
                WHERE id_client = ?
                ORDER BY updated_at DESC, created_at DESC
                LIMIT 25
            ");
            $st->execute([$clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['updated_at'] ?? $r['created_at'] ?? $r['date_prevue'] ?? '');
                $ref = (string)($r['reference'] ?? '');
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'livraison',
                    'title' => 'Livraison ' . $ref,
                    'summary' => ($r['statut'] ?? '') . ' — ' . mb_substr((string)($r['objet'] ?? ''), 0, 100),
                    'href' => '/public/livraison.php?client_id=' . $clientId . '&ref=' . rawurlencode($ref),
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] livraisons: ' . $e->getMessage());
        }
    }

    /** @param list<array> $events */
    private function appendFactures(array &$events, int $clientId): void
    {
        try {
            $st = $this->pdo->prepare("
                SELECT id, numero, date_facture, montant_ttc, statut, created_at
                FROM factures
                WHERE id_client = ? AND statut != 'annulee'
                ORDER BY created_at DESC
                LIMIT 25
            ");
            $st->execute([$clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['created_at'] ?? $r['date_facture'] ?? '');
                $num = (string)($r['numero'] ?? '');
                $fid = (int)($r['id'] ?? 0);
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'facture',
                    'title' => 'Facture ' . $num,
                    'summary' => ($r['statut'] ?? '') . ' — ' . number_format((float)($r['montant_ttc'] ?? 0), 2, ',', ' ') . ' € TTC',
                    'href' => $fid > 0 ? '/public/view_facture.php?id=' . $fid : '/public/paiements.php',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] factures: ' . $e->getMessage());
        }
    }

    /** @param list<array> $events */
    private function appendPaiements(array &$events, int $clientId): void
    {
        try {
            $st = $this->pdo->prepare("
                SELECT p.id, p.montant, p.date_paiement, p.mode_paiement, p.statut, p.created_at, p.id_facture, f.numero AS facture_numero
                FROM paiements p
                LEFT JOIN factures f ON f.id = p.id_facture
                WHERE p.id_client = ?
                ORDER BY p.date_paiement DESC, p.id DESC
                LIMIT 25
            ");
            $st->execute([$clientId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['created_at'] ?? $r['date_paiement'] ?? '');
                $mode = (string)($r['mode_paiement'] ?? '');
                $fn = (string)($r['facture_numero'] ?? '');
                $sum = number_format((float)($r['montant'] ?? 0), 2, ',', ' ') . ' € — ' . $mode;
                if ($fn !== '') {
                    $sum .= ' (fact. ' . $fn . ')';
                }
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'paiement',
                    'title' => 'Paiement',
                    'summary' => ($r['statut'] ?? '') . ' — ' . $sum,
                    'href' => '/public/paiements.php',
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] paiements: ' . $e->getMessage());
        }
    }

    /**
     * Historique : uniquement les lignes dont les détails mentionnent explicitement « Client #id »
     * (convention utilisée par client_fiche et l’audit).
     *
     * @param list<array> $events
     */
    private function appendHistorique(array &$events, int $clientId): void
    {
        $like1 = 'Client #' . $clientId . ' %';
        $like2 = 'Client #' . $clientId . ' -%';
        try {
            $st = $this->pdo->prepare("
                SELECT h.date_action, h.action, h.details
                FROM historique h
                WHERE h.details LIKE ? OR h.details LIKE ?
                ORDER BY h.date_action DESC
                LIMIT 40
            ");
            $st->execute([$like1, $like2]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $ts = (string)($r['date_action'] ?? '');
                $events[] = [
                    'at' => $ts,
                    'sort_key' => $this->tsKey($ts),
                    'type' => 'historique',
                    'title' => (string)($r['action'] ?? 'Événement'),
                    'summary' => mb_substr((string)($r['details'] ?? ''), 0, 180),
                    'href' => '/public/historique.php?client_id=' . $clientId,
                ];
            }
        } catch (\Throwable $e) {
            error_log('[ClientTimelineService] historique: ' . $e->getMessage());
        }
    }
}
