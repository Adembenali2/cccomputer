<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/**
 * Ce script lit les données IONOS (printer_info, last_compteur, consommable.tmp_arr)
 * et alimente ta base "table_plus" :
 *  - last_compteur (UPSERT par mac)
 *  - compteur_relevee (INSERT si pas déjà présent grâce à ux_mac_ts)
 *
 * PRÉREQUIS ENV :
 *  SOURCE_* = accès DB IONOS
 *  DEST_*   = accès DB table_plus
 */

function pdoFromEnv(string $prefix): PDO {
    $host = getenv($prefix.'_HOST') ?: 'db550618985.db.1and1.com';
    $port = (int)(getenv($prefix.'_PORT') ?: 3306);
    $db   = getenv($prefix.'_NAME') ?: '';
    $user = getenv($prefix.'_USER') ?: '';
    $pass = getenv($prefix.'_PASS') ?: '';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

    $opts = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    // SSL (facultatif) : $opts[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    return new PDO($dsn, $user, $pass, $opts);
}

function mac_norm(?string $mac): ?string {
    if (!$mac) return null;
    $m = strtoupper(preg_replace('~[^0-9A-F]~', '', $mac));
    return $m !== '' ? $m : null; // ex: "00:26:73:14:E1:27" -> "00267314E127"
}
function clamp(?int $v, int $min = 0, int $max = 100): ?int {
    if ($v === null) return null;
    return max($min, min($max, $v));
}
function toIntOrNull($v): ?int {
    if ($v === null || $v === '') return null;
    if (is_numeric($v)) return (int)$v;
    if (preg_match('~(-?\d+)~', (string)$v, $m)) return (int)$m[1];
    return null;
}

try {
    $src = pdoFromEnv('SOURCE'); // IONOS
    $dst = pdoFromEnv('DEST');   // table_plus

    // ----------------------------
    // 1) RÉFÉRENTIEL imprimantes (IONOS.printer_info)
    // ----------------------------
    $map = []; // mac_norm => [refClient, modele, serial, ip, pid]
    $byPid = []; // pid (printer_info.id) => mac_norm

    $qPI = $src->query("SELECT id, refClient, modele, serialNum, addressIP, mac, etat FROM printer_info");
    while ($r = $qPI->fetch()) {
        $macN = mac_norm($r['mac'] ?? null);
        if (!$macN) continue;
        $map[$macN] = [
            'refClient' => (string)$r['refClient'],
            'modele'    => (string)$r['modele'],
            'serial'    => (string)$r['serialNum'],
            'ip'        => (string)$r['addressIP'],
            'etat'      => isset($r['etat']) ? (int)$r['etat'] : null,
            'pid'       => (int)$r['id'],
        ];
        $byPid[(int)$r['id']] = $macN;
    }

    // ----------------------------
    // 2) DERNIERS COMPTEURS (IONOS.last_compteur) groupés par MAC
    // ----------------------------
    // On prend la dernière ligne (max(date)) par MAC
    $sqlLC = "
        SELECT lc.ref_client, lc.mac, lc.pid, lc.etat, lc.date, lc.totalNB, lc.totalCouleur
        FROM last_compteur lc
        INNER JOIN (
          SELECT mac, MAX(date) AS max_date
          FROM last_compteur
          GROUP BY mac
        ) t ON t.mac = lc.mac AND t.max_date = lc.date
    ";
    $rowsLC = $src->query($sqlLC)->fetchAll();

    // Prépare UPSERT last_compteur (DEST)
    $upLast = $dst->prepare("
        INSERT INTO last_compteur (ref_client, mac, pid, etat, date, totalNB, totalCouleur)
        VALUES (:ref_client, :mac, :pid, :etat, :date, :nb, :clr)
        ON DUPLICATE KEY UPDATE
          ref_client = VALUES(ref_client),
          pid        = VALUES(pid),
          etat       = VALUES(etat),
          date       = VALUES(date),
          totalNB    = VALUES(totalNB),
          totalCouleur = VALUES(totalCouleur)
    ");

    // Prépare INSERT compteur_relevee (DEST)
    $insCR = $dst->prepare("
        INSERT IGNORE INTO compteur_relevee
        (Timestamp, IpAddress, Nom, Model, SerialNumber, MacAddress, Status,
         TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
         TotalPages, FaxPages, CopiedPages, PrintedPages,
         BWCopies, ColorCopies, MonoCopies, BichromeCopies,
         BWPrinted, BichromePrinted, MonoPrinted, ColorPrinted,
         TotalColor, TotalBW, DateInsertion)
        VALUES
        (:ts, :ip, :nom, :model, :serial, :mac, :status,
         :tb, :tc, :tm, :ty,
         NULL, NULL, NULL, NULL,
         NULL, NULL, NULL, NULL,
         NULL, NULL, NULL, NULL,
         :total_color, :total_bw, NOW())
    ");
    // NB: INSERT IGNORE s’appuie sur ux_mac_ts pour ignorer les doublons

    // ----------------------------
    // 3) NIVEAUX DE TONER (IONOS.consommable.tmp_arr)
    // ----------------------------
    // On extrait la DERNIÈRE entrée par printer_id quand dispo
    $qCons = $src->query("
        SELECT id, ref_client, tmp_arr
        FROM consommable
        WHERE tmp_arr IS NOT NULL AND tmp_arr <> 'a:0:{}'
    ");
    $lastTonerByPid = []; // pid => [ts, tb, tc, tm, ty]
    while ($r = $qCons->fetch()) {
        $arr = @unserialize((string)$r['tmp_arr'], ['allowed_classes' => false]);
        if (!is_array($arr) || !$arr) continue;
        $entry = end($arr);
        if (!is_array($entry)) continue;
        $pid = isset($entry['printer_id']) ? (int)$entry['printer_id'] : null;
        if (!$pid) continue;

        // date (tdate|cdate)
        $ts = null;
        foreach ([$entry['tdate'] ?? null, $entry['cdate'] ?? null] as $cand) {
            if (!$cand) continue;
            $k = strtotime((string)$cand);
            if ($k !== false) { $ts = date('Y-m-d H:i:s', $k); break; }
        }
        // toner
        $tb = clamp(toIntOrNull($entry['toner_noir']    ?? null));
        $tc = clamp(toIntOrNull($entry['toner_cyan']    ?? null));
        $tm = clamp(toIntOrNull($entry['toner_magenta'] ?? null));
        $ty = clamp(toIntOrNull($entry['toner_jaune']   ?? null));

        // garde le plus récent par pid
        $prev = $lastTonerByPid[$pid]['ts'] ?? null;
        if (!$prev || ($ts && $ts > $prev)) {
            $lastTonerByPid[$pid] = ['ts'=>$ts, 'tb'=>$tb, 'tc'=>$tc, 'tm'=>$tm, 'ty'=>$ty];
        }
    }

    // ----------------------------
    // 4) Écriture DEST (UPSERT last_compteur + INSERT compteur_relevee)
    // ----------------------------
    $dst->beginTransaction();

    $writtenLast = 0; $writtenCR = 0; $skipped = [];
    foreach ($rowsLC as $r) {
        $mac         = (string)$r['mac'];
        $macN        = mac_norm($mac);
        if (!$macN) { $skipped[] = ['mac'=>$mac, 'reason'=>'mac_invalid']; continue; }

        $pid         = (int)$r['pid'];
        $refClient   = (string)$r['ref_client'];
        $etat        = isset($r['etat']) ? (int)$r['etat'] : null;
        $ts          = (string)$r['date'];
        $totalBW     = (int)$r['totalNB'];
        $totalColor  = (int)$r['totalCouleur'];

        // UPSERT last_compteur
        $upLast->execute([
            ':ref_client' => $refClient,
            ':mac' => $mac,
            ':pid' => $pid,
            ':etat'=> $etat,
            ':date'=> $ts,
            ':nb'  => $totalBW,
            ':clr' => $totalColor,
        ]);
        $writtenLast++;

        // Détails imprimante (si présents dans printer_info)
        $ref = $map[$macN] ?? null;
        $ip     = $ref['ip']     ?? null;
        $model  = $ref['modele'] ?? null;
        $serial = $ref['serial'] ?? null;
        $status = ($etat === null) ? null : (string)$etat; // tu peux mapper 0/1/… en libellé

        // Toners (dernière mesure par pid si on a)
        $t = $lastTonerByPid[$pid] ?? null;
        $tb = $t['tb'] ?? null;
        $tc = $t['tc'] ?? null;
        $tm = $t['tm'] ?? null;
        $ty = $t['ty'] ?? null;

        // INSERT compteur_relevee (IGNORE si doublon)
        $insCR->execute([
            ':ts' => $ts,
            ':ip' => $ip,
            ':nom'=> $refClient ?: null, // Nom=refClient pour affichage
            ':model' => $model,
            ':serial'=> $serial,
            ':mac'   => $mac,
            ':status'=> $status,
            ':tb' => $tb, ':tc' => $tc, ':tm' => $tm, ':ty' => $ty,
            ':total_color' => $totalColor,
            ':total_bw'    => $totalBW,
        ]);
        $writtenCR += (int)$insCR->rowCount(); // 1 si inséré, 0 si IGNORE
    }

    $dst->commit();

    echo json_encode([
        'ok' => 1,
        'last_compteur_upserts' => $writtenLast,
        'compteur_relevee_inserts' => $writtenCR,
        'skipped' => $skipped,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if (isset($dst) && $dst instanceof PDO && $dst->inTransaction()) $dst->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>0, 'error'=>$e->getMessage()]);
}
