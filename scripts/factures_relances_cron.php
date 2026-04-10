<?php
declare(strict_types=1);

// [Fonctionnalité C]
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';
require_once __DIR__ . '/../vendor/autoload.php';

$pdo = getPdo();

$regles = [
    1 => ['jours' => 15, 'sujet' => 'Rappel - Facture %s en attente de règlement'],
    2 => ['jours' => 30, 'sujet' => 'Deuxième relance - Facture %s impayée'],
    3 => ['jours' => 45, 'sujet' => 'MISE EN DEMEURE - Facture %s'],
];

$stmt = $pdo->query("
    SELECT f.id, f.numero, f.montant_ttc, f.date_facture, f.nb_relances, f.date_derniere_relance,
           c.email, c.prenom_dirigeant
    FROM factures f
    JOIN clients c ON c.id = f.id_client
    WHERE f.statut IN ('envoyee', 'en_retard') AND c.email IS NOT NULL AND c.email != '' AND f.nb_relances < 3
");
$factures = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$appConfig = require __DIR__ . '/../config/app.php';
$emailConfig = $appConfig['email'] ?? [];

foreach ($factures as $facture) {
    $prochaine = ((int)$facture['nb_relances']) + 1;
    if (!isset($regles[$prochaine])) {
        continue;
    }
    $joursDepuisFacture = (int)floor((time() - strtotime((string)$facture['date_facture'])) / 86400);
    if ($joursDepuisFacture < $regles[$prochaine]['jours']) {
        continue;
    }
    if (!empty($facture['date_derniere_relance'])) {
        $joursDepuisRelance = (int)floor((time() - strtotime((string)$facture['date_derniere_relance'])) / 86400);
        if ($joursDepuisRelance < 15) {
            continue;
        }
    }

    try {
        $sujet = sprintf($regles[$prochaine]['sujet'], $facture['numero']);
        $prenom = (string)($facture['prenom_dirigeant'] ?? '');
        $montantFormate = number_format((float)$facture['montant_ttc'], 2, ',', ' ') . ' €';
        $niveauLabel = $prochaine === 3 ? 'MISE EN DEMEURE' : "Relance n°{$prochaine}";
        $couleur = $prochaine === 3 ? '#dc2626' : '#f59e0b';
        $htmlBody = "<html><body style='font-family:Arial'><div style='border-top:4px solid {$couleur};padding:18px'><h2>{$niveauLabel}</h2><p>Bonjour {$prenom},</p><p>La facture <strong>{$facture['numero']}</strong> d'un montant de <strong>{$montantFormate} TTC</strong> reste impayée.</p></div></body></html>";

        // Pattern identique a livraison_auto_check.php: Brevo si cle presente, sinon MailerService.
        $useBrevo = !empty($_ENV['BREVO_API_KEY']) || !empty(getenv('BREVO_API_KEY'));
        if ($useBrevo) {
            $mailer = new \App\Mail\BrevoApiMailerService();
            $mailer->sendEmailWithPdf($facture['email'], $sujet, strip_tags($htmlBody), $htmlBody, null, null);
        } else {
            $mailer = new \App\Mail\MailerService($emailConfig);
            $mailer->sendEmail($facture['email'], $sujet, strip_tags($htmlBody), $htmlBody);
        }

        $pdo->prepare("INSERT INTO facture_relances (id_facture, numero_relance, email_destinataire, statut) VALUES (?, ?, ?, 'envoye')")
            ->execute([$facture['id'], $prochaine, $facture['email']]);
        $pdo->prepare("UPDATE factures SET nb_relances = nb_relances + 1, date_derniere_relance = NOW(), statut = IF(? >= 2, 'en_retard', statut) WHERE id = ?")
            ->execute([$prochaine, $facture['id']]);

        enregistrerAction($pdo, 0, 'facture_relance_envoyee', "Relance n°{$prochaine} envoyée pour facture {$facture['numero']}");
    } catch (\Throwable $e) {
        error_log('[relances_cron] Erreur facture ' . $facture['id'] . ': ' . $e->getMessage());
        $pdo->prepare("INSERT INTO facture_relances (id_facture, numero_relance, email_destinataire, statut) VALUES (?, ?, ?, 'echec')")
            ->execute([$facture['id'], $prochaine, $facture['email']]);
    }
}
