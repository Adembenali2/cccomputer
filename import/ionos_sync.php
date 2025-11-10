<?php
// /import/ionos_sync.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/**
 * OBJECTIF
 * - Lire directement IONOS (PDO #1) : last_compteur + printer_info + consommable.tmp_arr (dernier snapshot toner)
 * - Écrire directement Railway (PDO #2) dans compteur_relevee
 * - Idempotent via UNIQUE(mac_norm, Timestamp) OU via WHERE NOT EXISTS si unique absente
 * - Curseur incrémental (table locale ionos_cursor2) pour ne traiter qu'une fois chaque (date, mac)
 *
 * APPELS
 * - POST /import/ionos_sync.php?limit=50                 // batch 50
 * - POST /import/ionos_sync.php?limit=500&force=1       // bypasse l’anti-bouclage 20s
 * - POST /import/ionos_sync.php?mode=backfill&limit=500 // backfill total par tranches
 *
 * VARIABLES D’ENV ATTENDUES (Railway Settings > Variables)
 * - MYSQLHOST, MYSQLPORT, MYSQLDATABASE, MYSQLUSER, MYSQLPASSWORD (cible Railway)
 * - IONOS_HOST, IONOS_PORT, IONOS_DB, IONOS_USER, IONOS_PASS  (source IONOS)
 * - (optionnel) IONOS_IMPORT_INTERVAL_SEC (def 20)
 */

register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        http_response_code(500);
        echo json_encode([
            'error'   => 'FATAL in ionos_sync.php',
            'type'    => $e['type'],
            'message' => $e['message'],
            'file'    => $e['file'],
            'line'    => $e['line'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
});

try {
    // ---------- params ----------
    $mode  = strtolower((string)($_GET['mode'] ?? $_POST['mode'] ?? 'latest')); // latest | backfill
    $force = (int)($_GET['force'] ?? $_POST['force'] ?? 0);
    $LIMIT = (int)($_GET['limit'] ?? $_POST['limit'] ?? 50);
    if ($LIMIT <= 0) $LIMIT = 50;

    // ---------- connect PDO destination (Railway) ----------
    $rHost = getenv('MYSQLHOST'); $rPort = getenv('MYSQLPORT'); $rDb = getenv('MYSQLDATABASE');
    $rUser = getenv('MYSQLUSER'); $rPass = getenv('MYSQLPASSWORD');
    if (!$rHost || !$rDb || !$rUser) throw new RuntimeException('Railway MySQL env vars missing');

    $rDsn = "mysql:host={$rHost};port={$rPort};dbname={$rDb};charset=utf8mb4";
    $dst = new PDO($rDsn, $rUser, $rPass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);

    // ---------- connect PDO source (IONOS) ----------
    $iHost = getenv('IONOS_HOST'); $iPort = (int)(getenv('IONOS_PORT') ?: 3306); $iDb = getenv('IONOS_DB');
    $iUser = getenv('IONOS_USER'); $iPass = getenv('IONOS_PASS');
    if (!$iHost || !$iDb || !$iUser) throw new RuntimeException('IONOS env vars missing');

    $iDsn = "mysql:host={$iHost};port={$iPort};dbname={$iDb};charset=utf8mb4";
    $src = new PDO($iDsn, $iUser, $iPass, [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false
    ]);

    // ---------- helpers ----------
    $clamp = function($v){ if($v===null||$v==='') return null; if(!is_numeric($v) && !preg_match('~(-?\d+)~',(string)$v,$m)) return null; $x = (int)($m[1] ?? $v); return max(0,min(100,$x)); };
    $macNorm = function(?string $mac){ if(!$mac) return null; $m = strtoupper(preg_replace('~[^0-9A-F]~','',$mac)); return $m !== '' ? $m : null; };

    // ---------- bootstrap tables côté Railway ----------
    $dst->exec("CREATE TABLE IF NOT EXISTS import_run (
      id INT NOT NULL AUTO_INCREMENT,
      ran_at DATETIME NOT NULL,
      imported INT NOT NULL,
      skipped INT NOT NULL,
      ok TINYINT(1) NOT NULL,
      msg TEXT,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $cursorTable = $mode === 'backfill' ? 'ionos_backfill_cursor2' : 'ionos_cursor2';
    $dst->exec("CREATE TABLE IF NOT EXISTS {$cursorTable} (
      id TINYINT NOT NULL DEFAULT 1,
      last_ts DATETIME DEFAULT NULL,
      last_mac CHAR(12) DEFAULT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // s’assurer des index/unique sur compteur_relevee
    $dst->exec("CREATE TABLE IF NOT EXISTS compteur_relevee (
      id INT NOT NULL AUTO_INCREMENT,
      Timestamp DATETIME DEFAULT NULL,
      IpAddress VARCHAR(50) NULL,
      Nom VARCHAR(255) NULL,
      Model VARCHAR(100) NULL,
      SerialNumber VARCHAR(100) NULL,
      MacAddress VARCHAR(50) NULL,
      Status VARCHAR(50) NULL,
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
      DateInsertion DATETIME DEFAULT NULL,
      mac_norm CHAR(12) GENERATED ALWAYS AS (REPLACE(UPPER(IFNULL(MacAddress,'')), ':','')) STORED,
      PRIMARY KEY (id),
      KEY ix_compteur_date (Timestamp),
      KEY ix_compteur_mac_ts (mac_norm, Timestamp)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    try { $dst->exec("ALTER TABLE compteur_relevee ADD UNIQUE KEY ux_mac_ts (mac_norm, Timestamp)"); } catch(Throwable $ignored){}

    // ---------- anti-bouclage 20s ----------
    $dst->exec("CREATE TABLE IF NOT EXISTS app_kv (k VARCHAR(64) PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    $INTERVAL = (int)(getenv('IONOS_IMPORT_INTERVAL_SEC') ?: 20);
    $kvKey = $mode === 'backfill' ? 'ionos_sync_backfill_last_run' : 'ionos_sync_last_run';
    $lastRun = $dst->query("SELECT v FROM app_kv WHERE k='{$kvKey}'")->fetchColumn();
    $due = $force ? true : ((time() - ($lastRun ? strtotime((string)$lastRun) : 0)) >= $INTERVAL);
    if (!$due) {
        echo json_encode(['ran'=>false,'reason'=>'not_due','last_run'=>$lastRun,'mode'=>$mode]); exit;
    }
    $dst->prepare("REPLACE INTO app_kv(k,v) VALUES(?,NOW())")->execute([$kvKey]);

    // ---------- référentiel imprimantes IONOS (par MAC normalisée) ----------
    $mapByMac = [];
    $qpi = $src->query("SELECT id, refClient, modele, serialNum, addressIP, mac, etat FROM printer_info");
    while ($r = $qpi->fetch()) {
        $mn = $macNorm($r['mac'] ?? null); if(!$mn) continue;
        $mapByMac[$mn] = [
            'refClient'=>$r['refClient'] ?? '',
            'modele'=>$r['modele'] ?? '',
            'serial'=>$r['serialNum'] ?? '',
            'ip'=>$r['addressIP'] ?? '',
            'etat'=>isset($r['etat'])?(int)$r['etat']:null,
            'pid'=>(int)$r['id']
        ];
    }

    // ---------- derniers toners par pid (consommable.tmp_arr) ----------
    $lastTonerByPid = [];
    $qc = $src->query("SELECT tmp_arr FROM consommable WHERE tmp_arr IS NOT NULL AND tmp_arr <> 'a:0:{}'");
    while ($r = $qc->fetch()) {
        $arr = @unserialize((string)$r['tmp_arr'], ['allowed_classes'=>false]);
        if (!is_array($arr) || !$arr) continue;
        $ent = end($arr); if (!is_array($ent)) continue;
        $pid = isset($ent['printer_id']) ? (int)$ent['printer_id'] : null; if(!$pid) continue;

        $ts = null;
        foreach ([$ent['tdate'] ?? null, $ent['cdate'] ?? null] as $cand) {
            if (!$cand) continue;
            $k = strtotime((string)$cand);
            if ($k !== false) { $ts = date('Y-m-d H:i:s', $k); break; }
        }
        $tb = $clamp($ent['toner_noir']   ?? null);
        $tc = $clamp($ent['toner_cyan']   ?? null);
        $tm = $clamp($ent['toner_magenta']?? null);
        $ty = $clamp($ent['toner_jaune']  ?? null);

        $prev = $lastTonerByPid[$pid]['ts'] ?? null;
        if (!$prev || ($ts && $ts > $prev)) $lastTonerByPid[$pid] = ['ts'=>$ts,'tb'=>$tb,'tc'=>$tc,'tm'=>$tm,'ty'=>$ty];
    }

    // ---------- curseur ----------
    $cur = $dst->query("SELECT last_ts, last_mac FROM {$cursorTable} WHERE id=1")->fetch() ?: ['last_ts'=>null,'last_mac'=>null];
    $lastTs = $cur['last_ts'] ?? null;
    $lastMac = $cur['last_mac'] ?? null;

    // ---------- lecture du batch ----------
    if ($mode === 'latest') {
        // prend uniquement le DERNIER relevé par MAC (comme une “vue” latest)
        $latest = $src->query("SELECT mac, MAX(date) AS max_date FROM last_compteur GROUP BY mac")->fetchAll();
        $rows = [];
        $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur FROM last_compteur WHERE mac=:mac AND date=:d");
        foreach ($latest as $x) {
            $mac = (string)$x['mac']; $d = (string)$x['max_date'];
            $mn = strtoupper(str_replace(':','', $mac));
            $after = !$lastTs || ($d > $lastTs) || ($d === $lastTs && $mn > ($lastMac ?? ''));
            if (!$after) continue;
            $st->execute([':mac'=>$mac, ':d'=>$d]);
            if ($row = $st->fetch()) $rows[] = $row;
        }
        usort($rows, function($a,$b){
            if ($a['date'] === $b['date']) return strcasecmp(str_replace(':','',$a['mac']), str_replace(':','',$b['mac']));
            return strcmp($a['date'], $b['date']);
        });
        if (count($rows) > $LIMIT) $rows = array_slice($rows, 0, $LIMIT);
    } else {
        // backfill : tout l’historique, tri ASC, après curseur
        if ($lastTs === null) {
            $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur
                                 FROM last_compteur
                                 ORDER BY date ASC, mac ASC
                                 LIMIT :lim");
        } else {
            $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur
                                 FROM last_compteur
                                 WHERE (date > :ts) OR (date = :ts AND REPLACE(UPPER(mac),':','') > :mac)
                                 ORDER BY date ASC, mac ASC
                                 LIMIT :lim");
            $st->bindValue(':ts', $lastTs);
            $st->bindValue(':mac', $lastMac);
        }
        $st->bindValue(':lim', $LIMIT, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    }

    if (!$rows) {
        echo json_encode(['ok'=>1,'mode'=>$mode,'inserted'=>0,'skipped'=>[],'done'=>($mode==='backfill'?1:0),'message'=>'Rien à importer']); exit;
    }

    // ---------- insertion idempotente ----------
    // on tente ON DUPLICATE KEY si UNIQUE existe; sinon fallback WHERE NOT EXISTS
    $hasUnique = false;
    try {
        $dst->query("SHOW INDEX FROM compteur_relevee WHERE Key_name='ux_mac_ts'")->fetch();
        $hasUnique = true;
    } catch(Throwable $ignored){}

    if ($hasUnique) {
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
          ON DUPLICATE KEY UPDATE id=id
        ");
    } else {
        $ins = $dst->prepare("
          INSERT INTO compteur_relevee
          (Timestamp, IpAddress, Nom, Model, SerialNumber, MacAddress, Status,
           TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
           TotalPages, FaxPages, CopiedPages, PrintedPages,
           BWCopies, ColorCopies, MonoCopies, BichromeCopies,
           BWPrinted, BichromePrinted, MonoPrinted, ColorPrinted,
           TotalColor, TotalBW, DateInsertion)
          SELECT :ts, :ip, :nom, :model, :serial, :mac, :status,
                 :tb, :tc, :tm, :ty,
                 NULL, NULL, NULL, NULL,
                 NULL, NULL, NULL, NULL,
                 NULL, NULL, NULL, NULL,
                 :total_color, :total_bw, NOW()
          FROM DUAL WHERE NOT EXISTS (
            SELECT 1 FROM compteur_relevee
            WHERE mac_norm = REPLACE(UPPER(IFNULL(:mac,'')),':','') AND Timestamp = :ts
          )
        ");
    }

    $inserted = 0; $skipped = [];
    $maxTs = $lastTs; $maxMac = $lastMac;

    foreach ($rows as $r) {
        $macRaw = (string)$r['mac']; $mn = $macNorm($macRaw);
        if (!$mn) { $skipped[] = ['mac'=>$macRaw,'reason'=>'mac_invalid']; continue; }

        $ts   = (string)$r['date'];
        $pid  = (int)$r['pid'];
        $refC = (string)$r['ref_client'];
        $etat = isset($r['etat']) ? (int)$r['etat'] : null;
        $totalBW    = (int)$r['totalNB'];
        $totalColor = (int)$r['totalCouleur'];

        $ref   = $mapByMac[$mn] ?? null;
        $ip    = $ref['ip'] ?? null;
        $model = $ref['modele'] ?? null;
        $serial= $ref['serial'] ?? null;
        $status= is_null($etat) ? null : ($etat ? 'ONLINE' : 'OFFLINE');

        $t   = $lastTonerByPid[$pid] ?? null;
        $tb  = $t['tb'] ?? null; $tc = $t['tc'] ?? null; $tm = $t['tm'] ?? null; $ty = $t['ty'] ?? null;

        $ins->execute([
            ':ts'=>$ts, ':ip'=>$ip, ':nom'=>$refC ?: null, ':model'=>$model, ':serial'=>$serial, ':mac'=>$macRaw, ':status'=>$status,
            ':tb'=>$tb, ':tc'=>$tc, ':tm'=>$tm, ':ty'=>$ty,
            ':total_color'=>$totalColor, ':total_bw'=>$totalBW
        ]);
        $aff = $ins->rowCount();
        $inserted += $aff;
        if ($aff === 0) $skipped[] = ['mac'=>$macRaw,'ts'=>$ts,'reason'=>'exists'];

        $macU = strtoupper($mn);
        if ($maxTs === null || $ts > $maxTs || ($ts === $maxTs && $macU > ($maxMac ?? ''))) { $maxTs = $ts; $maxMac = $macU; }
    }

    // ---------- avance curseur ----------
    if ($maxTs !== $lastTs || $maxMac !== $lastMac) {
        $dst->exec("INSERT INTO {$cursorTable} (id, last_ts, last_mac) VALUES (1, NULL, NULL)
                    ON DUPLICATE KEY UPDATE last_ts=last_ts");
        $up = $dst->prepare("UPDATE {$cursorTable} SET last_ts=:ts, last_mac=:mac WHERE id=1");
        $up->execute([':ts'=>$maxTs, ':mac'=>$maxMac]);
    }

    // ---------- log import_run ----------
    $msg = json_encode(['source'=>'ionos_sync','mode'=>$mode,'limit'=>$LIMIT,'inserted'=>$inserted,'skipped'=>$skipped,'cursor'=>['last_ts'=>$maxTs,'last_mac'=>$maxMac]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $dst->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg) VALUES (NOW(), :i, :s, 1, :m)")
        ->execute([':i'=>$inserted, ':s'=>count($skipped), ':m'=>$msg]);

    echo json_encode(['ok'=>1,'mode'=>$mode,'inserted'=>$inserted,'skipped'=>$skipped,'cursor'=>['last_ts'=>$maxTs,'last_mac'=>$maxMac]]);
    exit;

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>0,'error'=>$e->getMessage()]);
    exit;
}
