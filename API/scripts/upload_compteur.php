<?php
declare(strict_types=1);

// ✅ Affiche toutes les erreurs PHP et PDO dans Railway
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * upload_compteur.php (version avec logs détaillés)
 * - Connexion SFTP
 * - Import CSV compteur_relevee
 * - Log dans import_run
 */

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
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../includes/db.php',
];
$ok = false;
foreach ($paths as $p) {
    if (is_file($p)) {
        require_once $p;
        $ok = true;
        break;
    }
}
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit("❌ Erreur: impossible de charger includes/db.php et obtenir \$pdo\n");
}

echo "✅ Connexion à la base établie.\n";

// ---------- 2) Connexion SFTP ----------
require __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SFTP;

// Utiliser uniquement les variables d'environnement pour la sécurité
$sftp_host = getenv('SFTP_HOST') ?: '';
$sftp_user = getenv('SFTP_USER') ?: '';
$sftp_pass = getenv('SFTP_PASS') ?: '';
$sftp_port = (int)(getenv('SFTP_PORT') ?: 22);

if (empty($sftp_host) || empty($sftp_user) || empty($sftp_pass)) {
    http_response_code(500);
    exit("❌ Erreur: Variables d'environnement SFTP manquantes (SFTP_HOST, SFTP_USER, SFTP_PASS)\n");
}

$sftp = new SFTP($sftp_host, $sftp_port);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("❌ Erreur de connexion SFTP\n");
}

echo "✅ Connexion SFTP établie.\n";

// ---------- Création dossiers SFTP ----------
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

// ---------- 3) Utilitaires ----------
function parse_csv_kv(string $filepath): array {
    $data = [];
    if (($h = fopen($filepath, 'r')) !== false) {
        while (($row = fgetcsv($h, 2000, ',')) !== false) {
            if (isset($row[0], $row[1])) {
                $data[trim($row[0])] = trim((string)$row[1]);
            }
        }
        fclose($h);
    }
    return $data;
}

$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "🚀 Traitement des fichiers CSV...\n";

// ---------- 4) Requêtes PDO ----------
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
$st_compteur   = $pdo->prepare($sql_compteur);

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
    echo "⚠️ [IMPORT_RUN] Erreur CREATE TABLE: " . $e->getMessage() . "\n";
}

$files_processed = 0;
$compteurs_inserted = 0;
$files_error = 0;

// ---------- 5) Parcours fichiers ----------
$files = $sftp->nlist('/');
if ($files === false) {
    echo "❌ Impossible d’ouvrir le dossier racine SFTP\n";
} else {
    $found = false;
    foreach ($files as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        if (!preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) continue;

        $found = true;
        $files_processed++;
        $remote = '/' . $entry;
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        if (!$sftp->get($remote, $tmp)) {
            echo "❌ Erreur téléchargement $entry\n";
            sftp_safe_move($sftp, $remote, '/errors');
            @unlink($tmp);
            $files_error++;
            continue;
        }

        $row = parse_csv_kv($tmp);
        @unlink($tmp);

        $values = [];
        foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;

        if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
            echo "⚠️ Données manquantes (MacAddress/Timestamp) pour $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        try {
            $pdo->beginTransaction();
            $binds = [];
            foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
            $st_compteur->execute($binds);

            if ($st_compteur->rowCount() === 1) {
                $compteurs_inserted++;
                echo "✅ Compteur INSÉRÉ pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            } else {
                echo "ℹ️ Déjà présent: compteur NON réinséré pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            }

            $pdo->commit();

            [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
            if (!$okMove) {
                echo "⚠️ Impossible de déplacer $entry vers /processed\n";
            } else {
                echo "📦 Archivé: $entry → /processed\n";
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "❌ [ERREUR PDO] " . $e->getMessage() . "\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
        }
    }

    if (!$found) {
        echo "⚠️ Aucun fichier CSV trouvé sur le SFTP.\n";
    }
}

// ---------- 6) Journal du run ----------
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
        ':ok'       => ($files_error === 0 ? 1 : 0),
        ':msg'      => $summary,
    ]);
    echo "📝 [IMPORT_RUN] Ligne insérée: $summary\n";
} catch (Throwable $e) {
    echo "❌ [IMPORT_RUN] Erreur INSERT: " . $e->getMessage() . "\n";
}

echo "-----------------------------\n";
echo "✅ Traitement terminé.\n";
