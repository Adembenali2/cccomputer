<?php
declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use PDO;
use Throwable;

/**
 * Génère les factures pour les lignes actives dont prochaine_echeance <= aujourd'hui.
 */
final class RecurringInvoiceService
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array{created:int, skipped:int, errors:int, log: list<array>}
     */
    public function processDue(?int $systemUserId = null): array
    {
        require_once __DIR__ . '/../../includes/parametres.php';
        if (!ProductTier::canUseFeature($this->pdo, 'module_factures_recurrentes')) {
            return ['created' => 0, 'skipped' => 0, 'errors' => 0, 'log' => [['info' => 'module_factures_recurrentes désactivé']]];
        }

        require_once __DIR__ . '/../../API/factures_generer.php';
        require_once __DIR__ . '/../../includes/historique.php';

        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        $st = $this->pdo->prepare("
            SELECT fr.*, c.raison_sociale
            FROM factures_recurrentes fr
            INNER JOIN clients c ON c.id = fr.id_client
            WHERE fr.actif = 1 AND fr.prochaine_echeance <= ?
            ORDER BY fr.id ASC
        ");
        $st->execute([$today]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $created = 0;
        $skipped = 0;
        $errors = 0;
        $log = [];

        foreach ($rows as $rec) {
            $rid = (int)$rec['id'];
            $clientId = (int)$rec['id_client'];

            $stmtC = $this->pdo->prepare("SELECT * FROM clients WHERE id = ? LIMIT 1");
            $stmtC->execute([$clientId]);
            $client = $stmtC->fetch(PDO::FETCH_ASSOC);
            if (!$client) {
                $skipped++;
                $log[] = ['recurring_id' => $rid, 'skip' => 'client introuvable'];
                continue;
            }

            $dup = $this->pdo->prepare("
                SELECT COUNT(*) FROM factures f
                WHERE f.id_client = ? AND f.date_facture = CURDATE()
                AND f.type = ? AND ABS(f.montant_ht - ?) < 0.01
                AND EXISTS (
                    SELECT 1 FROM facture_lignes l
                    WHERE l.id_facture = f.id AND l.description = ? LIMIT 1
                )
            ");
            $dup->execute([
                $clientId,
                $rec['type_facture'],
                (float)$rec['montant_ht'],
                (string)$rec['description_ligne'],
            ]);
            if ((int)$dup->fetchColumn() > 0) {
                $skipped++;
                $this->advanceSchedule($rid, (string)$rec['frequence'], (int)$rec['jour_mois'], $today);
                $log[] = ['recurring_id' => $rid, 'skip' => 'doublon même jour'];
                continue;
            }

            $montantHt = round((float)$rec['montant_ht'], 2);
            $tvaPct = (float)$rec['tva_pct'];
            if ($tvaPct < 0) {
                $tvaPct = 20.0;
            }
            $tva = round($montantHt * ($tvaPct / 100.0), 2);
            $montantTtc = round($montantHt + $tva, 2);
            $factureType = (string)$rec['type_facture'];
            $ligneType = (string)$rec['ligne_type'];

            try {
                $this->pdo->beginTransaction();

                $numero = generateFactureNumber($this->pdo, $factureType);

                $insF = $this->pdo->prepare("
                    INSERT INTO factures (id_client, numero, date_facture, type, montant_ht, tva, montant_ttc, statut, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'en_attente', ?)
                ");
                $insF->execute([
                    $clientId,
                    $numero,
                    $today,
                    $factureType,
                    $montantHt,
                    $tva,
                    $montantTtc,
                    $systemUserId,
                ]);
                $factureId = (int)$this->pdo->lastInsertId();

                $insL = $this->pdo->prepare("
                    INSERT INTO facture_lignes (id_facture, description, type, quantite, prix_unitaire_ht, total_ht, ordre)
                    VALUES (?, ?, ?, 1, ?, ?, 0)
                ");
                $insL->execute([
                    $factureId,
                    (string)$rec['description_ligne'],
                    $ligneType,
                    $montantHt,
                    $montantHt,
                ]);

                $stub = ['lignes' => [], 'factureDate' => $today];
                $pdfWebPath = generateFacturePDF($this->pdo, $factureId, $client, $stub);
                $this->pdo->prepare("UPDATE factures SET pdf_genere = 1, pdf_path = ? WHERE id = ?")
                    ->execute([$pdfWebPath, $factureId]);

                $next = $this->computeNextEcheance((string)$rec['frequence'], (int)$rec['jour_mois'], $today);
                $this->pdo->prepare("
                    UPDATE factures_recurrentes
                    SET derniere_facture_id = ?, prochaine_echeance = ?, updated_at = NOW()
                    WHERE id = ?
                ")->execute([$factureId, $next, $rid]);

                $this->pdo->commit();
                $created++;

                $detail = sprintf('Récurrent #%d → facture %s client %s', $rid, $numero, $client['raison_sociale'] ?? $clientId);
                enregistrerAction(
                    $this->pdo,
                    $systemUserId,
                    'facture_recurrente_generee',
                    $detail,
                    $systemUserId === null ? getServerIp() : null
                );
                $log[] = ['recurring_id' => $rid, 'facture_id' => $factureId, 'numero' => $numero];
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                $errors++;
                error_log('[RecurringInvoiceService] ' . $e->getMessage());
                $log[] = ['recurring_id' => $rid, 'error' => $e->getMessage()];
            }
        }

        return ['created' => $created, 'skipped' => $skipped, 'errors' => $errors, 'log' => $log];
    }

    private function advanceSchedule(int $recurringId, string $frequence, int $jourMois, string $fromDate): void
    {
        $next = $this->computeNextEcheance($frequence, $jourMois, $fromDate);
        $this->pdo->prepare("UPDATE factures_recurrentes SET prochaine_echeance = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$next, $recurringId]);
    }

    private function computeNextEcheance(string $frequence, int $jourMois, string $fromDate): string
    {
        $jourMois = max(1, min(28, $jourMois));
        $d = new DateTimeImmutable($fromDate);
        $d = match ($frequence) {
            'trimestriel' => $d->modify('+3 months'),
            'annuel' => $d->modify('+1 year'),
            default => $d->modify('+1 month'),
        };
        $y = (int)$d->format('Y');
        $m = (int)$d->format('m');
        $last = (int)$d->format('t');
        $day = min($jourMois, $last);
        return sprintf('%04d-%02d-%02d', $y, $m, $day);
    }
}
