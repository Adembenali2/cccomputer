<?php
declare(strict_types=1);
/**
 * API pour lister les programmations d'envoi
 * date_envoi_programmee stockée en UTC, retournée en ISO 8601 UTC pour affichage local côté frontend
 */

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

try {
    $pdo = getPdo();
    $config = require __DIR__ . '/../config/app.php';
    $appTz = new DateTimeZone($config['app_timezone'] ?? 'Europe/Paris');

    $stmt = $pdo->query("
        SELECT 
            fep.id, fep.type_envoi, fep.facture_id, fep.factures_json, fep.client_id,
            fep.email_destination, fep.use_client_email, fep.all_clients, fep.all_selected_factures,
            fep.sujet, fep.message, fep.date_envoi_programmee, fep.statut, fep.erreur_message,
            fep.created_at, fep.sent_at,
            f.numero as facture_numero, f.date_facture as facture_date, f.montant_ttc as facture_montant,
            c.raison_sociale as client_nom, c.email as client_email
        FROM factures_envois_programmes fep
        LEFT JOIN factures f ON fep.facture_id = f.id
        LEFT JOIN clients c ON fep.client_id = c.id OR f.id_client = c.id
        ORDER BY fep.date_envoi_programmee DESC, fep.id DESC
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $r) {
        $facturesInfo = [];
        if ($r['facture_id']) {
            $facturesInfo[] = [
                'id' => (int)$r['facture_id'],
                'numero' => $r['facture_numero'],
                'date' => $r['facture_date'],
                'montant_ttc' => $r['facture_montant']
            ];
        }
        if (!empty($r['factures_json'])) {
            $ids = json_decode($r['factures_json'], true);
            if (is_array($ids)) {
                foreach ($ids as $fid) {
                    $facturesInfo[] = ['id' => (int)$fid, 'numero' => null, 'date' => null, 'montant_ttc' => null];
                }
            }
        }
        $dest = $r['email_destination'] ?: ($r['use_client_email'] ? ($r['client_email'] ?? 'Client') : 'Email manuel');
        if ($r['all_clients']) {
            $dest = 'Tous les clients';
        }
        $dateUtc = $r['date_envoi_programmee'];
        $dateIsoUtc = null;
        $dateLocaleFormatted = null;
        if ($dateUtc) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateUtc, new DateTimeZone('UTC'));
            if ($dt) {
                $dateIsoUtc = $dt->format('Y-m-d\TH:i:s\Z');
                $dt->setTimezone($appTz);
                $dateLocaleFormatted = $dt->format('d/m/Y H:i');
            }
        }
        $result[] = [
            'id' => (int)$r['id'],
            'type_envoi' => $r['type_envoi'],
            'facture_id' => $r['facture_id'] ? (int)$r['facture_id'] : null,
            'factures_json' => $r['factures_json'],
            'factures_info' => $facturesInfo,
            'destinataire' => $dest,
            'sujet' => $r['sujet'],
            'message' => $r['message'],
            'date_envoi_programmee' => $dateIsoUtc ?: $dateUtc,
            'date_envoi_locale' => $dateLocaleFormatted ?: $dateUtc,
            'statut' => $r['statut'],
            'erreur_message' => $r['erreur_message'],
            'created_at' => $r['created_at'],
            'sent_at' => $r['sent_at'],
        ];
    }

    jsonResponse(['ok' => true, 'programmations' => $result]);
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'factures_envois_programmes') !== false) {
        jsonResponse(['ok' => false, 'error' => 'Table factures_envois_programmes manquante'], 500);
    }
    error_log('[factures_programmations_liste] ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur base de données'], 500);
}
