<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * API/scripts/upload_compteur.php
 *
 * - Récupère les CSV sur un SFTP (phpseclib3)
 * - Insère dans `compteur_relevee` (INSERT IGNORE)
 * - UPSERT 1 ligne/jour dans `facture_relevee` (ON DUPLICATE KEY UPDATE)
 * - Archive les fichiers du SFTP en /processed ou /errors
 * - Journalise un résumé dans `import_run`
 *
 * Variables d'environnement attendues (Railway):
 *   SFTP_HOST, SFTP_PORT (22), SFTP_USER, SFTP_PASS
 *
 * Remarques:
 * - `mac_norm` est une colonne générée dans `compteur_relevee`: on n'y touche pas.
 * - Pour que l'UPSERT fonctionne dans `facture_relevee`, il faut une contrainte UNIQUE
 *   (par ex. UNIQUE (MacAddress, DateRelevee)).
 */

require __DIR__ . '/../vendor/autoload.php';

// ---------- 1) Charger $pdo depuis includes/db.php (Railway) ----------
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../../config/db.php',
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
use phpseclib3\Net\SFTP;

$sftp_host = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host';
$sftp_port = (int)(getenv('SFTP_PORT') ?: '22');
$sftp_user = getenv('SFTP_USER') ?: '';
$sftp_pass = getenv('SFTP_PASS') ?: '';

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
                $data[trim($row[0])] = trim((string)$row[1]);
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
/* compteur_relevee : INSERT IGNORE */
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$ph_compteur   = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur  = "INSERT IGNORE INTO compteur_relevee ($cols_compteur) VALUES ($ph_compteur)";
$st_compteur   = $pdo->prepare($sql_compteur);

/* facture_relevee : UPSERT (1/jour) */
$cols_facture = $FIELDS;
$cols_facture[] = 'DateRelevee';
$cols_facture[] = 'DateInsertion';

$insert_cols_facture = implode(',', $cols_facture);
$insert_vals_facture = ':' . implode(',:', $cols_facture);

$update_parts = [];
foreach ($FIELDS as $f) {
    if ($f === 'MacAddress' || $f === 'Timestamp') continue; // ces champs servent à regrouper
    $update_parts[] = "$f = VALUES($f)";
}
$update_parts[] = "DateInsertion = NOW()";

$sql_facture = "
    INSERT INTO facture_relevee ($insert_cols_facture)
    VALUES ($insert_vals_facture)
    ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);

$st_facture = $pdo->prepare($sql_facture);

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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci
    ");
} catch (Throwable $e) {
    // non bloquant
}

// Compteurs de run (pour import_run)
$files_processed    = 0;  // fichiers CSV conformes (match regex)
$compteurs_inserted = 0;  // lignes réellement insérées (INSERT IGNORE) dans compteur_relevee
$factures_inserted  = 0;  // insert dans facture_relevee (rowCount == 1)
$factures_updated   = 0;  // update dans facture_relevee (rowCount >= 2)
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
        foreach ($FIELDS as $f) {
            $values[$f] = $row[$f] ?? null;
        }

        // Contrôle minimal
        if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
            echo "Données manquantes (MacAddress/Timestamp) pour $entry → /errors\n";
            sftp_safe_move($sftp, $remote, '/errors');
            $files_error++;
            continue;
        }

        $date_relevee = date('Y-m-d', strtotime((string)$values['Timestamp']) ?: time());

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

            // 2) facture_relevee (UPSERT)
            $binds_fact = [];
            foreach ($FIELDS as $f) $binds_fact[":$f"] = $values[$f];
            $binds_fact[':DateRelevee']   = $date_relevee;
            $binds_fact[':DateInsertion'] = date('Y-m-d H:i:s');

            $st_facture->execute($binds_fact);

            $aff = $st_facture->rowCount();
            if ($aff === 1) {
                $factures_inserted++;
                echo "Facture INSÉRÉE pour {$values['MacAddress']} ($date_relevee)\n";
            } elseif ($aff >= 2) {
                $factures_updated++;
                echo "Facture MISE À JOUR pour {$values['MacAddress']} ($date_relevee)\n";
            } else {
                echo "Facture: $aff lignes affectées pour {$values['MacAddress']} ($date_relevee)\n";
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
        "[upload_compteur] files=%d, errors=%d, cmp_inserted=%d, fac_ins=%d, fac_upd=%d",
        $files_processed, $files_error, $compteurs_inserted, $factures_inserted, $factures_updated
    );

    $stmt = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    $stmt->execute([
        ':imported' => max(0, $files_processed - $files_error),
        ':skipped'  => $files_error,
        ':ok'       => ($files_error === 0 ? 1 : 0), // 1 si aucun fichier en erreur, sinon 0
        ':msg'      => $summary,
    ]);
} catch (Throwable $e) {
    // silencieux
}

echo "-----------------------------\n";
echo "Traitement terminé.\n";
