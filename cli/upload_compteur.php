<?php
declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * Import SFTP -> compteur_relevee (format CSV Champ,Valeur)
 * - Déduplication: NOT EXISTS sur (mac_norm, Timestamp)
 * - Journalisation: import_run (msg = JSON avec fichiers ajoutés)
 */

// ---------- 0) Normaliser éventuellement les env MySQL (Railway) ----------
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
    putenv("MYSQLDATABASE" . '=' . ltrim($p['path'], '/'));
})();

// ---------- 1) Charger la connexion PDO ----------
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

$sftp = new SFTP($sftp_host, $sftp_port, $sftp_timeout);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("❌ Erreur de connexion SFTP ($sftp_host:$sftp_port)\n");
}
echo "✅ SFTP connecté sur $sftp_host:$sftp_port, path='$sftp_path'\n";

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

echo "🚀 Scan des CSV…\n";

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

// ---------- 6) Parcours des fichiers SFTP ----------
$files_processed = 0;
$compteurs_inserted = 0;
$files_error = 0;
$inserted_files = [];   // ← on liste les fichiers réellement insérés

$list = $sftp->nlist($sftp_path);
if ($list === false) {
    echo "❌ Impossible de lister '$sftp_path'\n";
} else {
    $found = false;
    foreach ($list as $name) {
        if ($name === '.' || $name === '..') continue;
        if (!preg_match('/^COPIEUR_MAC-([A-F0-9:\-]+)_(\d{8}_\d{6})\.csv$/i', $name)) continue;

        $found = true;
        $files_processed++;

        $remote = rtrim($sftp_path, '/') . '/' . ltrim($name, '/');
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

        // Construire les valeurs
        $vals = [];
        foreach ($FIELDS as $f) $vals[$f] = $kv[$f] ?? null;

        if (empty($vals['Timestamp']) || empty($vals['MacAddress'])) {
            echo "⚠️ Manque Timestamp/MacAddress pour $name → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        // Normaliser Timestamp → DATETIME MySQL
        $vals['Timestamp'] = date('Y-m-d H:i:s', strtotime((string)$vals['Timestamp']));

        try {
            $pdo->beginTransaction();

            $binds = [];
            foreach ($FIELDS as $f) $binds[":$f"] = $vals[$f];
            // paramètres pour la clause NOT EXISTS
            $binds[':_mac_check'] = $vals['MacAddress'];
            $binds[':_ts_check']  = $vals['Timestamp'];

            $stInsert->execute($binds);

            if ($stInsert->rowCount() === 1) {
                $compteurs_inserted++;
                $inserted_files[] = $name; // ← on retient ce fichier
                echo "✅ INSÉRÉ: {$vals['MacAddress']} @ {$vals['Timestamp']} (file: $name)\n";
            } else {
                echo "ℹ️ Doublon ignoré (mac_norm,Timestamp): {$vals['MacAddress']} @ {$vals['Timestamp']} (file: $name)\n";
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

    if (!$found) echo "⚠️ Aucun CSV trouvé dans '$sftp_path'\n";
}

// ---------- 7) Journal import_run ----------
try {
    // Résumé JSON dans msg (on tronque la liste si trop longue)
    $filesForMsg = $inserted_files;
    if (count($filesForMsg) > 50) {
        $filesForMsg = array_slice($filesForMsg, 0, 50);
        $filesForMsg[] = '...';
    }
    $msgJson = json_encode([
        'processed' => $files_processed,
        'errors'    => $files_error,
        'inserted'  => $compteurs_inserted,
        'files'     => $filesForMsg
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
    echo "📝 import_run: inserted=$compteurs_inserted, files=$files_processed, errors=$files_error\n";
} catch (Throwable $e) {
    echo "❌ import_run INSERT: ".$e->getMessage()."\n";
}

echo "-----------------------------\n";
echo "✅ Fin traitement.\n";
