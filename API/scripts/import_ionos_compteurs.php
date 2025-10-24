<?php
/**
 * scripts/import_ionos_compteurs.php
 *
 * Importe les compteurs (et toner) depuis IONOS
 * vers la table compteur_relevee de la base Railway.
 */

declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/db.php'; // -> $pdo (Railway)

// ---------- Connexion IONOS ----------
$ionosDsn  = 'mysql:host=db550618985.db.1and1.com;port=3306;dbname=db550618985;charset=utf8';
$ionosUser = 'dbo550618985';
$ionosPass = 'kcamsoncamson'; // <<< mets le vrai mot de passe

try {
    $pdoIonos = new PDO($ionosDsn, $ionosUser, $ionosPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (Throwable $e) {
    error_log('IONOS connect error: ' . $e->getMessage());
    http_response_code(500);
    exit("Impossible de se connecter à la base IONOS.");
}

// ---------- Helpers ----------
function normalizeMac(?string $mac): ?string {
    if ($mac === null) return null;
    $m = strtoupper(preg_replace('/[^0-9A-F]/i', '', $mac));
    return $m !== '' ? $m : null;
}
function iOrNull($v): ?int {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    return (int)$v;
}

/**
 * Extrait les derniers niveaux de toner pour un ref_client
 * en lisant consommable.tmp_arr (PHP serialized).
 * On prend la dernière entrée (par tdate ou date) qui contient
 * les 4 clés toner_*.
 *
 * Retourne: ['k'=>int|null,'c'=>int|null,'m'=>int|null,'y'=>int|null]  (0..100 ou valeurs négatives si stockées ainsi)
 */
function getLatestTonersForRefClient(PDO $pdoIonos, string $refClient): array {
    // On regarde plusieurs lignes récentes pour maximiser les chances
    $sql = "SELECT tmp_arr, date FROM consommable WHERE ref_client = :ref ORDER BY date DESC LIMIT 50";
    $stmt = $pdoIonos->prepare($sql);
    $stmt->execute([':ref' => $refClient]);

    $best = ['k'=>null,'c'=>null,'m'=>null,'y'=>null];
    $bestTs = null;

    while ($row = $stmt->fetch()) {
        $raw = $row['tmp_arr'];
        if (!$raw) continue;

        // Unserialize en bloquant les classes
        $data = @unserialize($raw, ['allowed_classes' => false]);
        if (!is_array($data) || empty($data)) continue;

        // Les clés semblent être des codes "ACH2022..." => on prend la dernière par date si possible
        foreach ($data as $k => $arr) {
            if (!is_array($arr)) continue;

            $tNoir   = $arr['toner_noir']   ?? null;
            $tCyan   = $arr['toner_cyan']   ?? null;
            $tMag    = $arr['toner_magenta']?? null;
            $tJaune  = $arr['toner_jaune']  ?? null;
            $tDate   = $arr['tdate']        ?? ($arr['cdate'] ?? ($arr['date'] ?? null));

            // On n’utilise que les enregistrements qui ont les 4 toners
            $hasAll = (isset($tNoir) || isset($tCyan) || isset($tMag) || isset($tJaune));
            if (!$hasAll) continue;

            // Timestamp comparatif
            $ts = $tDate ? strtotime((string)$tDate) : (strtotime((string)$row['date']) ?: 0);
            if ($ts === false) $ts = 0;

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

    // Clamp (optionnel) entre -100 et 100 pour éviter les débordements
    foreach ($best as $color => $v) {
        if ($v === null) continue;
        $best[$color] = max(-100, min(100, (int)$v));
    }

    return $best;
}

// ---------- Lecture des compteurs IONOS ----------
$sql = "SELECT ref_client, mac, `date`, totalNB, totalCouleur, etat FROM last_compteur ORDER BY `date` DESC";
$rows = $pdoIonos->query($sql)->fetchAll();

if (!$rows) {
    echo "Aucun compteur à importer.\n";
    exit;
}

// ---------- Préparation insert dans Railway.compteur_relevee ----------
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

// ---------- Transaction ----------
$pdo->beginTransaction();
$imported = 0;
$skipped  = 0;

try {
    // Pour limiter les appels à consommable, on met un petit cache par ref_client
    $tonerCache = [];

    foreach ($rows as $r) {
        $ref  = trim((string)$r['ref_client']);
        $mac  = trim((string)$r['mac']);
        $macN = normalizeMac($mac);
        if (!$macN) { $skipped++; continue; }

        $tsStr  = (string)$r['date'];
        $tsSql  = date('Y-m-d H:i:s', strtotime($tsStr) ?: time());

        $totBW  = iOrNull($r['totalNB']);
        $totCol = iOrNull($r['totalCouleur']);
        $totAll = ($totBW ?? 0) + ($totCol ?? 0);

        // status: mappe etat (tinyint) vers qqchose proche de ce que tu utilises (optionnel)
        $status = isset($r['etat']) ? (int)$r['etat'] : null;

        // Toners: récup depuis cache sinon IONOS
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
            ':t_m'       => $t['m'], // <<< TonerMagenta alimenté ici
            ':t_y'       => $t['y'],
            ':status'    => $status,

            // Pas d’info côté IONOS pour ces champs → NULL
            ':sn'        => null,
            ':model'     => null,
            ':nom'       => null,
        ]);

        $imported++;
    }

    $pdo->commit();
    echo "Import terminé. Insérés/MAJ: $imported — Ignorés (MAC invalide): $skipped\n";

} catch (Throwable $e) {
    $pdo->rollBack();
    error_log('Import IONOS error: ' . $e->getMessage());
    http_response_code(500);
    exit("Erreur pendant l’import: " . $e->getMessage());
}
