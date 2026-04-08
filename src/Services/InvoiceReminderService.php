<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use Throwable;

/**
 * Relances email progressives pour factures impayées après échéance (25 du mois de date_facture).
 * Anti-doublon : table facture_relances (unique facture_id + niveau).
 */
final class InvoiceReminderService
{
    public function __construct(private PDO $pdo, private array $appConfig)
    {
    }

    /**
     * @return array{sent:int, skipped:int, errors:int, details: list<array>}
     */
    public function run(?int $systemUserId = null): array
    {
        require_once __DIR__ . '/../../includes/historique.php';
        require_once __DIR__ . '/../../includes/parametres.php';
        if (!ProductTier::canUseFeature($this->pdo, 'module_relances_auto')) {
            return ['sent' => 0, 'skipped' => 0, 'errors' => 0, 'details' => [['info' => 'module_relances_auto désactivé ou offre standard']]];
        }

        $d1 = max(1, getParametreInt($this->pdo, 'relance_jours_1', 7));
        $d2 = max($d1, getParametreInt($this->pdo, 'relance_jours_2', 14));
        $d3 = max($d2, getParametreInt($this->pdo, 'relance_jours_3', 30));
        $seuils = [1 => $d1, 2 => $d2, 3 => $d3];

        $sql = "
            SELECT f.id, f.numero, f.date_facture, f.montant_ttc,
                   c.email AS client_email, c.raison_sociale,
                   CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25') AS echeance,
                   DATEDIFF(CURDATE(), CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25')) AS jours_retard,
                   COALESCE(p.total_paye, 0) AS paye
            FROM factures f
            INNER JOIN clients c ON c.id = f.id_client
            LEFT JOIN (
                SELECT id_facture, SUM(montant) AS total_paye
                FROM paiements WHERE statut = 'recu' GROUP BY id_facture
            ) p ON p.id_facture = f.id
            WHERE f.statut != 'annulee'
            AND COALESCE(p.total_paye, 0) < f.montant_ttc
            AND CONCAT(YEAR(f.date_facture), '-', LPAD(MONTH(f.date_facture), 2, '0'), '-25') < CURDATE()
        ";

        $rows = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $invoiceService = new InvoiceEmailService($this->pdo, $this->appConfig);

        $sent = 0;
        $skipped = 0;
        $errors = 0;
        $details = [];

        foreach ($rows as $row) {
            $factureId = (int)$row['id'];
            $jours = (int)$row['jours_retard'];
            $sentLevels = $this->getSentLevels($factureId);
            $niveau = $this->pickNiveau($jours, $seuils, $sentLevels);
            if ($niveau === 0) {
                $skipped++;
                continue;
            }

            $email = trim((string)($row['client_email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $details[] = ['facture_id' => $factureId, 'skip' => 'email invalide'];
                continue;
            }

            [$sujet, $message] = $this->buildReminderCopy($row, $niveau);

            try {
                $this->pdo->beginTransaction();
                $ins = $this->pdo->prepare("
                    INSERT INTO facture_relances (facture_id, niveau, destinataire, sent_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $ins->execute([$factureId, $niveau, $email]);
                if ($ins->rowCount() === 0) {
                    $this->pdo->rollBack();
                    $skipped++;
                    continue;
                }
                $this->pdo->commit();
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                if (str_contains($e->getMessage(), 'Duplicate') || $e->getCode() == 23000) {
                    $skipped++;
                    continue;
                }
                $errors++;
                $details[] = ['facture_id' => $factureId, 'error' => $e->getMessage()];
                continue;
            }

            try {
                $res = $invoiceService->sendInvoiceToEmail($factureId, null, $sujet, $message);
                if ($res['success'] ?? false) {
                    $sent++;
                    enregistrerAction(
                        $this->pdo,
                        $systemUserId,
                        'facture_relance_envoyee',
                        "Facture #{$row['numero']} niveau {$niveau} → {$email}",
                        $systemUserId === null ? getServerIp() : null
                    );
                    $details[] = ['facture_id' => $factureId, 'niveau' => $niveau, 'ok' => true];
                } else {
                    $errors++;
                    $this->pdo->prepare("DELETE FROM facture_relances WHERE facture_id = ? AND niveau = ?")->execute([$factureId, $niveau]);
                    $details[] = ['facture_id' => $factureId, 'error' => $res['message'] ?? 'envoi refusé'];
                }
            } catch (Throwable $e) {
                $errors++;
                $this->pdo->prepare("DELETE FROM facture_relances WHERE facture_id = ? AND niveau = ?")->execute([$factureId, $niveau]);
                $details[] = ['facture_id' => $factureId, 'error' => $e->getMessage()];
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'errors' => $errors, 'details' => $details];
    }

    /** @param list<int> $sentLevels */
    private function pickNiveau(int $joursRetard, array $seuils, array $sentLevels): int
    {
        $chosen = 0;
        for ($k = 1; $k <= 3; $k++) {
            if ($joursRetard >= ($seuils[$k] ?? 999) && !in_array($k, $sentLevels, true)) {
                $chosen = $k;
            }
        }
        return $chosen;
    }

    /** @return list<int> */
    private function getSentLevels(int $factureId): array
    {
        $st = $this->pdo->prepare("SELECT niveau FROM facture_relances WHERE facture_id = ?");
        $st->execute([$factureId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:string,1:string}
     */
    private function buildReminderCopy(array $row, int $niveau): array
    {
        $numero = (string)($row['numero'] ?? '');
        $ttc = number_format((float)($row['montant_ttc'] ?? 0), 2, ',', ' ') . ' € TTC';
        $base = "Facture {$numero} — montant {$ttc} — échéance dépassée.";

        if ($niveau === 1) {
            $sujet = "Rappel amiable — facture {$numero}";
            $msg = "Bonjour,\n\nNous nous permettons de vous rappeler que la facture {$numero} ({$ttc}) n’a pas encore été réglée.\n\nSi le paiement est déjà parti, merci d’ignorer ce message.\n\n{$base}\n\nCordialement";
        } elseif ($niveau === 2) {
            $sujet = "Relance — facture {$numero} en attente de règlement";
            $msg = "Bonjour,\n\nMalgré notre précédent rappel, nous n’avons pas enregistré de règlement pour la facture {$numero} ({$ttc}).\n\nMerci de régulariser sous 8 jours ou de nous contacter pour convenir d’un échéancier.\n\n{$base}\n\nCordialement";
        } else {
            $sujet = "URGENT — facture {$numero} — régularisation nécessaire";
            $msg = "Bonjour,\n\nLa facture {$numero} demeure impayée ({$ttc}). Sans régularisation rapide, nous serons contraints d’engager les suites habituelles.\n\nMerci de traiter cette demande en priorité ou de nous contacter sans délai.\n\n{$base}\n\nCordialement";
        }

        return [$sujet, $msg];
    }
}
