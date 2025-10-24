<?php
declare(strict_types=1);

/**
 * upload_compteur.php — version debug
 * - Logs détaillés (milestones M1..M6)
 * - DB via includes/db.php + ERRMODE_EXCEPTION
 * - Autoload vendor avec détection de chemin
 * - SFTP via phpseclib3 (identifiants depuis env)
 * - Parsing CSV automatique: header row OU key/value 2 colonnes
 * - nlist() fallback sur plusieurs répertoires
 * - Toujours journaliser dans import_run, même si SFTP tombe
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');

function logf(string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

logf('M1: script start');

// ---------- 0) Normaliser les variables d'env pour db.php ----------
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

// ---------- 1) Charger $pdo depuis includes/db.php ----------
$includeCandidates = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/includes/db.php',
];
$pdoLoaded = false;
foreach ($includeCandidates as $inc) {
    if (is_file($inc)) {
        logf("M1b: trying include $inc");
        require_once $inc;
        $pdoLoaded = true;
        break;
    } else {
        logf("M1b: include not found $inc");
    }
}
if (!$pdoLoaded || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit("❌ Erreur: impossible de charger includes/db.php et obtenir \$pdo\n");
}

try {
    // Sécurise le mode erreur (évite les erreurs silencieuses)
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    logf("⚠️ Impossible de forcer ERRMODE_EXCEPTION: " . $e->getMessage());
}

logf('M2: DB loaded + ERRMODE_EXCEPTION');

// ---------- 1b) Healthcheck insert import_run (crée table si besoin) ----------
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
    $pdo->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg) VALUES (NOW(), 0, 0, 1, 'healthcheck')")
        ->execute();
    logf('M2c: import_run healthcheck insert OK');
} catch (Throwable $e) {
    logf("⚠️ [IMPORT_RUN] Erreur init/healthcheck: " . $e->getMessage());
}

// ---------- 2) Autoload vendor ----------
$autoloadCandidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/vendor/autoload.php',
];
$autoloadOk = false;
foreach ($autoloadCandidates as $auto) {
    $exists = file_exists($auto);
    logf("M2b: autoload path = $auto, exists? " . ($exists ? 'yes' : 'no'));
    if ($exists) {
        require $auto;
        $autoloadOk = true;
        break;
    }
}
if (!$autoloadOk) {
    // On ne sort pas; on journalisera quand même
    logf("❌ vendor/autoload introuvable — SFTP indisponible");
}

// ---------- 3) Connexion SFTP ----------
use phpseclib3\Net\SFTP;

$sftp_host = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host'; // remplace par env
$sftp_user = getenv('SFTP_USER') ?: 'CHANGE_ME';
$sftp_pass = getenv('SFTP_PASS') ?: 'CHANGE_ME';
$sftp_port = (int)(getenv('SFTP_PORT') ?: 22);

$had_fatal = false;
$files_processed = 0;
$compteurs_inserted = 0;
$files_error = 0;

$sftp = null;
if ($autoloadOk) {
    try {
        logf("M3b: trying SFTP {$sftp_host}:{$sftp_port} user={$sftp_user}");
        $sftp = new SFTP($sftp_host, $sftp_port, 10); // 10s timeout
        if (!$sftp->login($sftp_user, $sftp_pass)) {
            logf("❌ Erreur de connexion SFTP (login)");
            $had_fatal = true;
        } else {
            logf("M4: SFTP logged in");
        }
    } catch (Throwable $e) {
        logf("❌ SFTP exception: " . $e->getMessage());
        $had_fatal = true;
    }
} else {
    $had_fatal = true;
}

// ---------- Création dossiers SFTP (si connecté) ----------
if ($sftp && $sftp->isConnected()) {
    @$sftp->mkdir('/processed');
    @$sftp->mkdir('/errors');
}

function sftp_safe_move(?SFTP $sftp, string $from, string $toDir): array {
    if (!$sftp || !$sftp->isConnected()) return [false, null];
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/' . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.' . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// ---------- 4) Utilitaires CSV ----------
$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

/**
 * Détecte automatiquement le format:
 *  - CSV header (ligne 1 = en-têtes, ligne 2 = valeurs)
 *  - KV 2 colonnes (clé,valeur pour chaque ligne)
 */
function parse_csv_auto(string $filepath, array $expectedFields): array {
    $data = [];
    if (!is_file($filepath)) return $data;
    $h = fopen($filepath, 'r');
    if ($h === false) return $data;

    $first = fgetcsv($h, 0, ',');
    if ($first === false) { fclose($h); return $data; }

    // Peek ligne 2
    $second = fgetcsv($h, 0, ',');
    // Remettre le pointeur au début si besoin
    fclose($h);

    // Heuristique: si $first contient >= 3 colonnes ET intersecte beaucoup $expectedFields => mode header
    $intersect = array_intersect(array_map('trim', $first), $expectedFields);
    $headerMode = is_array($first) && count($first) >= 3 && count($intersect) >= 3;

    if ($headerMode) {
        $h = fopen($filepath, 'r');
        $header = fgetcsv($h, 0, ',');
        $row = fgetcsv($h, 0, ','); // un seul relevé par fichier attendu
        fclose($h);
        if ($header !== false && $row !== false) {
            foreach ($header as $i => $col) {
                $key = trim((string)$col);
                $data[$key] = $row[$i] ?? null;
            }
        }
        return $data;
    }

    // Sinon, on tente le mode key/value 2 colonnes
    $h = fopen($filepath, 'r');
    if ($h === false) return $data;
    while (($row = fgetcsv($h, 0, ',')) !== false) {
        if (count($row) >= 2) {
            $k = trim((string)$row[0]);
            $v = trim((string)$row[1]);
            if ($k !== '') $data[$k] = $v;
        }
    }
    fclose($h);
    return $data;
}

// ---------- 5) Préparer requêtes ----------
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
try {
    $st_compteur = $pdo->prepare($sql_compteur);
    logf('M3: prepared statement OK');
} catch (Throwable $e) {
    logf('❌ Préparation INSERT compteur_relevee échouée: ' . $e->getMessage());
    // On continue, mais ça ne pourra pas insérer
}

// ---------- 5b) S’assurer que la table compteur_relevee existe (optionnel mais pratique en debug)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS compteur_relevee (
            id INT AUTO_INCREMENT PRIMARY KEY,
            Timestamp VARCHAR(64) NULL,
            IpAddress VARCHAR(64) NULL,
            Nom VARCHAR(255) NULL,
            Model VARCHAR(255) NULL,
            SerialNumber VARCHAR(255) NULL,
            MacAddress VARCHAR(64) NULL,
            Status VARCHAR(64) NULL,
            TonerBlack INT NULL,
            TonerCyan INT NULL,
            TonerMagenta INT NULL,
            TonerYellow INT NULL,
            TotalPages INT NULL,
            FaxPages INT NULL,
            CopiedPages INT NULL,
            PrintedPages INT NULL,
            BWCopies INT NULL,
            ColorCopies INT NULL,
            MonoCopies INT NULL,
            BichromeCopies INT NULL,
            BWPrinted INT NULL,
            BichromePrinted INT NULL,
            MonoPrinted INT NULL,
            ColorPrinted INT NULL,
            TotalColor INT NULL,
            TotalBW INT NULL,
            DateInsertion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_mac_ts (MacAddress, Timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    logf('M3b: compteur_relevee ensured');
} catch (Throwable $e) {
    logf("⚠️ compteur_relevee DDL error: " . $e->getMessage());
}

// ---------- 5c) Si SFTP OK: parcourir les fichiers ----------
if (!$had_fatal && $sftp && $sftp->isConnected()) {
    logf("🚀 Traitement des fichiers CSV...");

    // Trouver un répertoire listable
    $rootsToTry = ['/', '.', '/uploads', '/incoming'];
    $root = null;
    foreach ($rootsToTry as $dir) {
        $lst = @$sftp->nlist($dir);
        logf("M4b: nlist($dir) => " . (is_array($lst) ? count($lst) : 'false'));
        if (is_array($lst)) { $root = $dir; break; }
    }
    if ($root === null) {
        logf("❌ Impossible de lister un répertoire SFTP");
        $had_fatal = true;
    } else {
        $files = @$sftp->nlist($root);
        if ($files === false) {
            logf("❌ Impossible d’ouvrir le dossier $root");
            $had_fatal = true;
        } else {
            $found = false;
            foreach ($files as $entry) {
                if ($entry === '.' || $entry === '..') continue;
                logf("M4c: found entry '$entry'");
                if (!preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) continue;

                $found = true;
                $files_processed++;
                $remote = rtrim($root, '/') . '/' . $entry;
                $tmp = tempnam(sys_get_temp_dir(), 'csv_');
                if (!$sftp->get($remote, $tmp)) {
                    logf("❌ Erreur téléchargement $entry");
                    sftp_safe_move($sftp, $remote, '/errors');
                    @unlink($tmp);
                    $files_error++;
                    continue;
                }

                $row = parse_csv_auto($tmp, $FIELDS);
                @unlink($tmp);

                $values = [];
                foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;

                if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
                    logf("⚠️ Données manquantes (MacAddress/Timestamp) pour $entry → /errors");
                    sftp_safe_move($sftp, $remote, '/errors');
                    $files_error++;
                    continue;
                }

                try {
                    $pdo->beginTransaction();
                    if (isset($st_compteur)) {
                        $binds = [];
                        foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
                        $st_compteur->execute($binds);

                        if ($st_compteur->rowCount() === 1) {
                            $compteurs_inserted++;
                            logf("✅ Compteur INSÉRÉ pour {$values['MacAddress']} ({$values['Timestamp']})");
                        } else {
                            logf("ℹ️ Déjà présent: compteur NON réinséré pour {$values['MacAddress']} ({$values['Timestamp']})");
                        }
                    } else {
                        logf("❌ Statement compteur_relevee indisponible; skip insert");
                    }

                    $pdo->commit();

                    [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
                    if (!$okMove) {
                        logf("⚠️ Impossible de déplacer $entry vers /processed");
                    } else {
                        logf("📦 Archivé: $entry → /processed");
                    }

                } catch (Throwable $e) {
                    try { $pdo->rollBack(); } catch (Throwable $e2) {}
                    logf("❌ [ERREUR PDO] " . $e->getMessage());
                    sftp_safe_move($sftp, $remote, '/errors');
                    $files_error++;
                }
            }

            if (!$found) {
                logf("⚠️ Aucun fichier CSV trouvé sur le SFTP (pattern COPIEUR_MAC-*_YYYYMMDD_HHMMSS.csv).");
            }
        }
    }
} else {
    logf("⏭️ Saut du traitement SFTP (autoload/SFTP indispo).");
}

// ---------- 6) Journal du run (toujours) ----------
try {
    $summary = sprintf(
        "[upload_compteur] files=%d, errors=%d, cmp_inserted=%d",
        $files_processed, $files_error, $compteurs_inserted
    );

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    $stmt->execute([
        ':imported' => max(0, $files_processed - $files_error),
        ':skipped'  => $files_error,
        ':ok'       => ($had_fatal ? 0 : ($files_error === 0 ? 1 : 0)),
        ':msg'      => $summary,
    ]);
    logf("M6: [IMPORT_RUN] Ligne insérée: $summary");
} catch (Throwable $e) {
    logf("❌ [IMPORT_RUN] Erreur INSERT: " . $e->getMessage());
}

logf('-----------------------------');
logf('✅ Traitement terminé');
