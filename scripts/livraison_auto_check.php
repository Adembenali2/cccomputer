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
        // [Livraison Auto] Verification papier (nouveau stock)
        if ((int)$config['papier_actif'] === 1) {
            $stmtStock = $pdo->prepare("
                SELECT COALESCE(SUM(quantite),0) FROM stock
                WHERE actif = 1 AND categorie='papier'
            ");
            $stmtStock->execute();
            $stock = (int)($stmtStock->fetchColumn() ?: 0);
            $seuil = (int)$config['papier_seuil'];

            if ($stock <= $seuil) {
                $stmtCheck = $pdo->prepare("
                    SELECT COUNT(*) FROM livraisons
                            WHERE id_client=? AND product_type='papier'
                    AND statut IN ('planifiee','en_cours')
                    AND commentaire LIKE '%[AUTO]%'
                ");
                $stmtCheck->execute([$clientId]);
                $existante = (int)$stmtCheck->fetchColumn();
                if ($existante === 0) {
                    if ((int)$config['papier_qte_auto'] === 1) {
                        $qte = max(1, (int)$config['papier_qte_livraison']);
                    } else {
                        $qte = max(1, (int)$config['papier_qte_livraison']);
                    }

                    $ref = 'LIV-AUTO-' . date('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));
                    $adresse = !empty($config['adresse_livraison']) ? $config['adresse_livraison'] : $config['adresse'];
                    $datePrevue = date('Y-m-d', strtotime('+1 day'));
                    $stmtInsert = $pdo->prepare("
                        INSERT INTO livraisons
                        (id_client, reference, adresse_livraison, objet, date_prevue, statut, product_type, product_id, product_qty, commentaire)
                        VALUES (?, ?, ?, ?, ?, 'planifiee', 'papier', NULL, ?, ?)
                    ");
                    $stmtInsert->execute([
                        $clientId,
                        $ref,
                        $adresse,
                        "Reapprovisionnement papier automatique ({$qte} ramettes)",
                        $datePrevue,
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

        // [Livraison Auto] Verification toners (nouveau stock)
        if ((int)$config['toner_actif'] === 1) {
            $stmtToners = $pdo->prepare("
                SELECT id, categorie, designation, quantite, quantite_min
                FROM stock
                WHERE actif = 1
                  AND categorie IN ('toner_noir','toner_cyan','toner_magenta','toner_jaune')
                  AND quantite <= quantite_min
            ");
            $stmtToners->execute();
            $tonersEnAlerte = $stmtToners->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($tonersEnAlerte as $toner) {
                    $label = str_replace('toner_', '', (string)$toner['categorie']);
                    $label = ucfirst($label);
                        $stmtCheckT = $pdo->prepare("
                            SELECT COUNT(*) FROM livraisons
                            WHERE id_client=? AND statut IN ('planifiee','en_cours')
                            AND commentaire LIKE ?
                        ");
                        $likeSearch = '%[AUTO] toner ' . $label . '%';
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
                                "Remplacement toner {$label} automatique",
                                date('Y-m-d', strtotime('+1 day')),
                                "[AUTO] toner {$label} en alerte stock",
                            ]);
                            $livIdT = (int)$pdo->lastInsertId();
                            $pdo->prepare("
                                INSERT INTO livraison_auto_log (id_client, type, declencheur, id_livraison_creee)
                                VALUES (?, ?, ?, ?)
                            ")->execute([$clientId, 'toner_' . strtolower($label), "stock toner en alerte", $livIdT]);
                            $results['created'][] = ['client_id' => $clientId, 'type' => 'toner_' . strtolower($label), 'livraison_id' => $livIdT, 'ref' => $ref];
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
