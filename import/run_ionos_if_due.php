<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/**
 * Lit IONOS (printer_info, last_compteur, consommable.tmp_arr)
 * et insère dans table_plus.compteur_relevee :
 * - Timestamp, TotalBW/TotalColor (depuis last_compteur)
 * - Model/Serial/IP/Nom=refClient (depuis printer_info)
 * - TonerBlack/Cyan/Magenta/Yellow (dernière valeur par printer_id depuis consommable.tmp_arr)
 *
 * Anti-doublon: vérifie existence par (mac_norm, Timestamp) avant insertion.
 */

function pdoFromEnv(string $prefix): PDO {
    $host = getenv($prefix.'_HOST') ?: '';
    $port = (int)(getenv($prefix.'_PORT') ?: 3306);
    $db   = getenv($prefix.'_NAME') ?: '';
    $user = getenv($prefix.'_USER') ?: '';
    $pass = getenv($prefix.'_PASS') ?: '';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $opts);
}

function mac_norm(?string $mac): ?string {
    if (!$mac) return null;
    $m = strtoupper(preg_replace('~[^0-9A-F]~', '', $mac)); // retire ":" "-" etc.
    return $m !== '' ? $m : null;
}
function clamp(?int $v, int $min=0, int $max=100): ?int {
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
    // Connexions
    $src = pdoFromEnv('SOURCE'); // IONOS
    $dst = pdoFromEnv('DEST');   // table_plus

    // ---------------------------
    // 1) Référentiel imprimantes (IONOS.printer_info)
    // ---------------------------
    $mapByMac = [];   // mac_norm => info
    $mapPidMac = [];  // pid (printer_info.id) => mac_norm
    $q = $src->query("SELECT id, refClient, modele, serialNum, addressIP, mac, etat FROM printer_info");
    while ($r = $q->fetch()) {
        $macN = mac_norm($r['mac'] ?? null);
        if (!$macN) continue;
        $mapByMac[$macN] = [
            'refClient' => (string)($r['refClient'] ?? ''),
            'modele'    => (string)($r['modele'] ?? ''),
            'serial'    => (string)($r['serialNum'] ?? ''),
            'ip'        => (string)($r['addressIP'] ?? ''),
            'etat'      => isset($r['etat']) ? (int)$r['etat'] : null,
            'pid'       => (int)$r['id'],
        ];
        $mapPidMac[(int)$r['id']] = $macN;
    }

    // ---------------------------
    // 2) Derniers toners par printer_id (IONOS.consommable.tmp_arr)
    // ---------------------------
    $lastTonerByPid = []; // pid => ['ts','tb','tc','tm','ty']
    $qc = $src->query("SELECT tmp_arr FROM consommable WHERE tmp_arr IS NOT NULL AND tmp_arr <> 'a:0:{}'");
    while ($r = $qc->fetch()) {
        $arr = @unserialize((string)$r['tmp_arr'], ['allowed_classes'=>false]);
        if (!is_array($arr) || !$arr) continue;
        $ent = end($arr);
        if (!is_array($ent)) continue;
        $pid = isset($ent['printer_id']) ? (int)$ent['printer_id'] : null;
        if (!$pid) continue;

        $ts = null;
        foreach ([$ent['tdate'] ?? null, $ent['cdate'] ?? null] as $cand) {
            if (!$cand) continue;
            $k = strtotime((string)$cand);
            if ($k !== false) { $ts = date('Y-m-d H:i:s', $k); break; }
        }
        $tb = clamp(toIntOrNull($ent['toner_noir']    ?? null));
        $tc = clamp(toIntOrNull($ent['toner_cyan']    ?? null));
        $tm = clamp(toIntOrNull($ent['toner_magenta'] ?? null));
        $ty = clamp(toIntOrNull($ent['toner_jaune']   ?? null));

        $prev = $lastTonerByPid[$pid]['ts'] ?? null;
        if (!$prev || ($ts && $ts > $prev)) {
            $lastTonerByPid[$pid] = ['ts'=>$ts,'tb'=>$tb,'tc'=>$tc,'tm'=>$tm,'ty'=>$ty];
        }
    }

    // ---------------------------
    // 3) Dernier compteur NB/Couleur par MAC (IONOS.last_compteur)
    // ---------------------------
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

    // Prépare requêtes destination
    // existence par mac_norm + Timestamp
    $selExists = $dst->prepare("
        SELECT id FROM compteur_relevee
        WHERE mac_norm = :macn AND Timestamp = :ts
        LIMIT 1
    ");

    $ins = $dst->prepare("
        INSERT INTO compteur_relevee
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

    $dst->beginTransaction();

    $inserted = 0; $skipped = [];
    foreach ($rowsLC as $r) {
        $macRaw = (string)$r['mac'];
        $macN   = mac_norm($macRaw);
        if (!$macN) { $skipped[] = ['mac'=>$macRaw,'reason'=>'mac_invalid']; continue; }

        $ts         = (string)$r['date'];
        $pid        = (int)$r['pid'];
        $refClient  = (string)$r['ref_client'];
        $etat       = isset($r['etat']) ? (int)$r['etat'] : null;
        $totalBW    = (int)$r['totalNB'];
        $totalColor = (int)$r['totalCouleur'];

        // anti-doublon (si pas de contrainte UNIQUE en base)
        $selExists->execute([':macn'=>$macN, ':ts'=>$ts]);
        if ($selExists->fetchColumn()) {
            $skipped[] = ['mac'=>$macRaw,'ts'=>$ts,'reason'=>'exists'];
            continue;
        }

        // enrichissement printer_info
        $ref   = $mapByMac[$macN] ?? null;
        $ip    = $ref['ip']     ?? null;
        $model = $ref['modele'] ?? null;
        $serial= $ref['serial'] ?? null;
        // status lisible (option)
        $status = is_null($etat) ? null : ($etat ? 'ONLINE' : 'OFFLINE');

        // derniers toners par pid
        $t = $lastTonerByPid[$pid] ?? null;
        $tb = $t['tb'] ?? null;
        $tc = $t['tc'] ?? null;
        $tm = $t['tm'] ?? null;
        $ty = $t['ty'] ?? null;

        // insertion
        $ins->execute([
            ':ts'=>$ts,
            ':ip'=>$ip,
            ':nom'=>$refClient ?: null,
            ':model'=>$model,
            ':serial'=>$serial,
            ':mac'=>$macRaw,
            ':status'=>$status,
            ':tb'=>$tb, ':tc'=>$tc, ':tm'=>$tm, ':ty'=>$ty,
            ':total_color'=>$totalColor,
            ':total_bw'=>$totalBW,
        ]);
        $inserted++;
    }

    $dst->commit();

    echo json_encode([
        'ok'=>1,
        'inserted'=>$inserted,
        'skipped'=>$skipped,
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    if (isset($dst) && $dst instanceof PDO && $dst->inTransaction()) $dst->rollBack();
    http_response_code(500);
    echo json_encode(['ok'=>0, 'error'=>$e->getMessage()]);
}
