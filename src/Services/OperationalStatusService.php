<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Règles métier transverses : relevés obsolètes, impayés, machines orphelines, etc.
 * Réutilisable par la fiche client, la timeline, le dashboard opérationnel.
 */
final class OperationalStatusService
{
    /** Nombre de jours sans relevé = alerte sur le parc attribué */
    public const STALE_RELEVE_DAYS = 14;

    public static function releveIsStale(?string $lastTimestamp): bool
    {
        if ($lastTimestamp === null || $lastTimestamp === '') {
            return true;
        }
        $t = strtotime($lastTimestamp);
        if ($t === false) {
            return true;
        }
        return $t < strtotime('-' . self::STALE_RELEVE_DAYS . ' days');
    }

    public static function releveAgeDays(?string $lastTimestamp): ?int
    {
        if ($lastTimestamp === null || $lastTimestamp === '') {
            return null;
        }
        $t = strtotime($lastTimestamp);
        if ($t === false) {
            return null;
        }
        return (int)floor((time() - $t) / 86400);
    }

    /**
     * Échéance métier alignée sur la logique paiements (25 du mois de date_facture).
     */
    public static function factureEcheanceYmd(string $dateFacture): string
    {
        $d = new \DateTimeImmutable($dateFacture);
        return $d->format('Y-m') . '-25';
    }

    public static function isFactureOverdueUnpaid(float $montantTtc, float $totalPaye, string $dateFacture): bool
    {
        if ($montantTtc <= 0) {
            return false;
        }
        if ($totalPaye >= $montantTtc) {
            return false;
        }
        $echeance = self::factureEcheanceYmd($dateFacture);
        return $echeance < date('Y-m-d');
    }

    public static function isFactureUnpaid(float $montantTtc, float $totalPaye): bool
    {
        return $montantTtc > 0 && $totalPaye < $montantTtc;
    }

    public static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $st = $pdo->prepare(
                'SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
            );
            $st->execute([$table]);
            return (bool)$st->fetchColumn();
        } catch (\Throwable) {
            return false;
        }
    }

    /** Machines en base sans client (id_client NULL). */
    public static function countPhotocopieursSansClient(PDO $pdo): int
    {
        try {
            return (int)$pdo->query(
                'SELECT COUNT(*) FROM photocopieurs_clients WHERE id_client IS NULL'
            )->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Machines attribuées dont le dernier relevé (vue v_compteur_last si dispo) est absent ou ancien.
     */
    public static function countMachinesReleveStale(PDO $pdo): int
    {
        try {
            $sql = "
                SELECT COUNT(*) FROM photocopieurs_clients pc
                LEFT JOIN v_compteur_last v ON v.mac_norm = pc.mac_norm
                WHERE pc.id_client IS NOT NULL
                  AND (v.Timestamp IS NULL OR v.Timestamp < DATE_SUB(NOW(), INTERVAL " . (int)self::STALE_RELEVE_DAYS . " DAY))
            ";
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Résumé stock bas (papier ≤5, toner ≤3) — mêmes seuils que le dashboard business. */
    public static function countStockLowLines(PDO $pdo): int
    {
        try {
            return (int)$pdo->query("
                SELECT COUNT(*) FROM (
                    SELECT paper_id FROM v_paper_stock WHERE qty_stock <= 5
                    UNION ALL
                    SELECT toner_id FROM v_toner_stock WHERE qty_stock <= 3
                ) x
            ")->fetchColumn();
        } catch (\Throwable) {
            return 0;
        }
    }
}
