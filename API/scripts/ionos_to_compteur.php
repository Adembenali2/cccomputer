<?php
// API/SCRIPTS/ionos_to_compteur.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

/**
 * ETL : lit IONOS (printer_info, last_compteur, consommable.tmp_arr)
 * -> insère dans Railway.compteur_relevee
 * -> incrémental via ionos_cursor (last_ts/last_mac)
 * -> journalise dans import_run et met à jour sftp_jobs si JOB_ID fourni
 */

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/config/db.php'; // $pdo = DEST (Railway)

function pdo_ionos(): PDO {
    $host = getenv('IONOS_HOST') ?: 'db550618985.db.1and1.com';
    $port = (int)(getenv('IONOS_PORT') ?: 3306);
    $db   = getenv('IONOS_DB')   ?: 'db550618985';
    $user = getenv('IONOS_USER') ?: 'dbo550618985';
    $pass = getenv('IONOS_PASS') ?: '';
    $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
    $opt  = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    return new PDO($dsn, $user, $pass, $opt);
}
function mac_norm(?string $mac): ?string {
    if (!$mac) return null;
    $m = strtoupper(preg_replace('~[^0-9A-F]~', '', $mac));
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
    $src = pdo_ionos(); // source IONOS
    $dst = $pdo;        // dest Railway

    // Tables de suivi (si pas créées)
    $dst->exec("CREATE TABLE IF NOT EXISTS import_run (
      id INT NOT NULL AUTO_INCREMENT,
      ran_at DATETIME NOT NULL,
      imported INT NOT NULL,
      skipped INT NOT NULL,
      ok TINYINT(1) NOT NULL,
      msg TEXT,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $dst->exec("CREATE TABLE IF NOT EXISTS ionos_cursor (
      id TINYINT NOT NULL DEFAULT 1,
      last_ts DATETIME DEFAULT NULL,
      last_mac CHAR(12) DEFAULT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    $dst->exec("CREATE TABLE IF NOT EXISTS sftp_jobs (
      id INT NOT NULL AUTO_INCREMENT,
      status ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      started_at DATETIME DEFAULT NULL,
      finished_at DATETIME DEFAULT NULL,
      summary JSON DEFAULT NULL,
      error TEXT,
      triggered_by INT DEFAULT NULL,
      PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    // Lire curseur
    $cur = $dst->query("SELECT last_ts, last_mac FROM ionos_cursor WHERE id=1")->fetch() ?: ['last_ts'=>null,'last_mac'=>null];
    $lastTs  = $cur['last_ts'] ?? null;
    $lastMac = $cur['last_mac'] ?? null;

    // 1) Référentiel imprimantes
    $mapByMac = []; $mapPidMac = [];
    $q = $src->query("SELECT id, refClient, modele, serialNum, addressIP, mac, etat FROM printer_info");
    while ($r = $q->fetch()) {
        $macN = mac_norm($r['mac'] ?? null);
        if (!$macN) continue;
        $mapByMac[$macN] = [
            'refClient' => (string)$r['refClient'],
            'modele'    => (string)$r['modele'],
            'serial'    => (string)$r['serialNum'],
            'ip'        => (string)$r['addressIP'],
            'etat'      => isset($r['etat']) ? (int)$r['etat'] : null,
            'pid'       => (int)$r['id'],
        ];
        $mapPidMac[(int)$r['id']] = $macN;
    }

    // 2) Derniers toners par printer_id (consommable.tmp_arr)
    $lastTonerByPid = [];
    $qc = $src->query("SELECT tmp_arr FROM consommable WHERE tmp_arr IS NOT NULL AND tmp_arr <> 'a:0:{}'");
    while ($r = $qc->fetch()) {
        $arr = @unserialize((string)$r['tmp_arr'], ['allowed_classes'=>false]);
        if (!is_array($arr) || !$arr) continue;
        $ent = end($arr); if (!is_array($ent)) continue;
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

    // 3) Dernier compteur par MAC, filtré par curseur
    // CTE non dispo sur MySQL 5.7, on fait en 2 temps :

    // a) dernières dates par MAC
    $latest = $src->query("SELECT lc.mac, MAX(lc.date) AS max_date FROM last_compteur lc GROUP BY lc.mac")->fetchAll();
    $latestMap = [];
    foreach ($latest as $x) { $latestMap[$x['mac']] = $x['max_date']; }

    // b) récupère les lignes complètes correspondant à ces (mac, max_date)
    $rowsLC = [];
    if ($latestMap) {
        $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur
                             FROM last_compteur
                             WHERE mac = :mac AND date = :d");
        foreach ($latestMap as $mac => $d) {
            // filtre curseur ici
            $macN = strtoupper(str_replace(':','',(string)$mac));
            $afterCursor = !$lastTs
                || ($d > $lastTs)
                || ($d === $lastTs && $macN > ($lastMac ?? ''));
            if (!$afterCursor) continue;

            $st->execute([':mac'=>$mac, ':d'=>$d]);
            if ($row = $st->fetch()) $rowsLC[] = $row;
        }
    }

    // 4) INSERT dans compteur_relevee (Railway) — avec INSERT IGNORE (prévoir unique key mac_norm+Timestamp en BDD)
    $dst->beginTransaction();

    $ins = $dst->prepare("
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

    $inserted = 0; $skipped = [];
    $maxTs  = $lastTs;
    $maxMac = $lastMac;

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

        // enrichissement
        $ref   = $mapByMac[$macN] ?? null;
        $ip    = $ref['ip']     ?? null;
        $model = $ref['modele'] ?? null;
        $serial= $ref['serial'] ?? null;
        $status= is_null($etat) ? null : ($etat ? 'ONLINE' : 'OFFLINE');

        $t   = $lastTonerByPid[$pid] ?? null;
        $tb  = $t['tb'] ?? null;
        $tc  = $t['tc'] ?? null;
        $tm  = $t['tm'] ?? null;
        $ty  = $t['ty'] ?? null;

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
        $inserted += (int)$ins->rowCount(); // 1 si inséré, 0 si doublon

        // maj curseur max
        $macNUpper = strtoupper($macN);
        if ($maxTs === null || $ts > $maxTs || ($ts === $maxTs && $macNUpper > ($maxMac ?? ''))) {
            $maxTs  = $ts;
            $maxMac = $macNUpper;
        }
    }

    // upsert curseur (si on a avancé)
    if ($maxTs !== $lastTs || $maxMac !== $lastMac) {
        $dst->exec("INSERT INTO ionos_cursor (id, last_ts, last_mac) VALUES (1, NULL, NULL)
                    ON DUPLICATE KEY UPDATE last_ts=last_ts");
        $up = $dst->prepare("UPDATE ionos_cursor SET last_ts=:ts, last_mac=:mac WHERE id=1");
        $up->execute([':ts'=>$maxTs, ':mac'=>$maxMac]);
    }

    $dst->commit();

    // 5) import_run (journal)
    $ok  = 1;
    $msg = json_encode(['source'=>'ionos','inserted'=>$inserted,'skipped'=>$skipped], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $log = $dst->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg) VALUES (NOW(), :i, :s, :ok, :m)");
    $log->execute([':i'=>$inserted, ':s'=>count($skipped), ':ok'=>$ok, ':m'=>$msg]);

    // 6) sftp_jobs (si chainé par run_ionos_if_due.php)
    $jobId = (int)($_ENV['JOB_ID'] ?? $_SERVER['JOB_ID'] ?? 0);
    if ($jobId > 0) {
        $sum = json_encode(['inserted'=>$inserted,'skipped'=>$skipped], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $upd = $dst->prepare("UPDATE sftp_jobs SET status='done', finished_at=NOW(), summary=:su WHERE id=:id");
        $upd->execute([':su'=>$sum, ':id'=>$jobId]);
    }

    echo json_encode(['ok'=>1,'inserted'=>$inserted,'skipped'=>$skipped,'cursor'=>['last_ts'=>$maxTs,'last_mac'=>$maxMac]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;

} catch (Throwable $e) {
    // log import_run
    try {
        $msg = json_encode(['error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $stmt = $pdo->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg) VALUES (NOW(), 0, 0, 0, :m)");
        $stmt->execute([':m'=>$msg]);
    } catch(Throwable $ignored){}

    // job failed si JOB_ID
    try {
        $jobId = (int)($_ENV['JOB_ID'] ?? $_SERVER['JOB_ID'] ?? 0);
        if ($jobId > 0) {
            $upd = $pdo->prepare("UPDATE sftp_jobs SET status='failed', finished_at=NOW(), error=:er WHERE id=:id");
            $upd->execute([':er'=>$e->getMessage(), ':id'=>$jobId]);
        }
    } catch(Throwable $ignored){}

    http_response_code(500);
    echo json_encode(['ok'=>0,'error'=>$e->getMessage()]);
    exit;
}
