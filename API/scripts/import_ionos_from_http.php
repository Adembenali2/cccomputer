<?php
/**
 * API/scripts/import_ionos_from_http.php
 * Consomme le JSON d'export (hébergé chez IONOS) et insère/MAJ dans compteur_relevee (Railway).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// --------- charge $pdo de includes/db.php ---------
$paths = [
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config/db.php',
];
$ok = false;
foreach ($paths as $p) { if (is_file($p)) { require_once $p; $ok=true; break; } }
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "Impossible de charger includes/db.php et obtenir \$pdo\n");
    exit(1);
}

// --------- config URL export IONOS ---------
// Mets cette URL en variable Railway si tu veux (ex: IONOS_EXPORT_URL)
$exportUrl = getenv('IONOS_EXPORT_URL') ?: 'http://cccomputer.fr/web/export_compteurs.php?token=MaCleHyperSecrete_123456789';


// --------- récup JSON ---------
$ctx = stream_context_create([
    'http' => [
        'timeout' => 30,
        'header'  => "Accept: application/json\r\n",
    ]
]);
$json = @file_get_contents($exportUrl, false, $ctx);
if ($json === false) {
    fwrite(STDERR, "Erreur: impossible de télécharger $exportUrl\n");
    exit(1);
}
$payload = json_decode($json, true);
if (!is_array($payload) || empty($payload['ok'])) {
    fwrite(STDERR, "Erreur: payload invalide\n$json\n");
    exit(1);
}

$items = $payload['items'] ?? [];
echo "Reçus depuis IONOS: " . count($items) . " éléments\n";

// --------- helpers ---------
function normMac(?string $mac): ?string {
    if ($mac===null) return null;
    $m = strtoupper(preg_replace('/[^0-9A-F]/i','',$mac));
    return $m !== '' ? $m : null;
}
function iOrNull($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}

// --------- index d’unicité (une seule fois) ---------
try {
    $pdo->exec("ALTER TABLE `compteur_relevee` ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`,`Timestamp`)");
    echo "Index uniq_mac_ts créé\n";
} catch (Throwable $e) { /* ignore si déjà là */ }

// --------- UPSERT ---------
$sql = "
INSERT INTO compteur_relevee
    (mac_norm, MacAddress, `Timestamp`,
     TotalBW, TotalColor, TotalPages,
     TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
     Status, SerialNumber, Model, Nom)
VALUES
    (:mac_norm, :mac_addr, :ts,
     :tot_bw, :tot_col, :tot_pages,
     :t_k, :t_c, :t_m, :t_y,
     :status, :sn, :model, :nom)
ON DUPLICATE KEY UPDATE
     TotalBW      = VALUES(TotalBW),
     TotalColor   = VALUES(TotalColor),
     TotalPages   = VALUES(TotalPages),
     TonerBlack   = VALUES(TonerBlack),
     TonerCyan    = VALUES(TonerCyan),
     TonerMagenta = VALUES(TonerMagenta),
     TonerYellow  = VALUES(TonerYellow),
     Status       = VALUES(Status)
";
$ins = $pdo->prepare($sql);

// --------- Import ---------
$pdo->beginTransaction();
$imported=0; $skipped=0;

try {
    foreach ($items as $r) {
        $mac   = isset($r['mac']) ? (string)$r['mac'] : '';
        $macN  = normMac($mac);
        if (!$macN) { $skipped++; continue; }

        $tsStr = (string)($r['date'] ?? '');
        $tsSql = date('Y-m-d H:i:s', strtotime($tsStr) ?: time());

        $totBW  = iOrNull($r['totalNB'] ?? null);
        $totCol = iOrNull($r['totalCouleur'] ?? null);
        $totAll = ($totBW ?? 0) + ($totCol ?? 0);

        $t = $r['toners'] ?? ['k'=>null,'c'=>null,'m'=>null,'y'=>null];

        $ins->execute([
            ':mac_norm'  => $macN,
            ':mac_addr'  => $mac ?: null,
            ':ts'        => $tsSql,
            ':tot_bw'    => $totBW,
            ':tot_col'   => $totCol,
            ':tot_pages' => $totAll,
            ':t_k'       => iOrNull($t['k'] ?? null),
            ':t_c'       => iOrNull($t['c'] ?? null),
            ':t_m'       => iOrNull($t['m'] ?? null),
            ':t_y'       => iOrNull($t['y'] ?? null),
            ':status'    => iOrNull($r['etat'] ?? null),
            ':sn'        => null,
            ':model'     => null,
            ':nom'       => null,
        ]);

        $imported++;
    }
    $pdo->commit();
    echo "Import terminé. Insérés/MAJ: $imported — Ignorés: $skipped\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Erreur pendant l'import: ".$e->getMessage()."\n");
    exit(1);
}
