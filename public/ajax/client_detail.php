<?php
// /public/ajax/client_detail.php

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db.php';

// En-têtes JSON
header('Content-Type: application/json; charset=utf-8');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre id manquant']);
    exit;
}

$clientId = (int)$_GET['id'];
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Paramètre id invalide']);
    exit;
}

try {
    // 1) Récup client (toutes colonnes)
    $sqlClient = "SELECT 
        `id`, `numero_client`, `raison_sociale`, `adresse`, `code_postal`, `ville`,
        `adresse_livraison`, `livraison_identique`, `siret`, `numero_tva`, `depot_mode`,
        `nom_dirigeant`, `prenom_dirigeant`, `telephone1`, `telephone2`, `email`,
        `parrain`, `offre`, `date_creation`, `date_dajout`,
        `pdf1`, `pdf2`, `pdf3`, `pdf4`, `pdf5`, `pdfcontrat`, `iban`
    FROM clients
    WHERE id = :id
    LIMIT 1";
    $st = $pdo->prepare($sqlClient);
    $st->execute([':id' => $clientId]);
    $client = $st->fetch(PDO::FETCH_ASSOC);

    if (!$client) {
        http_response_code(404);
        echo json_encode(['error' => 'Client introuvable']);
        exit;
    }

    // 2) Périphériques liés au client
    $sqlPc = "SELECT id, SerialNumber, MacAddress, mac_norm
              FROM photocopieurs_clients
              WHERE id_client = :id";
    $st2 = $pdo->prepare($sqlPc);
    $st2->execute([':id' => $clientId]);
    $pcs = $st2->fetchAll(PDO::FETCH_ASSOC);

    $devices = [];

    if ($pcs && count($pcs) > 0) {
        // On récupère la dernière relève pour chaque mac_norm en une seule requête
        $macs = array_filter(array_map(function($r){ return $r['mac_norm']; }, $pcs));
        $macs = array_values(array_unique($macs));

        if (count($macs) > 0) {
            // Construction du IN sécurisé
            $in = implode(',', array_fill(0, count($macs), '?'));

            // Sous-requête pour max Timestamp par mac_norm
            $sqlLatest = "
                SELECT cr.*
                FROM compteur_relevee cr
                INNER JOIN (
                    SELECT mac_norm, MAX(`Timestamp`) AS max_ts
                    FROM compteur_relevee
                    WHERE mac_norm IN ($in)
                    GROUP BY mac_norm
                ) t
                ON cr.mac_norm = t.mac_norm AND cr.`Timestamp` = t.max_ts
            ";
            $st3 = $pdo->prepare($sqlLatest);
            $st3->execute($macs);
            $rows = $st3->fetchAll(PDO::FETCH_ASSOC);

            // Index par mac_norm pour accès rapide
            $byMac = [];
            foreach ($rows as $r) {
                $byMac[$r['mac_norm']] = $r;
            }

            // Construction du tableau final devices
            foreach ($pcs as $pc) {
                $macn = $pc['mac_norm'];
                $last = $byMac[$macn] ?? null;

                $devices[] = [
                    'SerialNumber'  => $pc['SerialNumber'],
                    'MacAddress'    => $pc['MacAddress'],
                    'Model'         => $last['Model'] ?? null,
                    'Status'        => $last['Status'] ?? null,
                    'TonerBlack'    => isset($last['TonerBlack'])   ? (int)$last['TonerBlack']   : null,
                    'TonerCyan'     => isset($last['TonerCyan'])    ? (int)$last['TonerCyan']    : null,
                    'TonerMagenta'  => isset($last['TonerMagenta']) ? (int)$last['TonerMagenta'] : null,
                    'TonerYellow'   => isset($last['TonerYellow'])  ? (int)$last['TonerYellow']  : null,
                    'TotalBW'       => isset($last['TotalBW'])      ? (int)$last['TotalBW']      : null,
                    'TotalColor'    => isset($last['TotalColor'])   ? (int)$last['TotalColor']   : null,
                    'Timestamp'     => $last['Timestamp'] ?? null,
                ];
            }
        } else {
            // Pas de mac_norm -> on renvoie juste la liste brute
            foreach ($pcs as $pc) {
                $devices[] = [
                    'SerialNumber'  => $pc['SerialNumber'],
                    'MacAddress'    => $pc['MacAddress'],
                    'Model'         => null,
                    'Status'        => null,
                    'TonerBlack'    => null,
                    'TonerCyan'     => null,
                    'TonerMagenta'  => null,
                    'TonerYellow'   => null,
                    'TotalBW'       => null,
                    'TotalColor'    => null,
                    'Timestamp'     => null,
                ];
            }
        }
    }

    echo json_encode([
        'client'  => $client,
        'devices' => $devices
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Erreur SQL', 'detail' => $e->getMessage()]);
    exit;
}
