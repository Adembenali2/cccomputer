<?php
declare(strict_types=1);

// [Livraison Auto]
require_once __DIR__ . '/../../includes/api_helpers.php';

initApi();
requireApiAuth();
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$idClientFilter = (int)($_GET['id_client'] ?? 0);

try {
    $pdo = getPdo();
    $sql = "
      SELECT c.id AS id_client, c.raison_sociale, COALESCE(lac.actif,0) AS actif,
             COALESCE(lac.papier_seuil,5) AS seuil_papier,
             COALESCE(lac.toner_seuil_pct,10) AS seuil_toner,
             lac.derniere_verification
      FROM clients c
      LEFT JOIN livraison_auto_config lac ON lac.id_client = c.id
    ";
    $params = [];
    if ($idClientFilter > 0) {
        $sql .= " WHERE c.id = ?";
        $params[] = $idClientFilter;
    }
    $sql .= " ORDER BY c.raison_sociale ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach ($clients as $c) {
        $idClient = (int)$c['id_client'];
        $stockStmt = $pdo->prepare("SELECT COALESCE(SUM(qty_stock),0) FROM client_stock WHERE id_client = ? AND product_type='papier'");
        $stockStmt->execute([$idClient]);
        $stockPapier = (int)$stockStmt->fetchColumn();

        $avgStmt = $pdo->prepare("
          SELECT ROUND(
            COALESCE((MAX(cr.TotalBW) - MIN(cr.TotalBW)) / NULLIF(DATEDIFF(MAX(cr.Timestamp), MIN(cr.Timestamp)), 0), 0), 1
          ) AS avg_bw_per_day
          FROM photocopieurs_clients pc
          JOIN (
            SELECT mac_norm, TotalBW, Timestamp FROM compteur_relevee WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            UNION ALL
            SELECT mac_norm, TotalBW, Timestamp FROM compteur_relevee_ancien WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
          ) cr ON cr.mac_norm = pc.mac_norm
          WHERE pc.id_client = ?
        ");
        $avgStmt->execute([$idClient]);
        $avgBw = (float)($avgStmt->fetchColumn() ?: 0);
        $joursRestants = $avgBw > 0 ? round(($stockPapier * 500) / $avgBw, 1) : null;
        $qteReco = $avgBw > 0 ? max(1, (int)ceil($avgBw * 7 / 500)) : null;

        $tonersStmt = $pdo->prepare("
          SELECT x.TonerBlack, x.TonerCyan, x.TonerMagenta, x.TonerYellow
          FROM photocopieurs_clients pc
          JOIN (
            SELECT z.mac_norm, z.TonerBlack, z.TonerCyan, z.TonerMagenta, z.TonerYellow
            FROM (
              SELECT mac_norm, TonerBlack, TonerCyan, TonerMagenta, TonerYellow, Timestamp,
                     ROW_NUMBER() OVER (PARTITION BY mac_norm ORDER BY Timestamp DESC) rn
              FROM compteur_relevee WHERE mac_norm IS NOT NULL
            ) z WHERE z.rn=1
          ) x ON x.mac_norm = pc.mac_norm
          WHERE pc.id_client = ?
          LIMIT 1
        ");
        $tonersStmt->execute([$idClient]);
        $t = $tonersStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $toners = [
            'black' => isset($t['TonerBlack']) ? (int)$t['TonerBlack'] : 0,
            'cyan' => isset($t['TonerCyan']) ? (int)$t['TonerCyan'] : 0,
            'magenta' => isset($t['TonerMagenta']) ? (int)$t['TonerMagenta'] : 0,
            'yellow' => isset($t['TonerYellow']) ? (int)$t['TonerYellow'] : 0,
        ];

        $alertes = [];
        if ($stockPapier <= (int)$c['seuil_papier']) {
            $alertes[] = 'papier';
        }
        if ($toners['black'] > 0 && $toners['black'] <= (int)$c['seuil_toner']) $alertes[] = 'toner_black';
        if ($toners['cyan'] > 0 && $toners['cyan'] <= (int)$c['seuil_toner']) $alertes[] = 'toner_cyan';
        if ($toners['magenta'] > 0 && $toners['magenta'] <= (int)$c['seuil_toner']) $alertes[] = 'toner_magenta';
        if ($toners['yellow'] > 0 && $toners['yellow'] <= (int)$c['seuil_toner']) $alertes[] = 'toner_yellow';

        $out[] = [
            'id_client' => $idClient,
            'raison_sociale' => $c['raison_sociale'],
            'actif' => ((int)$c['actif'] === 1),
            'stock_papier' => $stockPapier,
            'seuil_papier' => (int)$c['seuil_papier'],
            'jours_restants' => $joursRestants,
            'avg_bw_per_day' => $avgBw,
            'qte_recommandee' => $qteReco,
            'toners' => $toners,
            'seuil_toner' => (int)$c['seuil_toner'],
            'alertes' => $alertes,
            'derniere_verification' => $c['derniere_verification'] ?? null,
        ];
    }

    jsonResponse(['success' => true, 'clients' => $out]);
} catch (Throwable $e) {
    error_log('auto_stats.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
