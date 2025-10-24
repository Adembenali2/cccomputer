<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * API/scripts/upload_compteur.php (version "compteur only")
 *
 * - Récupère les CSV sur un SFTP (phpseclib3)
 * - Insère dans `compteur_relevee` (INSERT IGNORE)
 * - Archive les fichiers du SFTP en /processed ou /errors
 * - Journalise un résumé dans `import_run`
 *
 * NOTE: Ce script n'exige AUCUNE modif de includes/db.php.
 * Il alimente les variables MYSQLHOST/PORT/DATABASE/USER/PASSWORD
 * à partir de MYSQL_PUBLIC_URL (ou DATABASE_URL) si besoin.
 */

// ---------- 0) Normaliser les variables d'env pour ton db.php ----------
(function (): void {
    $needs = !getenv('MYSQLHOST') || !getenv('MYSQLDATABASE') || !getenv('MYSQLUSER');
    if (!$needs) return;

    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL') ?: '';
    if (!$url) return; // on laisse db.php échouer proprement si rien n'est dispo

    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) return;

    $host = $p['host'];
    $port = isset($p['port']) ? (string)$p['port'] : '3306';
    $user = isset($p['user']) ? urldecode($p['user']) : '';
    $pass = isset($p['pass']) ? urldecode($p['pass']) : '';
    $db   = ltrim($p['path'], '/');

    putenv("MYSQLHOST={$host}");
    putenv("MYSQLPORT={$port}");
    putenv("MYSQLUSER={$user}");
    putenv("MYSQLPASSWORD={$pass}");
    putenv("MYSQLDATABASE={$db}");
})();

// ---------- 1) Charger $pdo depuis includes/db.php ----------
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../../includes/db.php',
];
$ok = false;
foreach ($paths as $p) {
    if (is_file($p)) { require_once $p; $ok = true; break; }
}
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit("Erreur: impossible de charger includes/db.php et obtenir \$pdo\n");
}

// ---------- 2) Connexion SFTP ----------
require __DIR__ . '/../vendor/autoload.php';
use phpseclib3\Net\SFTP;

$sftp_host = 'home298245733.1and1-data.host';
$sftp_user = 'acc984891385';
$sftp_pass = 'RTC@4oEMh?orqP&pgir5rz&f';
$sftp_port = 22;

$sftp = new SFTP($sftp_host, $sftp_port);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("Erreur de connexion SFTP\n");
}

// Préparer les dossiers d'archivage
@$sftp->mkdir('/processed');
@$sftp->mkdir('/errors');

// Safe move sur le SFTP (avec fallback si fichier existe)
function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    $alt = rtrim($toDir, '/') . '/'
         . pathinfo($basename, PATHINFO_FILENAME)
         . '_' . date('Ymd_His') . '.'
         . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// ---------- 3) Utilitaires ----------
function parse_csv_kv(string $filepath): array {
    $data = [];
    if (($h = fopen($filepath, 'r')) !== false) {
        while (($row = fgetcsv($h, 2000, ',')) !== false) {
            if (isset($row[0], $row[1])) {
                // Trim + cast simple
                $k = trim($row[0]);
                $v = trim((string)$row[1]);
                $data[$k] = $v;
            }
        }
        fclose($h);
    }
    return $data;
}

// Champs attendus
$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "Traitement des nouveaux fichiers CSV...\n";

// ---------- 4) Préparer les requêtes PDO ----------
// compteur_relevee : INSERT IGNORE
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
$st_compteur   = $pdo->prepare($sql_compteur);

// (Optionnel) créer la table de log si absente (singulier: import_run)
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
    echo "[IMPORT_RUN] Erreur CREATE TABLE: " . $e->getMessage() . "\n";
}

// Compteurs de run (pour import_run)
$files_processed    = 0;  // fichiers CSV conformes (match regex)
$compteurs_inserted = 0;  // lignes réellement insérées (INSERT IGNORE) dans compteur_relevee
$files_error        = 0;  // fichiers envoyés en /errors

// ---------- 5) Lister et traiter les fichiers ----------
$files = $sftp->nlist('/');
if ($files === false) {
    echo "Impossible d’ouvrir le dossier racine SFTP\n";
} else {
    foreach ($files as $entry) {
        if ($entry === '.' || $entry === '..') continue;

        // Exemple: COPIEUR_MAC-XX-XX-XX-XX-XX-XX_YYYYMMDD_HHMMSS.csv
        if (!preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) {
            continue;
        }
        $files_processed++;

        $remote = '/' . $entry;
        $tmp = tempnam(sys_get_temp_dir(), 'csv_');
        if (!$sftp->get($remote, $tmp)) {
            echo "Erreur téléchargement $entry\n";
            sftp_safe_move($sftp, $remote, '/errors');
            @unlink($tmp);
            $files_error++;
            continue;
        }

        $row = parse_csv_kv($tmp);
        @unlink($tmp);

        // Construire array des valeurs
        $values = [];
        foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;

        // Contrôle minimal
        if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
            echo "Données manquantes (MacAddress/Timestamp) pour $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        // Transaction par fichier
        $pdo->beginTransaction();
        try {
            // 1) compteur_relevee (INSERT IGNORE)
            $binds = [];
            foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
            $st_compteur->execute($binds);

            if ($st_compteur->rowCount() === 1) {
                $compteurs_inserted++;
                echo "Compteur INSÉRÉ pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            } else {
                echo "Déjà présent: compteur NON réinséré pour {$values['MacAddress']} ({$values['Timestamp']})\n";
            }

            $pdo->commit();

            // ARCHIVAGE → /processed
            [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
            if (!$okMove) {
                echo "⚠️  Impossible de déplacer $entry vers /processed\n";
            } else {
                echo "Archivé: $entry → /processed\n";
            }

        } catch (Throwable $e) {
            $pdo->rollBack();
            echo "Erreur DB pour {$values['MacAddress']} ({$values['Timestamp']}) : "
               . $e->getMessage() . "\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
        }
    }
}

// ---------- 6) Journal du run dans import_run ----------
try {
    $summary = sprintf(
        "[upload_compteur] files=%d, errors=%d, cmp_inserted=%d",
        $files_processed, $files_error, $compteurs_inserted
    );

    // S'assure que la table existe (au cas où)
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
    echo "[IMPORT_RUN] Ligne insérée.\n";
} catch (Throwable $e) {
    echo "[IMPORT_RUN] Erreur INSERT: " . $e->getMessage() . "\n";
}

echo "-----------------------------\n";
echo "Traitement terminé.\n";
