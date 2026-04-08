<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Calcule et met à jour le statut des factures selon la logique :
 * - payee : facture entièrement payée
 * - envoyee : facture envoyée au client (email)
 * - en_attente : facture générée, avant le 25 du mois
 * - en_cours : le 25 du mois de la facture
 * - en_retard : après le 25 du mois de la facture
 */
class FactureStatutService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Calcule le statut basé sur la date pour une facture non payée
     */
    public function computeStatutFromDate(string $dateFacture): string
    {
        $factureDate = new \DateTimeImmutable($dateFacture);
        $today = new \DateTimeImmutable('today');

        $factureYear = (int)$factureDate->format('Y');
        $factureMonth = (int)$factureDate->format('m');
        $todayYear = (int)$today->format('Y');
        $todayMonth = (int)$today->format('m');
        $todayDay = (int)$today->format('d');

        if ($todayYear < $factureYear) {
            return 'en_attente';
        }
        if ($todayYear === $factureYear && $todayMonth < $factureMonth) {
            return 'en_attente';
        }
        if ($todayYear === $factureYear && $todayMonth === $factureMonth) {
            if ($todayDay < 25) {
                return 'en_attente';
            }
            if ($todayDay === 25) {
                return 'en_cours';
            }
            return 'en_retard';
        }
        return 'en_retard';
    }

    /**
     * Met à jour les statuts des factures selon la date (sauf payee, envoyee et annulee).
     * Les factures envoyées au client restent "envoyee" jusqu'à paiement.
     */
    public function updateStatutsFromDate(): int
    {
        $stmt = $this->pdo->query("
            SELECT id, date_facture, statut 
            FROM factures 
            WHERE statut NOT IN ('payee', 'envoyee', 'annulee')
        ");
        $factures = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updated = 0;

        foreach ($factures as $f) {
            $newStatut = $this->computeStatutFromDate($f['date_facture']);
            if ($newStatut !== $f['statut']) {
                $up = $this->pdo->prepare("UPDATE factures SET statut = ? WHERE id = ?");
                $up->execute([$newStatut, $f['id']]);
                $updated += $up->rowCount();
            }
        }

        return $updated;
    }

    /**
     * Vérifie si une facture est entièrement payée (somme des paiements validés >= montant_ttc)
     */
    public function isFactureFullyPaid(int $factureId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT f.montant_ttc,
                   COALESCE(SUM(p.montant), 0) as total_paye
            FROM factures f
            LEFT JOIN paiements p ON p.id_facture = f.id AND p.statut = 'recu'
            WHERE f.id = ?
            GROUP BY f.id
        ");
        $stmt->execute([$factureId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        return (float)$row['total_paye'] >= (float)$row['montant_ttc'];
    }

    /**
     * Met à jour le statut d'une facture après un paiement.
     * Ne met jamais à jour vers "payee" : le statut reste en_attente/en_cours/en_retard selon la date.
     * L'affichage "Payé" est calculé côté liste à partir des paiements validés.
     */
    public function updateFactureStatutAfterPayment(int $factureId): void
    {
        // Ne pas passer en payee : on garde le statut basé sur la date (en_attente, en_cours, en_retard)
        if ($this->isFactureFullyPaid($factureId)) {
            return;
        }
        $stmt = $this->pdo->prepare("SELECT date_facture, statut FROM factures WHERE id = ?");
        $stmt->execute([$factureId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return;
        }
        // Ne pas écraser "envoyee" : une facture envoyée au client reste envoyee jusqu'à paiement
        if (($row['statut'] ?? '') === 'envoyee') {
            return;
        }
        $newStatut = $this->computeStatutFromDate($row['date_facture']);
        $this->pdo->prepare("UPDATE factures SET statut = ? WHERE id = ?")->execute([$newStatut, $factureId]);
    }
}
