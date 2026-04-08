<?php
declare(strict_types=1);

// [Fonctionnalité E]
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

$pdo = getPdo();
$moisCourant = date('Y-m');
$jourActuel = (int)date('j');

$st = $pdo->query("
    SELECT fr.*, c.raison_sociale
    FROM factures_recurrentes fr
    JOIN clients c ON c.id = fr.id_client
    WHERE fr.actif = 1
");
$configs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($configs as $cfg) {
    if ($jourActuel < (int)$cfg['jour_generation']) {
        continue;
    }
    if (($cfg['mois_dernier_envoi'] ?? '') === $moisCourant) {
        continue;
    }

    $dateFacture = date('Y-m-d');
    $type = (string)$cfg['type'];
    $idClient = (int)$cfg['id_client'];
    $numero = 'R' . date('Ym') . '-' . str_pad((string)$idClient, 4, '0', STR_PAD_LEFT);
    $montantHt = 0.0;
    $tva = 0.0;
    $ttc = 0.0;

    if ($type === 'Consommation') {
        // Utilise la meme logique de base que les factures consommation: montant calcule depuis releves.
        $debut = date('Y-m-01', strtotime('first day of previous month'));
        $fin = date('Y-m-t', strtotime('last day of previous month'));

        $q = $pdo->prepare("
            SELECT COALESCE(SUM(GREATEST(x.fin_bw - x.debut_bw, 0) * 0.05 + GREATEST(x.fin_color - x.debut_color, 0) * 0.09), 0) AS ht
            FROM (
                SELECT pc.mac_norm,
                    (SELECT COALESCE(TotalBW,0) FROM compteur_relevee WHERE mac_norm = pc.mac_norm AND DATE(Timestamp) <= ? ORDER BY Timestamp ASC LIMIT 1) AS debut_bw,
                    (SELECT COALESCE(TotalColor,0) FROM compteur_relevee WHERE mac_norm = pc.mac_norm AND DATE(Timestamp) <= ? ORDER BY Timestamp ASC LIMIT 1) AS debut_color,
                    (SELECT COALESCE(TotalBW,0) FROM compteur_relevee WHERE mac_norm = pc.mac_norm AND DATE(Timestamp) <= ? ORDER BY Timestamp DESC LIMIT 1) AS fin_bw,
                    (SELECT COALESCE(TotalColor,0) FROM compteur_relevee WHERE mac_norm = pc.mac_norm AND DATE(Timestamp) <= ? ORDER BY Timestamp DESC LIMIT 1) AS fin_color
                FROM photocopieurs_clients pc
                WHERE pc.id_client = ?
            ) x
        ");
        $q->execute([$debut, $debut, $fin, $fin, $idClient]);
        $montantHt = (float)($q->fetchColumn() ?: 0);
    } else {
        $montantHt = (float)($cfg['montant_fixe'] ?? 0);
    }

    if ($montantHt <= 0) {
        continue;
    }
    $tva = round($montantHt * 0.20, 2);
    $ttc = round($montantHt + $tva, 2);

    $ins = $pdo->prepare("INSERT INTO factures (id_client, numero, date_facture, type, montant_ht, tva, montant_ttc, statut, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'brouillon', NULL, NOW(), NOW())");
    $ins->execute([$idClient, $numero, $dateFacture, $type, $montantHt, $tva, $ttc]);

    $pdo->prepare("UPDATE factures_recurrentes SET mois_dernier_envoi = ?, updated_at = NOW() WHERE id = ?")
        ->execute([$moisCourant, $cfg['id']]);
    enregistrerAction($pdo, 0, 'facture_recurrente_generee', "Facture recurrente generee client #{$idClient} ({$type})");
}
