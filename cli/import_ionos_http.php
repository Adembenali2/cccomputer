<?php
/**
 * /cli/import_ionos_http.php
 *
 * Importe depuis une URL JSON (IONOS_EXPORT_URL) vers la table `compteur_relevee`.
 * - Traite un LOT LIMITÉ par exécution (défaut 20 via IONOS_BATCH_SIZE).
 * - Priorise les éléments les plus anciens (tri par date ASC, puis mac ASC).
 * - Idempotent grâce à l'index UNIQUE (mac_norm, Timestamp) + UPSERT.
 * - Mémorise une position (curseur) dans `ionos_cursor` pour éviter de rebalayer.
 * - Journalise chaque run dans `import_run` (msg JSON: source=ionos, processed, inserted, skipped, remaining_estimate).
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ---------------------------------------------------------
// 0) Normaliser les variables d'env Railway pour includes/db.php
// ---------------------------------------------------------
(function (): void {
    $needs = !getenv('MYSQLHOST') || !getenv('MYSQLDATABASE') || !getenv('MYSQLUSER');
    if (!$needs) return;

    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL') ?: '';
    if (!$url) return;

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) return;

    putenv("MYSQLHOST={$p['host']}");
    putenv("MYSQLPORT=" . ($p['port'] ?? '3306'));
    putenv("MYSQLUSER=" . urldecode($p['user']));
    putenv("MYSQLPASSWORD=" . (isset($p['pass']) ? urldecode($p['pass']) : ''));
    putenv("MYSQLDATABASE=" . ltrim($p['path'], '/'));
})();

// ---------------------------------------------------------
// 1) Charger $pdo
// ---------------------------------------------------------
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/includes/db.php',
];
$ok = false;
foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; $ok = true; break; }
}
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    fwrite(STDERR, "❌ Impossible de charger includes/db.php et obtenir \$pdo\n");
    exit(1);
}
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------------------------------------------------------
// 2) Configuration
// ---------------------------------------------------------
$EXPORT_URL  = getenv('IONOS_EXPORT_URL') ?: '';
if ($EXPORT_URL === '') {
    fwrite(STDERR, "❌ IONOS_EXPORT_URL manquant (Variables Railway)\n");
    exit(1);
}
$BATCH = max(1, (int)(getenv('IONOS_BATCH_SIZE') ?: 20)); // taille du lot

// ---------------------------------------------------------
// 3) Fonctions utilitaires
// ---------------------------------------------------------
function normalizeMacColoned(?string $mac): ?string {
    if ($mac === null) return null;
    $hex = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
    if (strlen($hex) !== 12) return null;
    return implode(':', str_split($hex, 2));
}
function iOrNull($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}
function fetchJson(string $url): ?array {
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
            fwrite(STDERR, "❌ Téléchargement IONOS échoué (HTTP $code): $err\n");
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
            fwrite(STDERR, "❌ file_get_contents échoué: $url\n");
            return null;
        }
    }
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

// ---------------------------------------------------------
// 4) S'assurer des tables requises
// ---------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS ionos_cursor (
            id TINYINT PRIMARY KEY DEFAULT 1,
            last_ts DATETIME NULL,
            last_mac CHAR(12) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $pdo->exec("INSERT IGNORE INTO ionos_cursor(id,last_ts,last_mac) VALUES (1,NULL,NULL)");
} catch (Throwable $e) {
    fwrite(STDERR, "⚠️ ionos_cursor: ".$e->getMessage()."\n");
}
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS import_run (
          id INT NOT NULL AUTO_INCREMENT,
          ran_at DATETIME NOT NULL,
          imported INT NOT NULL,
          skipped INT NOT NULL,
          ok TINYINT(1) NOT NULL,
          msg TEXT,
          PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    fwrite(STDERR, "⚠️ import_run: ".$e->getMessage()."\n");
}
try {
    // au cas où l'index UNIQUE n'existe pas encore
    $pdo->exec("ALTER TABLE `compteur_relevee` ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`,`Timestamp`)");
} catch (Throwable $e) {
    // ignore
}

// ---------------------------------------------------------
// 5) Lire curseur
// ---------------------------------------------------------
$cur = $pdo->query("SELECT last_ts, last_mac FROM ionos_cursor WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: ['last_ts'=>null,'last_mac'=>null];
$lastTs  = $cur['last_ts'] ?: null;
$lastMac = $cur['last_mac'] ?: null;

// ---------------------------------------------------------
// 6) Télécharger JSON
// ---------------------------------------------------------
$payload = fetchJson($EXPORT_URL);
if (!$payload || empty($payload['ok'])) {
    fwrite(STDERR, "❌ Payload JSON invalide ou ok=false\n");
    exit(1);
}
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
echo "📦 IONOS items reçus: ".count($items)."\n";

// ---------------------------------------------------------
// 7) Normaliser + trier
// ---------------------------------------------------------
$norm = [];
foreach ($items as $r) {
    $mac = normalizeMacColoned($r['mac'] ?? null);
    if (!$mac) continue;
    $ts  = strtotime((string)($r['date'] ?? ''));
    if (!$ts) continue;
    $norm[] = [
        'mac' => $mac,
        'mac_norm' => str_replace(':','',$mac),
        'ts'  => date('Y-m-d H:i:s', $ts),
        'raw' => $r
    ];
}
usort($norm, fn($a,$b) => strcmp($a['ts'], $b['ts']) ?: strcmp($a['mac_norm'], $b['mac_norm']));

// ---------------------------------------------------------
// 8) Filtrer > curseur (ordre lexicographique (ts, mac_norm))
// ---------------------------------------------------------
$eligible = [];
foreach ($norm as $x) {
    if ($lastTs && ($x['ts'] < $lastTs)) continue;
    if ($lastTs && $x['ts'] === $lastTs) {
        if ($lastMac && $x['mac_norm'] <= $lastMac) continue;
    }
    $eligible[] = $x;
}
$batch = array_slice($eligible, 0, $BATCH);
$remaining_estimate = max(0, count($eligible) - count($batch));
echo "🧮 À traiter: ".count($batch)." (lot=". $BATCH ."), reste≈$remaining_estimate\n";

// ---------------------------------------------------------
// 9) UPSERT (ON DUPLICATE KEY UPDATE)
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

$pdo->beginTransaction();
$imported = 0;
$skipped  = 0;
$lastTsNew  = $lastTs;
$lastMacNew = $lastMac;

try {
    foreach ($batch as $x) {
        $r = $x['raw'];
        $tBW = iOrNull($r['totalNB']      ?? null);
        $tCL = iOrNull($r['totalCouleur'] ?? null);
        $tAll = ($tBW ?? 0) + ($tCL ?? 0);
        $ton = is_array($r['toners'] ?? null) ? $r['toners'] : [];
        $bind = [
            ':mac_addr'  => $x['mac'],
            ':ts'        => $x['ts'],
            ':tot_bw'    => $tBW,
            ':tot_col'   => $tCL,
            ':tot_pages' => $tAll,
            ':t_k'       => iOrNull($ton['k'] ?? null),
            ':t_c'       => iOrNull($ton['c'] ?? null),
            ':t_m'       => iOrNull($ton['m'] ?? null),
            ':t_y'       => iOrNull($ton['y'] ?? null),
            ':status'    => iOrNull($r['etat'] ?? null),
            ':sn'        => isset($r['serial']) ? (string)$r['serial'] : null,
            ':model'     => isset($r['model'])  ? (string)$r['model']  : null,
            ':nom'       => isset($r['nom'])    ? (string)$r['nom']    : null,
        ];
        try {
            $ins->execute($bind);
            $imported++;
            // avancer le curseur jusqu'au dernier du batch
            $lastTsNew  = $x['ts'];
            $lastMacNew = $x['mac_norm'];
        } catch (Throwable $e) {
            $skipped++;
            // ne pas interrompre le lot entier
        }
    }

    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "❌ Erreur import: ".$e->getMessage()."\n");
    // log KO
    $pdo->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
                   VALUES (NOW(), 0, 0, 0, :m)")
        ->execute([':m' => json_encode(['source'=>'ionos', 'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE)]);
    exit(1);
}

// ---------------------------------------------------------
// 10) Mettre à jour le curseur si on a avancé
// ---------------------------------------------------------
if ($imported > 0) {
    $u = $pdo->prepare("UPDATE ionos_cursor SET last_ts=?, last_mac=? WHERE id=1");
    $u->execute([$lastTsNew, $lastMacNew]);
}

// ---------------------------------------------------------
// 11) Journal import_run
// ---------------------------------------------------------
$msg = json_encode([
    'source'             => 'ionos',
    'processed'          => count($batch),
    'inserted'           => $imported,
    'skipped'            => $skipped,
    'remaining_estimate' => $remaining_estimate
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
               VALUES (NOW(), :i, :s, :ok, :m)")
    ->execute([
        ':i'  => $imported,
        ':s'  => $skipped,
        ':ok' => ($skipped === 0 ? 1 : 0),
        ':m'  => $msg
    ]);

echo "✅ Import IONOS terminé — inserted=$imported, skipped=$skipped, reste≈$remaining_estimate\n";
