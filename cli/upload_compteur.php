<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Import SFTP -> compteur_relevee (format CSV Champ,Valeur)
 * - Traite AU MAX un lot de N fichiers (par défaut 20) par exécution
 * - Priorité aux fichiers les plus anciens (mtime croissant)
 * - Déduplication: NOT EXISTS sur (mac_norm, Timestamp)
 * - Journalisation: import_run (msg = JSON avec fichiers ajoutés)
 */

// ---------- 0) Normaliser les env MySQL (Railway) si besoin ----------
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

// ---------- 1) Connexion PDO ----------
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
    http_response_code(500);
    exit("❌ Erreur: impossible de charger includes/db.php et obtenir \$pdo\n");
}
echo "✅ DB OK\n";

// ---------- 2) Connexion SFTP ----------
require __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SFTP;

$sftp_host    = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host';
$sftp_user    = getenv('SFTP_USER') ?: '';
$sftp_pass    = getenv('SFTP_PASS') ?: '';
$sftp_port    = (int)(getenv('SFTP_PORT') ?: 22);
$sftp_path    = getenv('SFTP_PATH') ?: '/';
$sftp_timeout = (int)(getenv('SFTP_TIMEOUT') ?: 30);

$BATCH_SIZE   = max(1, (int)(getenv('IMPORT_BATCH_SIZE') ?: 20)); // ← limite par run

$sftp = new SFTP($sftp_host, $sftp_port, $sftp_timeout);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("❌ Erreur de connexion SFTP ($sftp_host:$sftp_port)\n");
}
echo "✅ SFTP connecté sur $sftp_host:$sftp_port, path='$sftp_path' — lot max: $BATCH_SIZE\n";

@$sftp->mkdir('/processed');
@$sftp->mkdir('/errors');

function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/' . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.' . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// ---------- 3) CSV utilitaires ----------
function parse_csv_kv(string $filepath): array {
    $data = [];
    $h = @fopen($filepath, 'r');
    if (!$h) return $data;

    $first = fgets($h) ?: '';
    $sep = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
    rewind($h);

    while (($row = fgetcsv($h, 0, $sep)) !== false) {
        if (count($row) < 2) continue;
        $k = trim((string)$row[0]);
        $v = trim((string)$row[1]);
        if (strcasecmp($k, 'Champ') === 0 && strcasecmp($v, 'Valeur') === 0) continue;
        if ($k !== '') $data[$k] = ($v === '' ? null : $v);
    }
    fclose($h);
    return $data;
}

$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

// ---------- 4) S’assurer que les tables existent ----------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `compteur_relevee` (
          `id` int NOT NULL AUTO_INCREMENT,
          `Timestamp` datetime DEFAULT NULL,
          `IpAddress` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `Nom` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `Model` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `SerialNumber` varchar(100) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `MacAddress` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `Status` varchar(50) COLLATE utf8mb4_general_ci DEFAULT NULL,
          `TonerBlack` int DEFAULT NULL,
          `TonerCyan` int DEFAULT NULL,
          `TonerMagenta` int DEFAULT NULL,
          `TonerYellow` int DEFAULT NULL,
          `TotalPages` int DEFAULT NULL,
          `FaxPages` int DEFAULT NULL,
          `CopiedPages` int DEFAULT NULL,
          `PrintedPages` int DEFAULT NULL,
          `BWCopies` int DEFAULT NULL,
          `ColorCopies` int DEFAULT NULL,
          `MonoCopies` int DEFAULT NULL,
          `BichromeCopies` int DEFAULT NULL,
          `BWPrinted` int DEFAULT NULL,
          `BichromePrinted` int DEFAULT NULL,
          `MonoPrinted` int DEFAULT NULL,
          `ColorPrinted` int DEFAULT NULL,
          `TotalColor` int DEFAULT NULL,
          `TotalBW` int DEFAULT NULL,
          `DateInsertion` datetime DEFAULT NULL,
          `mac_norm` char(12) COLLATE utf8mb4_general_ci GENERATED ALWAYS AS (replace(upper(`MacAddress`),_utf8mb4':',_utf8mb4'')) STORED,
          PRIMARY KEY (`id`),
          KEY `ix_compteur_date` (`Timestamp`),
          KEY `ix_compteur_mac_ts` (`mac_norm`,`Timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `import_run` (
          `id` int NOT NULL AUTO_INCREMENT,
          `ran_at` datetime NOT NULL,
          `imported` int NOT NULL,
          `skipped` int NOT NULL,
          `ok` tinyint(1) NOT NULL,
          `msg` text,
          PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
} catch (Throwable $e) {
    exit('❌ Erreur CREATE TABLE: '.$e->getMessage()."\n");
}

// ---------- 5) Préparer l’INSERT conditionnel (anti-doublon) ----------
$cols = implode(',', $FIELDS) . ',DateInsertion';
$placeholders = ':' . implode(',:', $FIELDS) . ',NOW()';

$sqlInsertIfMissing = "
  INSERT INTO compteur_relevee ($cols)
  SELECT $placeholders
  FROM DUAL
  WHERE NOT EXISTS (
    SELECT 1 FROM compteur_relevee
    WHERE mac_norm = REPLACE(UPPER(:_mac_check), ':','')
      AND Timestamp = :_ts_check
  )
";
$stInsert = $pdo->prepare($sqlInsertIfMissing);

// ---------- 6) Sélectionner AU PLUS $BATCH_SIZE fichiers, les plus anciens ----------
echo "🔎 Listing SFTP…\n";

/** On utilise rawlist pour récupérer les mtime, puis on trie ASC et on coupe à $BATCH_SIZE */
$raw = $sftp->rawlist($sftp_path);
if ($raw === false || !is_array($raw)) {
    echo "❌ Impossible de lister '$sftp_path'\n";
    $raw = [];
}

$pattern = '/^COPIEUR_MAC-([A-F0-9:\-]+)_(\d{8}_\d{6})\.csv$/i';
$candidates = [];
foreach ($raw as $name => $meta) {
    if ($name === '.' || $name === '..') continue;
    if (is_array($meta) && isset($meta['type']) && $meta['type'] !== 1) continue; // 1 = fichier
    if (!preg_match($pattern, (string)$name)) continue;

    $mtime = is_array($meta) && isset($meta['mtime']) ? (int)$meta['mtime'] : 0;
    $candidates[] = [
        'name' => (string)$name,
        'path' => rtrim($sftp_path, '/') . '/' . ltrim((string)$name, '/'),
        'mtime'=> $mtime,
    ];
}

/** Trier par mtime croissant (plus anciens d'abord) et limiter à $BATCH_SIZE */
usort($candidates, function($a, $b){ return ($a['mtime'] <=> $b['mtime']); });
$selected = array_slice($candidates, 0, $BATCH_SIZE);

$total_available = count($candidates);
echo "📂 Fichiers candidats: $total_available — On va traiter: ".count($selected)." (lot max: $BATCH_SIZE)\n";

// ---------- 7) Traiter le lot sélectionné ----------
$files_processed = 0;
$compteurs_inserted = 0;
$files_error = 0;
$inserted_files = [];

foreach ($selected as $file) {
    $name   = $file['name'];
    $remote = $file['path'];
    $files_processed++;

    $tmp = tempnam(sys_get_temp_dir(), 'csv_');
    if (!$sftp->get($remote, $tmp)) {
        echo "❌ Download KO: $name → /errors\n";
        sftp_safe_move($sftp, $remote, '/errors');
        @unlink($tmp);
        $files_error++;
        continue;
    }

    $kv = parse_csv_kv($tmp);
    @unlink($tmp);

    $vals = [];
    foreach ($FIELDS as $f) $vals[$f] = $kv[$f] ?? null;

    if (empty($vals['Timestamp']) || empty($vals['MacAddress'])) {
        echo "⚠️ Manque Timestamp/MacAddress pour $name → /errors\n";
        sftp_safe_move($sftp, $remote, '/errors');
        $files_error++;
        continue;
    }

    // Normaliser Timestamp
    $vals['Timestamp'] = date('Y-m-d H:i:s', strtotime((string)$vals['Timestamp']));

    try {
        $pdo->beginTransaction();

        $binds = [];
        foreach ($FIELDS as $f) $binds[":$f"] = $vals[$f];
        $binds[':_mac_check'] = $vals['MacAddress'];
        $binds[':_ts_check']  = $vals['Timestamp'];

        $stInsert->execute($binds);

        if ($stInsert->rowCount() === 1) {
            $compteurs_inserted++;
            $inserted_files[] = $name;
            echo "✅ INSÉRÉ: {$vals['MacAddress']} @ {$vals['Timestamp']} (file: $name)\n";
        } else {
            echo "ℹ️ Doublon ignoré: {$vals['MacAddress']} @ {$vals['Timestamp']} (file: $name)\n";
        }

        $pdo->commit();

        [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
        if (!$okMove) echo "⚠️ Move → /processed échoué: $name\n";
        else          echo "📦 Archivé: $name\n";

    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "❌ PDO: ".$e->getMessage()."\n";
        sftp_safe_move($sftp, $remote, '/errors');
        $files_error++;
    }
}

// ---------- 8) Journal import_run ----------
try {
    $filesForMsg = $inserted_files;
    if (count($filesForMsg) > 50) {
        $filesForMsg = array_slice($filesForMsg, 0, 50);
        $filesForMsg[] = '...';
    }
    $msgJson = json_encode([
        'processed' => $files_processed,
        'errors'    => $files_error,
        'inserted'  => $compteurs_inserted,
        'files'     => $filesForMsg,
        'batch'     => $BATCH_SIZE,
        'remaining_estimate' => max(0, $total_available - $files_processed) // juste indicatif
    ], JSON_UNESCAPED_UNICODE);

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    $stmt->execute([
        ':imported' => max(0, $files_processed - $files_error),
        ':skipped'  => $files_error,
        ':ok'       => ($files_error === 0 ? 1 : 0),
        ':msg'      => $msgJson,
    ]);
    echo "📝 import_run: inserted=$compteurs_inserted, files=$files_processed, errors=$files_error, remaining≈".max(0,$total_available - $files_processed)."\n";
} catch (Throwable $e) {
    echo "❌ import_run INSERT: ".$e->getMessage()."\n";
}

echo "-----------------------------\n";
echo "✅ Fin traitement (lot partiel).\n";
