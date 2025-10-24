<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

/**
 * upload_compteur.php
 * - Récupère les CSV sur un SFTP (phpseclib3)
 * - Insère les relevés dans `compteur_relevee` (INSERT IGNORE)
 * - Upsert 1 ligne/jour dans `facture_relevee` (ON DUPLICATE KEY UPDATE)
 * - Archive les fichiers en /processed ou /errors côté SFTP
 *
 * Variables d'env attendues (Railway):
 *   SFTP_HOST, SFTP_PORT (22), SFTP_USER, SFTP_PASS
 */

require __DIR__ . '/../vendor/autoload.php';

// ----- PDO (Railway) -----
$paths = [
    __DIR__ . '/../includes/db.php',
    __DIR__ . '/../config/db.php',
    __DIR__ . '/../../includes/db.php',
    __DIR__ . '/../../config/db.php',
];
$ok = false;
foreach ($paths as $p) { if (is_file($p)) { require_once $p; $ok = true; break; } }
if (!$ok || !isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(500);
    exit("Erreur: impossible de charger includes/db.php et obtenir \$pdo");
}

// ----- SFTP (phpseclib) -----
use phpseclib3\Net\SFTP;

$sftp_host = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host';
$sftp_port = (int)(getenv('SFTP_PORT') ?: '22');
$sftp_user = getenv('SFTP_USER') ?: '';
$sftp_pass = getenv('SFTP_PASS') ?: '';

$sftp = new SFTP($sftp_host, $sftp_port);
if (!$sftp->login($sftp_user, $sftp_pass)) {
    http_response_code(500);
    exit("Erreur de connexion SFTP");
}

// Crée les dossiers d’archivage si besoin (no-op si existent)
@$sftp->mkdir('/processed');
@$sftp->mkdir('/errors');

// Déplacement sécurisé sur le SFTP
function sftp_safe_move(SFTP $sftp, string $from, string $toDir): array {
    $basename = basename($from);
    $target   = rtrim($toDir, '/') . '/' . $basename;
    if ($sftp->rename($from, $target)) return [true, $target];

    // suffixe horodaté si déjà présent
    $alt = rtrim($toDir, '/') . '/'
        . pathinfo($basename, PATHINFO_FILENAME)
        . '_' . date('Ymd_His') . '.'
        . pathinfo($basename, PATHINFO_EXTENSION);
    if ($sftp->rename($from, $alt)) return [true, $alt];

    return [false, null];
}

// Lecture CSV -> array clef/valeur
function parse_csv_kv(string $filepath): array {
    $data = [];
    if (($h = fopen($filepath, 'r')) !== false) {
        while (($row = fgetcsv($h, 2000, ',')) !== false) {
            if (isset($row[0], $row[1])) $data[trim($row[0])] = trim($row[1]);
        }
        fclose($h);
    }
    return $data;
}

// Champs attendus (doivent correspondre aux colonnes de `compteur_relevee`/`facture_relevee`)
$FIELDS = [
    'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress',
    'Status','TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
    'TotalPages','FaxPages','CopiedPages','PrintedPages','BWCopies',
    'ColorCopies','MonoCopies','BichromeCopies','BWPrinted','BichromePrinted',
    'MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

echo "<h2>Traitement des nouveaux fichiers CSV...</h2>";

// Liste des fichiers à la racine du SFTP
$files = $sftp->nlist('/');
if ($files === false) exit("Impossible d’ouvrir le dossier racine SFTP");

//
// Prépare les requêtes PDO
//

// 1) compteur_relevee : INSERT IGNORE
$cols_compteur = implode(',', $FIELDS) . ',DateInsertion';
$placeholders_compteur = ':' . implode(',:', $FIELDS) . ',NOW()';
$sql_compteur = "INSERT IGNORE INTO compteur_relevee ($cols_compteur)
                 VALUES ($placeholders_compteur)";
$st_compteur  = $pdo->prepare($sql_compteur);

// 2) facture_relevee : UPSERT (1 ligne par MacAddress + DateRelevee)
// On suppose qu’il existe une contrainte UNIQUE appropriée (ex: UNIQUE (MacAddress, DateRelevee))
$cols_facture              = $FIELDS;                   // mêmes colonnes…
$cols_facture[]            = 'DateRelevee';
$cols_facture[]            = 'DateInsertion';

$insert_cols_facture       = implode(',', $cols_facture);
$insert_vals_facture_names = ':' . implode(',:', $cols_facture);

$update_parts = [];
foreach ($FIELDS as $f) {
    if ($f === 'MacAddress' || $f === 'Timestamp') continue; // clés de regroupement
    $update_parts[] = "$f = VALUES($f)";
}
$update_parts[] = "DateInsertion = NOW()";

$sql_facture = "
    INSERT INTO facture_relevee ($insert_cols_facture)
    VALUES ($insert_vals_facture_names)
    ON DUPLICATE KEY UPDATE " . implode(', ', $update_parts);

$st_facture = $pdo->prepare($sql_facture);

//
// Boucle de traitement
//
foreach ($files as $entry) {
    if ($entry === '.' || $entry === '..') continue;

    // Fichiers de la forme: COPIEUR_MAC-XX-XX-XX-XX-XX-XX_YYYYMMDD_HHMMSS.csv
    if (!preg_match('/^COPIEUR_MAC-([A-F0-9\-]+)_(\d{8}_\d{6})\.csv$/i', $entry)) {
        continue;
    }

    $remote = '/' . $entry;
    $tmp = tempnam(sys_get_temp_dir(), 'csv_');
    if (!$sftp->get($remote, $tmp)) {
        echo "<p style='color:red'>Erreur téléchargement $entry</p>";
        sftp_safe_move($sftp, $remote, '/errors');
        @unlink($tmp);
        continue;
    }

    $row = parse_csv_kv($tmp);
    @unlink($tmp);

    // Map vers $values (null si absent)
    $values = [];
    foreach ($FIELDS as $f) $values[$f] = $row[$f] ?? null;

    // Contrôle minimal
    if (empty($values['MacAddress']) || empty($values['Timestamp'])) {
        echo "<p style='color:orange'>Données manquantes (MacAddress/Timestamp) pour $entry → /errors</p>";
        sftp_safe_move($sftp, $remote, '/errors');
        continue;
    }

    // Normalise la date relevée pour facture (YYYY-MM-DD)
    $date_relevee = date('Y-m-d', strtotime((string)$values['Timestamp']) ?: time());

    // TRANSACTION par fichier (facultatif, mais propre)
    $pdo->beginTransaction();
    try {
        // --- 1) compteur_relevee (INSERT IGNORE) ---
        $binds = [];
        foreach ($FIELDS as $f) $binds[":$f"] = $values[$f];
        $st_compteur->execute($binds);

        if ($st_compteur->rowCount() === 1) {
            echo "<p style='color:green'>Compteur inséré pour {$values['MacAddress']} ({$values['Timestamp']})</p>";
        } else {
            echo "<p style='color:blue'>Déjà présent: compteur NON réinséré pour {$values['MacAddress']} ({$values['Timestamp']})</p>";
        }

        // --- 2) facture_relevee (UPSERT) ---
        $binds_fact = [];
        foreach ($FIELDS as $f) $binds_fact[":$f"] = $values[$f];
        $binds_fact[':DateRelevee']   = $date_relevee;
        $binds_fact[':DateInsertion'] = date('Y-m-d H:i:s');

        $st_facture->execute($binds_fact);

        // rowCount peut retourner 1 (insert) ou 2 (update) selon MySQL/PDO
        $aff = $st_facture->rowCount();
        if ($aff === 1) {
            echo "<p style='color:green'>Facture insérée pour {$values['MacAddress']} ($date_relevee)</p>";
        } elseif ($aff >= 2) {
            echo "<p style='color:blue'>Facture mise à jour pour {$values['MacAddress']} ($date_relevee)</p>";
        } else {
            echo "<p style='color:purple'>Facture : $aff lignes affectées pour {$values['MacAddress']} ($date_relevee)</p>";
        }

        $pdo->commit();

        // Déplacement -> /processed
        [$okMove, ] = sftp_safe_move($sftp, $remote, '/processed');
        if (!$okMove) {
            echo "<p style='color:orange'>⚠️ Impossible de déplacer $entry vers /processed</p>";
        } else {
            echo "<small>Archivé : $entry → /processed</small>";
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo "<p style='color:red'>Erreur DB pour {$values['MacAddress']} ({$values['Timestamp']}) : "
            . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</p>";

        // Déplacement -> /errors pour éviter de retraiter
        sftp_safe_move($sftp, $remote, '/errors');
    }
}

echo "<hr>Traitement terminé.";
