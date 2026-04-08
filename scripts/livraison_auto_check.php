<?php
// [Livraison Auto] Script de verification des livraisons automatiques
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/historique.php';

$pdo = getPdo();
$results = ['created' => [], 'skipped' => [], 'errors' => []];

try {
    $stmt = $pdo->query("
        SELECT lac.*, c.raison_sociale, c.adresse_livraison, c.adresse, c.id as client_id
        FROM livraison_auto_config lac
        JOIN clients c ON c.id = lac.id_client
        WHERE lac.actif = 1
    ");
    $configs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    return ['created' => [], 'skipped' => [], 'errors' => [['global' => $e->getMessage()]]];
}

foreach ($configs as $config) {
    $clientId = (int)$config['client_id'];
    try {
        // [Livraison Auto] Verification papier
        if ((int)$config['papier_actif'] === 1 && !empty($config['papier_product_id'])) {
            $stmtStock = $pdo->prepare("
                SELECT qty_stock FROM client_stock
                WHERE id_client=? AND product_type='papier' AND product_id=?
            ");
            $stmtStock->execute([$clientId, (int)$config['papier_product_id']]);
            $stock = (int)($stmtStock->fetchColumn() ?: 0);
            $seuil = (int)$config['papier_seuil'];

            if ($stock <= $seuil) {
                $stmtCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM livraisons
                    WHERE id_client=? AND product_type='papier' AND product_id=?
                    AND statut IN ('planifiee','en_cours')
                    AND commentaire LIKE '%[AUTO]%'
                ");
                $stmtCheck->execute([$clientId, (int)$config['papier_product_id']]);
                $existante = (int)$stmtCheck->fetchColumn();
                if ($existante === 0) {
                    if ((int)$config['papier_qte_auto'] === 1) {
                        $stmtConso = $pdo->prepare("
                            SELECT ROUND(
                              COALESCE((MAX(cr.TotalBW) - MIN(cr.TotalBW)) / NULLIF(DATEDIFF(MAX(cr.Timestamp), MIN(cr.Timestamp)), 0), 0), 1
                            ) AS avg_bw
                            FROM photocopieurs_clients pc
                            JOIN (
                              SELECT mac_norm, TotalBW, Timestamp FROM compteur_relevee
                              WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              UNION ALL
                              SELECT mac_norm, TotalBW, Timestamp FROM compteur_relevee_ancien
                              WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                            ) cr ON cr.mac_norm = pc.mac_norm
                            WHERE pc.id_client = ?
                        ");
                        $stmtConso->execute([$clientId]);
                        $avgBw = (float)($stmtConso->fetchColumn() ?: 0);
                        $qte = $avgBw > 0 ? max(1, (int)ceil($avgBw * 7 / 500)) : (int)$config['papier_qte_livraison'];
                    } else {
                        $qte = max(1, (int)$config['papier_qte_livraison']);
                    }

                    $ref = 'LIV-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));
                    $adresse = !empty($config['adresse_livraison']) ? $config['adresse_livraison'] : $config['adresse'];
                    $datePrevue = date('Y-m-d', strtotime('+1 day'));
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO livraisons
                        (id_client, reference, adresse_livraison, objet, date_prevue, statut, product_type, product_id, product_qty, commentaire)
                        VALUES (?, ?, ?, ?, ?, 'planifiee', 'papier', ?, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $clientId,
                        $ref,
                        $adresse,
                        "Reapprovisionnement papier automatique ({$qte} ramettes)",
                        $datePrevue,
                        (int)$config['papier_product_id'],
                        $qte,
                        "[AUTO] Stock={$stock} ramettes <= seuil={$seuil}",
                    ]);
                    $livraisonId = (int)$pdo->lastInsertId();
                    $pdo->prepare("
                        INSERT INTO livraison_auto_log (id_client, type, declencheur, id_livraison_creee)
                        VALUES (?, 'papier', ?, ?)
                    ")->execute([$clientId, "stock={$stock} <= seuil={$seuil}", $livraisonId]);
                    $pdo->prepare("
                        UPDATE livraison_auto_config
                        SET derniere_livraison_creee=NOW(), derniere_verification=NOW()
                        WHERE id_client=?
                    ")->execute([$clientId]);
                    enregistrerAction($pdo, 0, 'livraison_auto_creee', "Livraison auto papier #{$livraisonId} pour client #{$clientId}");
                    $results['created'][] = ['client_id' => $clientId, 'type' => 'papier', 'livraison_id' => $livraisonId, 'ref' => $ref];
                } else {
                    $results['skipped'][] = ['client_id' => $clientId, 'type' => 'papier', 'reason' => 'livraison_en_cours'];
                }
            }
        }

        // [Livraison Auto] Verification toners
        if ((int)$config['toner_actif'] === 1) {
            $seuilToner = (int)$config['toner_seuil_pct'];
            $stmtToners = $pdo->prepare("
                SELECT last_rel.mac_norm, last_rel.TonerBlack, last_rel.TonerCyan, last_rel.TonerMagenta, last_rel.TonerYellow
                FROM photocopieurs_clients pc
                JOIN (
                  SELECT x.mac_norm, x.TonerBlack, x.TonerCyan, x.TonerMagenta, x.TonerYellow
                  FROM (
                    SELECT mac_norm, TonerBlack, TonerCyan, TonerMagenta, TonerYellow, Timestamp,
                           ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) rn
                    FROM compteur_relevee
                    WHERE mac_norm IS NOT NULL
                  ) x WHERE x.rn = 1
                ) last_rel ON last_rel.mac_norm = pc.mac_norm
                WHERE pc.id_client = ?
            ");
            $stmtToners->execute([$clientId]);
            $machinesReleves = $stmtToners->fetchAll(PDO::FETCH_ASSOC) ?: [];
            $tonerTypes = [
                ['col' => 'TonerBlack', 'label' => 'Noir', 'type_log' => 'toner_black'],
                ['col' => 'TonerCyan', 'label' => 'Cyan', 'type_log' => 'toner_cyan'],
                ['col' => 'TonerMagenta', 'label' => 'Magenta', 'type_log' => 'toner_magenta'],
                ['col' => 'TonerYellow', 'label' => 'Jaune', 'type_log' => 'toner_yellow'],
            ];
            foreach ($machinesReleves as $releve) {
                foreach ($tonerTypes as $info) {
                    $pct = (int)($releve[$info['col']] ?? 0);
                    if ($pct > 0 && $pct <= $seuilToner) {
                        $stmtCheckT = $pdo->prepare("
                            SELECT COUNT(*) FROM livraisons
                            WHERE id_client=? AND statut IN ('planifiee','en_cours')
                            AND commentaire LIKE ?
                        ");
                        $likeSearch = '%[AUTO] toner ' . $info['label'] . '%';
                        $stmtCheckT->execute([$clientId, $likeSearch]);
                        if ((int)$stmtCheckT->fetchColumn() === 0) {
                            $ref = 'LIV-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));
                            $adresse = !empty($config['adresse_livraison']) ? $config['adresse_livraison'] : $config['adresse'];
                            $stmtInsT = $pdo->prepare("
                                INSERT INTO livraisons
                                (id_client, reference, adresse_livraison, objet, date_prevue, statut, product_type, commentaire)
                                VALUES (?, ?, ?, ?, ?, 'planifiee', 'toner', ?)
                            ");
                            $stmtInsT->execute([
                                $clientId,
                                $ref,
                                $adresse,
                                "Remplacement toner {$info['label']} automatique",
                                date('Y-m-d', strtotime('+1 day')),
                                "[AUTO] toner {$info['label']} = {$pct}% <= seuil={$seuilToner}%",
                            ]);
                            $livIdT = (int)$pdo->lastInsertId();
                            $pdo->prepare("
                                INSERT INTO livraison_auto_log (id_client, type, declencheur, id_livraison_creee)
                                VALUES (?, ?, ?, ?)
                            ")->execute([$clientId, $info['type_log'], "{$pct}% <= {$seuilToner}%", $livIdT]);
                            $results['created'][] = ['client_id' => $clientId, 'type' => $info['type_log'], 'livraison_id' => $livIdT, 'ref' => $ref];
                        }
                    }
                }
            }
            $pdo->prepare("UPDATE livraison_auto_config SET derniere_verification=NOW() WHERE id_client=?")->execute([$clientId]);
        }
    } catch (Throwable $e) {
        $results['errors'][] = ['client_id' => $clientId, 'error' => $e->getMessage()];
    }
}

$snapshot = ['at' => date('c'), 'results' => $results];
$json = json_encode($snapshot, JSON_UNESCAPED_UNICODE);
$pdo->prepare("
    INSERT INTO app_kv (k, v) VALUES ('livraison_auto_last_run', ?)
    ON DUPLICATE KEY UPDATE v=?
")->execute([$json, $json]);

return $results;
