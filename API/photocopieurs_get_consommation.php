<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api_helpers.php';

// [Fonctionnalité consommation]
initApi();
requireApiAuth();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    jsonResponse(['success' => false, 'error' => 'Méthode non autorisée'], 405);
}

$macNorm = strtoupper(trim((string)($_GET['mac_norm'] ?? '')));
if (!preg_match('/^[0-9A-F]{12}$/', $macNorm)) {
    jsonResponse(['success' => false, 'error' => 'mac_norm invalide'], 400);
}

try {
    $pdo = getPdo();

    // [Fonctionnalité consommation] Requête 1 : premier relevé
    $stmtFirst = $pdo->prepare("
        SELECT TotalBW, TotalColor, TotalPages, Timestamp
        FROM (
          SELECT TotalBW, TotalColor, TotalPages, Timestamp FROM compteur_relevee WHERE mac_norm = :mac1
          UNION ALL
          SELECT TotalBW, TotalColor, TotalPages, Timestamp FROM compteur_relevee_ancien WHERE mac_norm = :mac2
        ) all_rel
        WHERE TotalBW IS NOT NULL
        ORDER BY Timestamp ASC
        LIMIT 1
    ");
    $stmtFirst->execute([':mac1' => $macNorm, ':mac2' => $macNorm]);
    $premierReleve = $stmtFirst->fetch(PDO::FETCH_ASSOC) ?: null;

    // [Fonctionnalité consommation] Requête 2 : dernier relevé
    $stmtLast = $pdo->prepare("
        SELECT TotalBW, TotalColor, TotalPages, Timestamp
        FROM (
          SELECT TotalBW, TotalColor, TotalPages, Timestamp FROM compteur_relevee WHERE mac_norm = :mac1
          UNION ALL
          SELECT TotalBW, TotalColor, TotalPages, Timestamp FROM compteur_relevee_ancien WHERE mac_norm = :mac2
        ) all_rel
        WHERE TotalBW IS NOT NULL
        ORDER BY Timestamp DESC
        LIMIT 1
    ");
    $stmtLast->execute([':mac1' => $macNorm, ':mac2' => $macNorm]);
    $dernierReleve = $stmtLast->fetch(PDO::FETCH_ASSOC) ?: null;

    // [Fonctionnalité consommation] Requête 3 : mois en cours
    $debutMois = date('Y-m-01 00:00:00');
    $stmtMoisFirst = $pdo->prepare("
        SELECT TotalBW, TotalColor, Timestamp
        FROM (
          SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee
          WHERE mac_norm = :mac1 AND Timestamp >= :debut1
          UNION ALL
          SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee_ancien
          WHERE mac_norm = :mac2 AND Timestamp >= :debut2
        ) all_rel
        WHERE TotalBW IS NOT NULL
        ORDER BY Timestamp ASC
        LIMIT 1
    ");
    $stmtMoisFirst->execute([
        ':mac1' => $macNorm, ':debut1' => $debutMois,
        ':mac2' => $macNorm, ':debut2' => $debutMois,
    ]);
    $premierReleveMois = $stmtMoisFirst->fetch(PDO::FETCH_ASSOC) ?: null;

    $stmtMoisLast = $pdo->prepare("
        SELECT TotalBW, TotalColor, Timestamp
        FROM (
          SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee
          WHERE mac_norm = :mac1 AND Timestamp <= NOW()
          UNION ALL
          SELECT TotalBW, TotalColor, Timestamp FROM compteur_relevee_ancien
          WHERE mac_norm = :mac2 AND Timestamp <= NOW()
        ) all_rel
        WHERE TotalBW IS NOT NULL
        ORDER BY Timestamp DESC
        LIMIT 1
    ");
    $stmtMoisLast->execute([':mac1' => $macNorm, ':mac2' => $macNorm]);
    $dernierReleveMois = $stmtMoisLast->fetch(PDO::FETCH_ASSOC) ?: null;

    // [Fonctionnalité consommation] Requête 4 : historique
    $stmtHisto = $pdo->prepare("
        SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
        FROM (
          SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
          FROM compteur_relevee WHERE mac_norm = :mac1
          UNION ALL
          SELECT Timestamp, TotalBW, TotalColor, TotalPages, TonerBlack, TonerCyan, TonerMagenta, TonerYellow
          FROM compteur_relevee_ancien WHERE mac_norm = :mac2
        ) all_rel
        WHERE TotalBW IS NOT NULL
        ORDER BY Timestamp DESC
        LIMIT 30
    ");
    $stmtHisto->execute([':mac1' => $macNorm, ':mac2' => $macNorm]);
    $historique = $stmtHisto->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $firstBw = (int)($premierReleve['TotalBW'] ?? 0);
    $firstColor = (int)($premierReleve['TotalColor'] ?? 0);
    $lastBw = (int)($dernierReleve['TotalBW'] ?? 0);
    $lastColor = (int)($dernierReleve['TotalColor'] ?? 0);

    $consommationTotale = [
        'bw' => max(0, $lastBw - $firstBw),
        'color' => max(0, $lastColor - $firstColor),
    ];
    $consommationMois = [
        'mois' => ['Janvier','Fevrier','Mars','Avril','Mai','Juin','Juillet','Aout','Septembre','Octobre','Novembre','Decembre'][(int)date('n') - 1] . ' ' . date('Y'),
        'bw' => max(0, (int)($dernierReleveMois['TotalBW'] ?? 0) - (int)($premierReleveMois['TotalBW'] ?? 0)),
        'color' => max(0, (int)($dernierReleveMois['TotalColor'] ?? 0) - (int)($premierReleveMois['TotalColor'] ?? 0)),
        'premier_releve_mois' => $premierReleveMois['Timestamp'] ?? null,
        'dernier_releve_mois' => $dernierReleveMois['Timestamp'] ?? null,
    ];

    foreach ($historique as $i => &$row) {
        $next = $historique[$i + 1] ?? null;
        $currentBw = (int)($row['TotalBW'] ?? 0);
        $currentColor = (int)($row['TotalColor'] ?? 0);
        $nextBw = (int)($next['TotalBW'] ?? 0);
        $nextColor = (int)($next['TotalColor'] ?? 0);
        $row = [
            'timestamp' => $row['Timestamp'] ?? null,
            'total_bw' => $currentBw,
            'total_color' => $currentColor,
            'delta_bw' => max(0, $currentBw - $nextBw),
            'delta_color' => max(0, $currentColor - $nextColor),
            'consommation_depuis_debut_bw' => max(0, $currentBw - $firstBw),
            'consommation_depuis_debut_color' => max(0, $currentColor - $firstColor),
            'toner_black' => isset($row['TonerBlack']) ? (int)$row['TonerBlack'] : null,
            'toner_cyan' => isset($row['TonerCyan']) ? (int)$row['TonerCyan'] : null,
            'toner_magenta' => isset($row['TonerMagenta']) ? (int)$row['TonerMagenta'] : null,
            'toner_yellow' => isset($row['TonerYellow']) ? (int)$row['TonerYellow'] : null,
        ];
    }
    unset($row);

    jsonResponse([
        'success' => true,
        'mac_norm' => $macNorm,
        'premier_releve' => $premierReleve ? [
            'timestamp' => $premierReleve['Timestamp'],
            'total_bw' => $firstBw,
            'total_color' => $firstColor,
        ] : null,
        'dernier_releve' => $dernierReleve ? [
            'timestamp' => $dernierReleve['Timestamp'],
            'total_bw' => $lastBw,
            'total_color' => $lastColor,
        ] : null,
        'consommation_totale' => $consommationTotale,
        'consommation_mois_en_cours' => $consommationMois,
        'historique' => $historique,
    ]);
} catch (Throwable $e) {
    error_log('[photocopieurs_get_consommation] ' . $e->getMessage());
    jsonResponse(['success' => false, 'error' => 'Erreur serveur'], 500);
}
