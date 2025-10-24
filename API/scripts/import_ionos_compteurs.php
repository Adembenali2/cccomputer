<?php
/**
 * scripts/import_ionos_compteurs.php
 * Importe les compteurs et niveaux de toner depuis IONOS
 * vers la table `compteur_relevee` de la base Railway.
 *
 * Variables d'environnement attendues :
 * - Railway : MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD
 * - IONOS   : IONOS_HOST, IONOS_PORT, IONOS_DB, IONOS_USER, IONOS_PASS
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

// ---------- Helpers génériques ----------
function println(string $s = ''): void { echo $s . PHP_EOL; }
function fail(string $s, int $code = 1): void { fwrite(STDERR, $s . PHP_EOL); exit($code); }

function normalizeMac(?string $mac): ?string {
    if ($mac === null) return null;
    $m = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
    return $m !== '' ? $m : null;
}
function iOrNull($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}

// ---------- Connexion DB Railway (cible) ----------
$host = getenv('MYSQLHOST') ?: '';
$port = (string)(getenv('MYSQLPORT') ?: '3306');
$db   = getenv('MYSQLDATABASE') ?: '';
$user = getenv('MYSQLUSER') ?: '';
$pass = getenv('MYSQLPASSWORD') ?: '';
$charset = 'utf8mb4';

if ($host === '' || $db === '' || $user === '') {
    fail("Variables d'environnement Railway manquantes : MYSQLHOST / MYSQLDATABASE / MYSQLUSER (et MYSQLPASSWORD si besoin).");
}

$dsnRailway = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
try {
    $pdo = new PDO($dsnRailway, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fail("Connexion Railway échouée : " . $e->getMessage());
}

// ---------- Connexion DB IONOS (source) ----------
$ionosHost = getenv('IONOS_HOST') ?: 'db550618985.db.1and1.com';
$ionosPort = (string)(getenv('IONOS_PORT') ?: '3306');
$ionosDb   = getenv('IONOS_DB')   ?: 'db550618985';
$ionosUser = getenv('IONOS_USER') ?: 'dbo550618985';
$ionosPass = getenv('IONOS_PASS') ?: ''; // <- mets la valeur dans Railway Variables

$dsnIonos = "mysql:host={$ionosHost};port={$ionosPort};dbname={$ionosDb};charset=utf8";
try {
    $pdoIonos = new PDO($dsnIonos, $ionosUser, $ionosPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    fail("Connexion IONOS échouée : " . $e->getMessage());
}

// ---------- (Optionnel) S'assurer de l'unicité (mac_norm, Timestamp) ----------
try {
    // MySQL n'a pas de IF NOT EXISTS pour les index -> on tente/catche
    $pdo->exec("ALTER TABLE `compteur_relevee` ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`,`Timestamp`)");
    println("Index unique `uniq_mac_ts` créé (mac_norm, Timestamp).");
} catch (Throwable $e) {
    // Probablement déjà créé ; on ignore
}

// ---------- Récup toners (depuis consommable.tmp_arr) ----------
/**
 * Retourne les derniers niveaux de toner pour un ref_client
 * au format : ['k'=>?int,'c'=>?int,'m'=>?int,'y'=>?int]
 */
function getLatestTonersForRefClient(PDO $pdoIonos, string $refClient): array {
    $sql = "SELECT tmp_arr, `date` FROM consommable WHERE ref_client = :ref ORDER BY `date` DESC LIMIT 50";
    $stmt = $pdoIonos->prepare($sql);
    $stmt->execute([':ref' => $refClient]);

    $best = ['k'=>null,'c'=>null,'m'=>null,'y'=>null];
    $bestTs = null;

    while ($row = $stmt->fetch()) {
        $raw = $row['tmp_arr'];
        if (!$raw) continue;

        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || empty($data)) continue;

        foreach ($data as $k => $arr) {
            if (!is_array($arr)) continue;

            $tNoir   = $arr['toner_noir']    ?? null;
            $tCyan   = $arr['toner_cyan']    ?? null;
            $tMag    = $arr['toner_magenta'] ?? null;
            $tJaune  = $arr['toner_jaune']   ?? null;
            $tDate   = $arr['tdate'] ?? ($arr['cdate'] ?? ($arr['date'] ?? null));

            // Utilise soit tdate/cdate soit la date de la ligne consommable
            $ts = $tDate ? strtotime((string)$tDate) : (strtotime((string)$row['date']) ?: 0);
            if ($ts === false) $ts = 0;

            $hasAny = isset($tNoir) || isset($tCyan) || isset($tMag) || isset($tJaune);
            if (!$hasAny) continue;

            if ($bestTs === null || $ts > $bestTs) {
                $bestTs = $ts;
                $best = [
                    'k' => is_numeric($tNoir)  ? (int)$tNoir  : null,
                    'c' => is_numeric($tCyan)  ? (int)$tCyan  : null,
                    'm' => is_numeric($tMag)   ? (int)$tMag   : null,
                    'y' => is_numeric($tJaune) ? (int)$tJaune : null,
                ];
            }
        }
    }

    // clamp (sécurité)
    foreach ($best as $color => $v) {
        if ($v === null) continue;
        $best[$color] = max(-100, min(100, (int)$v));
    }
    return $best;
}

// ---------- Lecture des compteurs IONOS ----------
println("Lecture des compteurs IONOS...");
$sql = "SELECT ref_client, mac, `date`, totalNB, totalCouleur, etat FROM last_compteur ORDER BY `date` DESC";
$ionosRows = $pdoIonos->query($sql)->fetchAll();

if (!$ionosRows) {
    println("Aucun compteur trouvé sur IONOS.");
    exit(0);
}

// ---------- Prépare l'UPSERT cible ----------
$insertSql = "
INSERT INTO compteur_relevee
    (mac_norm, MacAddress, `Timestamp`,
     TotalBW, TotalColor, TotalPages,
     TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
     Status, SerialNumber, Model, Nom)
VALUES
    (:mac_norm, :mac_addr, :ts,
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
$ins = $pdo->prepare($insertSql);

// ---------- Import ----------
$pdo->beginTransaction();
$imported = 0;
$skipped  = 0;

try {
    $tonerCache = [];

    foreach ($ionosRows as $r) {
        $ref  = trim((string)$r['ref_client']);
        $mac  = trim((string)$r['mac']);
        $macN = normalizeMac($mac);
        if (!$macN) { $skipped++; continue; }

        $tsStr = (string)$r['date'];
        $tsSql = date('Y-m-d H:i:s', strtotime($tsStr) ?: time());

        $totBW  = iOrNull($r['totalNB']);
        $totCol = iOrNull($r['totalCouleur']);
        $totAll = ($totBW ?? 0) + ($totCol ?? 0);

        $status = isset($r['etat']) ? (int)$r['etat'] : null;

        if (!array_key_exists($ref, $tonerCache)) {
            $tonerCache[$ref] = getLatestTonersForRefClient($pdoIonos, $ref);
        }
        $t = $tonerCache[$ref];

        $ins->execute([
            ':mac_norm'  => $macN,
            ':mac_addr'  => $mac ?: null,
            ':ts'        => $tsSql,
            ':tot_bw'    => $totBW,
            ':tot_col'   => $totCol,
            ':tot_pages' => $totAll,
            ':t_k'       => $t['k'],
            ':t_c'       => $t['c'],
            ':t_m'       => $t['m'],
            ':t_y'       => $t['y'],
            ':status'    => $status,
            ':sn'        => null, // pas disponibles côté IONOS
            ':model'     => null,
            ':nom'       => null,
        ]);

        $imported++;
    }

    $pdo->commit();
    println("Import terminé. Insérés/MAJ: {$imported} — Ignorés (MAC invalide): {$skipped}");

} catch (Throwable $e) {
    $pdo->rollBack();
    fail("Erreur pendant l'import : " . $e->getMessage());
}
