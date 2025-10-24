<?php
declare(strict_types=1);

// =========================
//  Affichage erreurs utile
// =========================
error_reporting(E_ALL);
ini_set('display_errors', '1');

// ============================================================
//  0) Normaliser les env MySQL (utile sur Railway) si besoin
// ============================================================
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

// =========================================
//  1) Charger $pdo (doit définir $pdo = PDO)
// =========================================
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

echo "✅ Connexion DB OK\n";

// ==========================
//  2) Connexion SFTP (env)
// ==========================
require __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SFTP;

$sftp_host   = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host';
$sftp_user   = getenv('SFTP_USER') ?: '';
$sftp_pass   = getenv('SFTP_PASS') ?: '';
$sftp_port   = (int)(getenv('SFTP_PORT') ?: 22);
$sftp_root   = getenv('SFTP_PATH') ?: '/';     // répertoire racine à scanner
$sftp_timeout= (int)(getenv('SFTP_TIMEOUT') ?: 30);

$sftp = new SFTP($sftp_host, $sftp_port, $sftp_timeout);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("❌ Erreur de connexion SFTP ($sftp_host:$sftp_port)\n");
}
echo "✅ SFTP connecté sur $sftp_host:$sftp_port, root='$sftp_root'\n";

// Dossiers d’archivage (ignorer erreurs si existent déjà)
@$sftp->mkdir('/processed');
@$sftp->mkdir('/errors');

// Déplacement sûr (avec suffixe horodaté si collision)
function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/' . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.' . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// =====================================
//  3) CSV util: clé/valeur + délimiteur
// =====================================
/**
 * Lit un CSV "Champ,Valeur" (ou "Champ;Valeur"), 2 colonnes, header optionnel.
 * Retourne un tableau associatif [Champ => Valeur].
 */
function parse_csv_kv(string $filepath): array {
    $data = [];

    // Détecter séparateur sur la 1ère ligne
    $first = '';
    $fh = fopen($filepath, 'r');
    if (!$fh) return $data;
    $first = fgets($fh) ?: '';
    $sep = (substr_count($first, ';') > substr_count($first, ',')) ? ';' : ',';
    rewind($fh);

    while (($row = fgetcsv($fh, 0, $sep)) !== false) {
        if (count($row) < 2) continue;
        $k = trim((string)$row[0]);
        $v = trim((string)$row[1]);

        // Skip header si présent
        if (strcasecmp($k, 'Champ') === 0 && strcasecmp($v, 'Valeur') === 0) {
            continue;
        }
        if ($k !== '') $data[$k] = ($v === '' ? null : $v);
    }
    fclose($fh);
    return $data;
}

// Champs attendus (d’après ton exemple)
$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "🚀 Parcours des fichiers CSV...\n";

// ===================================
//  4) Créer tables si pas existantes
// ===================================
try {
    // Table métier
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `compteur_relevee` (
            `Id` INT NOT NULL AUTO_INCREMENT,
            `Timestamp` DATETIME NOT NULL,
            `IpAddress` VARCHAR(45) NULL,
            `Nom` VARCHAR(191) NULL,
            `Model` VARCHAR(191) NULL,
            `SerialNumber` VARCHAR(191) NULL,
            `MacAddress` VARCHAR(32) NOT NULL,
            `Status` VARCHAR(191) NULL,
            `TonerBlack` INT NULL,
            `TonerCyan` INT NULL,
            `TonerMagenta` INT NULL,
            `TonerYellow` INT NULL,
            `TotalPages` INT NULL,
            `FaxPages` INT NULL,
            `CopiedPages` INT NULL,
            `PrintedPages` INT NULL,
            `BWCopies` INT NULL,
            `ColorCopies` INT NULL,
            `MonoCopies` INT NULL,
            `BichromeCopies` INT NULL,
            `BWPrinted` INT NULL,
            `BichromePrinted` INT NULL,
            `MonoPrinted` INT NULL,
            `ColorPrinted` INT NULL,
            `TotalColor` INT NULL,
            `TotalBW` INT NULL,
            `DateInsertion` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`Id`),
            UNIQUE KEY `uniq_mac_ts` (`MacAddress`,`Timestamp`),
            KEY `idx_serial_ts` (`SerialNumber`,`Timestamp`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Table journal des exécutions
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `import_run` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `ran_at` DATETIME NOT NULL,
            `imported` INT NOT NULL,
            `skipped` INT NOT NULL,
            `ok` TINYINT(1) NOT NULL,
            `msg` TEXT,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Throwable $e) {
    exit('❌ Erreur CREATE TABLE: '.$e->getMessage()."\n");
}

// Préparer l'INSERT IGNORE (idempotence grâce à l'unique index)
$cols_compteur = implode(',', array_map(fn($c) => "`$c`", $FIELDS)) . ',`DateInsertion`';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO `compteur_relevee` ($cols_compteur) VALUES ($ph_compteur)";
$st_compteur   = $pdo->prepare($sql_compteur);

// =========================
//  5) Traiter les fichiers
// =========================
$files_processed     = 0;
$compteurs_inserted  = 0;
$files_error         = 0;

$files = $sftp->nlist($sftp_root);
if ($files === false) {
    echo "❌ Impossible de lister '$sftp_root' sur le SFTP\n";
} else {
    $found = false;
    foreach ($files as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!preg_match('/^COPIEUR_MAC-([A-F0-9:\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) continue;

        $found = true;
        $files_processed++;
        $remote = rtrim($sftp_root, '/') . '/' . ltrim($entry, '/');

        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        if (!$sftp->get($remote, $tmp)) {
            echo "❌ Erreur téléchargement $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            @unlink($tmp);
            $files_error++;
            continue;
        }

        $row = parse_csv_kv($tmp);
        @unlink($tmp);

        // Construire le jeu de valeurs
        $values = [];
        foreach ($FIELDS as $f) { $values[$f] = $row[$f] ?? null; }

        // Champs requis pour assurer l'unicité et la cohérence
        if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
            echo "⚠️ Données manquantes (MacAddress/Timestamp) pour $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        // Normaliser Timestamp en DATETIME MySQL
        $ts = date('Y-m-d H:i:s', strtotime((string)$values['Timestamp']));
        $values['Timestamp'] = $ts;

        try {
            $pdo->beginTransaction();

            // binder proprement
            $binds = [];
            foreach ($FIELDS as $f) { $binds[":$f"] = $values[$f]; }

            $st_compteur->execute($binds);

            if ($st_compteur->rowCount() === 1) {
                $compteurs_inserted++;
                echo "✅ INSÉRÉ: {$values['MacAddress']} @ {$values['Timestamp']}\n";
            } else {
                echo "ℹ️ Déjà présent (IGNORE): {$values['MacAddress']} @ {$values['Timestamp']}\n";
            }

            $pdo->commit();

            // Archiver le fichier traité
            [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
            if (!$okMove) {
                echo "⚠️ Impossible de déplacer $entry vers /processed\n";
            } else {
                echo "📦 Archivé: $entry → /processed\n";
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "❌ [PDO] ".$e->getMessage()."\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
        }
    }

    if (!$found) {
        echo "⚠️ Aucun fichier CSV trouvé dans '$sftp_root'.\n";
    }
}

// ==========================
//  6) Journaliser le run
// ==========================
try {
    $summary = sprintf(
        "[upload_compteur] files=%d, errors=%d, inserted=%d",
        $files_processed, $files_error, $compteurs_inserted
    );

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    $stmt->execute([
        ':imported' => max(0, $files_processed - $files_error),
        ':skipped'  => $files_error,
        ':ok'       => ($files_error === 0 ? 1 : 0),
        ':msg'      => $summary,
    ]);
    echo "📝 import_run → $summary\n";
} catch (Throwable $e) {
    echo "❌ [IMPORT_RUN] ".$e->getMessage()."\n";
}

echo "-----------------------------\n";
echo "✅ Fin traitement.\n";
