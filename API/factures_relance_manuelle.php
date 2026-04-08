<?php
declare(strict_types=1);

// [Fonctionnalité C]
require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/historique.php';
require_once __DIR__ . '/../vendor/autoload.php';

initApi();
requireApiAuth();
apiRequireEmploi(['Admin', 'Dirigeant']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Methode non autorisee'], 405);
}
requireCsrfForApi();

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
if (!is_array($data)) {
    $data = $_POST;
}

$idFacture = (int)($data['id_facture'] ?? 0);
$numeroRelance = (int)($data['numero_relance'] ?? 1);
if ($idFacture <= 0 || !in_array($numeroRelance, [1, 2, 3], true)) {
    jsonResponse(['ok' => false, 'error' => 'Parametres invalides'], 400);
}

$pdo = getPdoOrFail();
$st = $pdo->prepare("
    SELECT f.id, f.numero, f.montant_ttc, f.date_facture, c.email, c.prenom_dirigeant
    FROM factures f
    JOIN clients c ON c.id = f.id_client
    WHERE f.id = ? AND f.statut IN ('envoyee','en_retard') LIMIT 1
");
$st->execute([$idFacture]);
$facture = $st->fetch(PDO::FETCH_ASSOC);
if (!$facture || empty($facture['email'])) {
    jsonResponse(['ok' => false, 'error' => 'Facture non eligible a la relance'], 400);
}

$regles = [
    1 => ['sujet' => 'Rappel - Facture %s en attente de reglement'],
    2 => ['sujet' => 'Deuxieme relance - Facture %s impayee'],
    3 => ['sujet' => 'MISE EN DEMEURE - Facture %s'],
];
$sujet = sprintf($regles[$numeroRelance]['sujet'], $facture['numero']);
$couleur = $numeroRelance === 3 ? '#dc2626' : '#f59e0b';
$niveauLabel = $numeroRelance === 3 ? 'MISE EN DEMEURE' : "Relance n°{$numeroRelance}";
$montantFormate = number_format((float)$facture['montant_ttc'], 2, ',', ' ') . ' €';
$prenom = (string)($facture['prenom_dirigeant'] ?? '');
$htmlBody = "<html><body><div style='border-top:4px solid {$couleur};padding:16px;font-family:Arial'><h2>{$niveauLabel}</h2><p>Bonjour {$prenom},</p><p>Facture <strong>{$facture['numero']}</strong> de <strong>{$montantFormate} TTC</strong> impayee.</p></div></body></html>";

$appConfig = require __DIR__ . '/../config/app.php';
$emailConfig = $appConfig['email'] ?? [];
$useBrevo = !empty($_ENV['BREVO_API_KEY']) || !empty(getenv('BREVO_API_KEY'));
if ($useBrevo) {
    $mailer = new \App\Mail\BrevoApiMailerService();
    $mailer->sendEmailWithPdf($facture['email'], $sujet, strip_tags($htmlBody), $htmlBody, null, null);
} else {
    $mailer = new \App\Mail\MailerService($emailConfig);
    $mailer->sendEmail($facture['email'], $sujet, strip_tags($htmlBody), $htmlBody);
}

$pdo->prepare("INSERT INTO facture_relances (id_facture, numero_relance, envoye_par, email_destinataire, statut) VALUES (?, ?, ?, ?, 'envoye')")
    ->execute([$idFacture, $numeroRelance, (int)currentUserId(), $facture['email']]);
$pdo->prepare("UPDATE factures SET nb_relances = GREATEST(nb_relances, ?), date_derniere_relance = NOW(), statut = IF(? >= 2, 'en_retard', statut) WHERE id = ?")
    ->execute([$numeroRelance, $numeroRelance, $idFacture]);
enregistrerAction($pdo, currentUserId(), 'facture_relance_envoyee', "Relance n°{$numeroRelance} envoyee pour facture {$facture['numero']}");

jsonResponse(['success' => true, 'relance' => $numeroRelance]);
