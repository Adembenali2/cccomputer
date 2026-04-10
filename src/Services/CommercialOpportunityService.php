<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Règles simples d’upsell : upsert dans commercial_opportunites (unique client + rule_code).
 */
final class CommercialOpportunityService
{
    public function __construct(private PDO $pdo)
    {
    }

    public function syncAll(?int $systemUserId = null): int
    {
        require_once __DIR__ . '/../../includes/parametres.php';
        if (!ProductTier::canUseFeature($this->pdo, 'module_opportunites')) {
            return 0;
        }

        $n = 0;
        $n += $this->ruleSavMaintenance();
        $n += $this->ruleConsumablesSubscription();
        $n += $this->ruleInactiveClient();
        $n += $this->ruleHighValuePremium();

        if ($n > 0) {
            require_once __DIR__ . '/../../includes/historique.php';
            enregistrerAction(
                $this->pdo,
                $systemUserId,
                'opportunites_sync',
                "Recalcul opportunités : {$n} ligne(s) touchée(s)",
                $systemUserId === null ? getServerIp() : null
            );
        }

        return $n;
    }

    private function upsert(int $clientId, string $ruleCode, string $titre, string $detail): int
    {
        $st = $this->pdo->prepare("SELECT id, statut FROM commercial_opportunites WHERE id_client = ? AND rule_code = ? LIMIT 1");
        $st->execute([$clientId, $ruleCode]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            if (!in_array($row['statut'], ['nouveau', 'vu'], true)) {
                return 0;
            }
            $u = $this->pdo->prepare("
                UPDATE commercial_opportunites SET titre = ?, detail = ?, updated_at = NOW()
                WHERE id = ?
            ");
            $u->execute([$titre, $detail, (int)$row['id']]);
            return (int)$u->rowCount();
        }
        $i = $this->pdo->prepare("
            INSERT INTO commercial_opportunites (id_client, rule_code, titre, detail, statut)
            VALUES (?, ?, ?, ?, 'nouveau')
        ");
        $i->execute([$clientId, $ruleCode, $titre, $detail]);
        return 1;
    }

    /** Beaucoup de tickets SAV sur 12 mois → contrat maintenance */
    private function ruleSavMaintenance(): int
    {
        $sql = "
            SELECT id_client, COUNT(*) AS n
            FROM sav
            WHERE id_client IS NOT NULL
            AND date_ouverture >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY id_client
            HAVING n >= 4
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $c = 0;
        foreach ($rows as $r) {
            $cid = (int)$r['id_client'];
            $c += $this->upsert(
                $cid,
                'sav_maintenance',
                'Proposer un contrat de maintenance',
                (string)((int)$r['n']) . ' demandes SAV sur 12 mois : un forfait maintenance peut réduire les interruptions et lisser les coûts.'
            );
        }
        return $c;
    }

    /** Nombreuses factures consommation → abonnement / prévisionnel */
    private function ruleConsumablesSubscription(): int
    {
        $sql = "
            SELECT f.id_client, COUNT(*) AS n
            FROM factures f
            WHERE f.type = 'Consommation' AND f.statut != 'annulee'
            AND f.date_facture >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY f.id_client
            HAVING n >= 4
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $c = 0;
        foreach ($rows as $r) {
            $cid = (int)$r['id_client'];
            $c += $this->upsert(
                $cid,
                'consommables_abo',
                'Proposer un abonnement consommables',
                (string)((int)$r['n']) . ' factures consommation sur 6 mois : un abonnement ou un plafond mensuel peut simplifier la facturation.'
            );
        }
        return $c;
    }

    /** Pas de facture depuis 12 mois (client actif dans la base) */
    private function ruleInactiveClient(): int
    {
        $sql = "
            SELECT c.id
            FROM clients c
            LEFT JOIN (
                SELECT id_client, MAX(date_facture) AS dmax
                FROM factures WHERE statut != 'annulee' GROUP BY id_client
            ) fx ON fx.id_client = c.id
            WHERE (fx.dmax IS NULL OR fx.dmax < DATE_SUB(CURDATE(), INTERVAL 12 MONTH))
            AND c.date_creation < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
        $c = 0;
        foreach ($rows as $id) {
            $cid = (int)$id;
            $c += $this->upsert(
                $cid,
                'relance_commerciale',
                'Relance commerciale',
                'Aucune facture depuis plus de 12 mois : prévoir un appel ou une visite pour réactiver le compte.'
            );
        }
        return $c;
    }

    /** CA encaissé élevé sur 24 mois → offre premium / SLA */
    private function ruleHighValuePremium(): int
    {
        $sql = "
            SELECT f.id_client, COALESCE(SUM(p.montant), 0) AS total
            FROM paiements p
            INNER JOIN factures f ON f.id = p.id_facture
            WHERE p.statut = 'recu'
            AND p.date_paiement >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
            GROUP BY f.id_client
            HAVING total >= 15000
        ";
        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $c = 0;
        foreach ($rows as $r) {
            $cid = (int)$r['id_client'];
            $total = number_format((float)$r['total'], 0, ',', ' ');
            $c += $this->upsert(
                $cid,
                'compte_premium',
                'Compte stratégique — upsell premium',
                "Environ {$total} € encaissés sur 24 mois : proposer temps d'intervention prioritaire, audit parc ou contrat cadre."
            );
        }
        return $c;
    }
}
