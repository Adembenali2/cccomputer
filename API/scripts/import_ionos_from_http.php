<?php
/**
 * API/scripts/import_ionos_from_http.php
 *
 * Importe depuis une URL JSON (IONOS_EXPORT_URL) vers la table `compteur_relevee`
 * de ta base Railway. L'UPSERT s'appuie sur un index UNIQUE (mac_norm, Timestamp).
 *
 * JSON attendu (exemple d'un item) :
 * {
 *   "ref_client": "C27835",
 *   "mac": "00:26:73:50:D2:12",
 *   "date": "2025-10-23 09:48:24",
 *   "totalNB": "849410",
 *   "totalCouleur": "491108",
 *   "etat": "1",
 *   "toners": {"k":40,"c":50,"m":30,"y":60}   // optionnel
 * }
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------------------------------------------------------
// 1) Charger $pdo depuis includes/config
// ---------------------------------------------------------
$paths = [
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../config/db.php',
    __DIR__ . '/../config/db.php',
];
$ok = false;
foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; $ok = true; break; }
}
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "[import] Impossible de charger includes/db.php et obtenir \$pdo\n");
    exit(1);
}

// ---------------------------------------------------------
// 2) URL d'export (via env IONOS_EXPORT_URL)
// ---------------------------------------------------------
$exportUrl = getenv('IONOS_EXPORT_URL');
if (!$exportUrl) {
    fwrite(STDERR, "[import] IONOS_EXPORT_URL manquant (Variables Railway)\n");
    exit(1);
}

// ---------------------------------------------------------
// Helpers
// ---------------------------------------------------------
function println(string $s = ''): void { echo $s . PHP_EOL; }
function iOrNull($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}
/** Normalise et valide une MAC. Retourne "00:11:22:33:44:55" ou null */
function normalizeMacColoned(?string $mac): ?string {
    if ($mac === null) return null;
    $hex = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
    if ($hex === '' || strlen($hex) !== 12) return null;
    return implode(':', str_split($hex, 2));
}

// ---------------------------------------------------------
// 3) Télécharger le JSON
// ---------------------------------------------------------
function fetchJson(string $url): ?array {
    // cURL si dispo (timeouts plus fiables)
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'User-Agent: ionos-importer/1.0'],
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code >= 400) {
            fwrite(STDERR, "[import] Téléchargement échoué ($code): $err\n");
            return null;
        }
    } else {
        $ctx = stream_context_create([
            'http' => [
                'timeout' => 45,
                'header'  => "Accept: application/json\r\nUser-Agent: ionos-importer/1.0\r\n",
            ]
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            fwrite(STDERR, "[import] file_get_contents échoué: $url\n");
            return null;
        }
    }
    $json = json_decode($body, true);
    if (!is_array($json)) {
        fwrite(STDERR, "[import] JSON invalide\n");
        return null;
    }
    return $json;
}

$payload = fetchJson($exportUrl);
if (!$payload || empty($payload['ok'])) {
    fwrite(STDERR, "[import] Payload JSON invalide ou ok=false\n");
    exit(1);
}

$items = $payload['items'] ?? [];
println("Reçus depuis IONOS: " . count($items) . " éléments");

// ---------------------------------------------------------
// 4) Préparer la table cible : index UNIQUE pour l'upsert
// ---------------------------------------------------------
try {
    // mac_norm est une colonne générée à partir de MacAddress dans ton schéma.
    // On force un index UNIQUE pour que ON DUPLICATE KEY UPDATE fonctionne.
    $pdo->exec("ALTER TABLE `compteur_relevee`
                ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`, `Timestamp`)");
} catch (Throwable $e) {
    // déjà présent -> ignorer
}

// (Optionnel) petite table de suivi des imports
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_runs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ran_at DATETIME NOT NULL,
            imported INT NOT NULL,
            skipped INT NOT NULL,
            ok TINYINT(1) NOT NULL,
            msg TEXT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
} catch (Throwable $e) {
    // si ça échoue, on continue quand même
}

// ---------------------------------------------------------
// 5) UPSERT
//   NB: on ne touche PAS à mac_norm (générée par MySQL).
//   On remplit MacAddress, Timestamp, compteurs, toners, status.
// ---------------------------------------------------------
$sql = "
INSERT INTO compteur_relevee
    (MacAddress, `Timestamp`,
     TotalBW, TotalColor, TotalPages,
     TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
     Status, SerialNumber, Model, Nom)
VALUES
    (:mac_addr, :ts,
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

// ---------------------------------------------------------
// 6) Import transactionnel
// ---------------------------------------------------------
$pdo->beginTransaction();
$imported = 0;
$skipped  = 0;

try {
    foreach ($items as $r) {
        // MAC
        $macInput = isset($r['mac']) ? (string)$r['mac'] : '';
        $macCol   = normalizeMacColoned($macInput);
        if (!$macCol) { $skipped++; continue; }

        // Timestamp
        $tsStr = (string)($r['date'] ?? '');
        $ts    = strtotime($tsStr);
        $tsSql = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');

        // Compteurs
        $totBW  = iOrNull($r['totalNB']       ?? null);
        $totCol = iOrNull($r['totalCouleur']  ?? null);
        $totAll = ($totBW ?? 0) + ($totCol ?? 0);

        // Toners (optionnels)
        $t = is_array($r['toners'] ?? null) ? $r['toners'] : [];
        $tK = iOrNull($t['k'] ?? null);
        $tC = iOrNull($t['c'] ?? null);
        $tM = iOrNull($t['m'] ?? null);
        $tY = iOrNull($t['y'] ?? null);

        // Status (on accepte int ou string)
        $status = iOrNull($r['etat'] ?? null);

        // Champs non fournis par l’export (si tu les ajoutes plus tard, ils seront pris)
        $sn    = isset($r['serial']) ? (string)$r['serial'] : null;
        $model = isset($r['model'])  ? (string)$r['model']  : null;
        $nom   = isset($r['nom'])    ? (string)$r['nom']    : null;

        $ins->execute([
            ':mac_addr'  => $macCol,
            ':ts'        => $tsSql,
            ':tot_bw'    => $totBW,
            ':tot_col'   => $totCol,
            ':tot_pages' => $totAll,
            ':t_k'       => $tK,
            ':t_c'       => $tC,
            ':t_m'       => $tM,
            ':t_y'       => $tY,
            ':status'    => $status,
            ':sn'        => $sn,
            ':model'     => $model,
            ':nom'       => $nom,
        ]);

        $imported++;
    }

    $pdo->commit();
    println("✅ Import terminé. Insérés/MAJ: {$imported} — Ignorés: {$skipped}");

    try {
        $log = $pdo->prepare("INSERT INTO import_runs(ran_at, imported, skipped, ok, msg)
                              VALUES (NOW(), :i, :s, 1, 'OK')");
        $log->execute([':i' => $imported, ':s' => $skipped]);
    } catch (Throwable $e) { /* non bloquant */ }

} catch (Throwable $e) {
    $pdo->rollBack();
    $msg = $e->getMessage();
    fwrite(STDERR, "[import] Erreur pendant l'import: {$msg}\n");
    try {
        $log = $pdo->prepare("INSERT INTO import_runs(ran_at, imported, skipped, ok, msg)
                              VALUES (NOW(), 0, 0, 0, :m)");
        $log->execute([':m' => $msg]);
    } catch (Throwable $e2) { /* ignore */ }
    exit(1);
}
