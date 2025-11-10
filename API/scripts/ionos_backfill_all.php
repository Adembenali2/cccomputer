<?php
// /API/SCRIPTS/ionos_backfill_all.php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$projectRoot = dirname(__DIR__, 2);
require_once $projectRoot . '/includes/db.php'; // $pdo destination (Railway)

// Connexion source IONOS
function pdo_ionos(): PDO {
  $host = getenv('IONOS_HOST') ?: 'db550618985.db.1and1.com';
  $port = (int)(getenv('IONOS_PORT') ?: 3306);
  $db   = getenv('IONOS_DB')   ?: 'db550618985';
  $user = getenv('IONOS_USER') ?: 'dbo550618985';
  $pass = getenv('IONOS_PASS') ?: '';
  $dsn  = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";
  $opt  = [
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES=>false
  ];
  return new PDO($dsn, $user, $pass, $opt);
}

function mac_norm(?string $mac): ?string { if(!$mac) return null; $m=strtoupper(preg_replace('~[^0-9A-F]~','',$mac)); return $m!==''?$m:null; }
function clamp(?int $v, int $min=0, int $max=100): ?int { if($v===null) return null; return max($min,min($max,$v)); }
function toIntOrNull($v): ?int { if($v===null||$v==='') return null; if(is_numeric($v)) return (int)$v; if(preg_match('~(-?\d+)~',(string)$v,$m)) return (int)$m[1]; return null; }

try {
  $src = pdo_ionos(); // IONOS
  $dst = $pdo;        // Railway

  $LIMIT = max(1, (int)(getenv('IONOS_BATCH_LIMIT') ?: 500)); // batch plus gros pour backfill

  // tables auxiliaires / log / curseur
  $dst->exec("CREATE TABLE IF NOT EXISTS import_run (
    id INT NOT NULL AUTO_INCREMENT, ran_at DATETIME NOT NULL,
    imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $dst->exec("CREATE TABLE IF NOT EXISTS ionos_backfill_cursor (
    id TINYINT NOT NULL DEFAULT 1,
    last_ts DATETIME DEFAULT NULL,
    last_mac CHAR(12) DEFAULT NULL,
    PRIMARY KEY (id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // s'assurer de la table cible (selon ton schéma)
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
    TotalPages INT NULL, FaxPages INT NULL, CopiedPages INT NULL, PrintedPages INT NULL,
    BWCopies INT NULL, ColorCopies INT NULL, MonoCopies INT NULL, BichromeCopies INT NULL,
    BWPrinted INT NULL, BichromePrinted INT NULL, MonoPrinted INT NULL, ColorPrinted INT NULL,
    TotalColor INT NULL, TotalBW INT NULL,
    DateInsertion DATETIME DEFAULT NULL,
    mac_norm CHAR(12) GENERATED ALWAYS AS (REPLACE(UPPER(IFNULL(MacAddress,'')), ':','')) STORED,
    PRIMARY KEY (id),
    KEY ix_compteur_date (Timestamp),
    KEY ix_compteur_mac_ts (mac_norm, Timestamp)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // Essaye d'ajouter UNIQUE (si pas déjà là)
  try { $dst->exec("ALTER TABLE compteur_relevee ADD UNIQUE KEY ux_mac_ts (mac_norm, Timestamp)"); } catch (Throwable $ignored) {}

  // 1) référentiel imprimantes (map par MAC)
  $mapByMac = [];
  $qpi = $src->query("SELECT id, refClient, modele, serialNum, addressIP, mac, etat FROM printer_info");
  while ($r = $qpi->fetch()) {
    $macN = mac_norm($r['mac'] ?? null); if(!$macN) continue;
    $mapByMac[$macN] = [
      'refClient'=>$r['refClient'] ?? '',
      'modele'=>$r['modele'] ?? '',
      'serial'=>$r['serialNum'] ?? '',
      'ip'=>$r['addressIP'] ?? '',
      'etat'=>isset($r['etat'])?(int)$r['etat']:null,
      'pid'=>(int)$r['id']
    ];
  }

  // 2) derniers toners par pid (depuis consommable.tmp_arr)
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
    $tb = clamp(toIntOrNull($ent['toner_noir'] ?? null));
    $tc = clamp(toIntOrNull($ent['toner_cyan'] ?? null));
    $tm = clamp(toIntOrNull($ent['toner_magenta'] ?? null));
    $ty = clamp(toIntOrNull($ent['toner_jaune'] ?? null));

    $prev = $lastTonerByPid[$pid]['ts'] ?? null;
    if (!$prev || ($ts && $ts > $prev)) $lastTonerByPid[$pid] = ['ts'=>$ts,'tb'=>$tb,'tc'=>$tc,'tm'=>$tm,'ty'=>$ty];
  }

  // 3) curseur backfill
  $cur = $dst->query("SELECT last_ts, last_mac FROM ionos_backfill_cursor WHERE id=1")->fetch() ?: ['last_ts'=>null,'last_mac'=>null];
  $lastTs  = $cur['last_ts'] ?? null;
  $lastMac = $cur['last_mac'] ?? null;

  // 4) prendre le prochain batch trié (date ASC, mac ASC) depuis last_compteur
  $st = null;
  if ($lastTs === null) {
    $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur
                         FROM last_compteur
                         ORDER BY date ASC, mac ASC
                         LIMIT :lim");
  } else {
    // strictement > (date,mac)
    $st = $src->prepare("SELECT ref_client, mac, pid, etat, date, totalNB, totalCouleur
                         FROM last_compteur
                         WHERE (date > :ts) OR (date = :ts AND REPLACE(UPPER(mac),':','') > :mac)
                         ORDER BY date ASC, mac ASC
                         LIMIT :lim");
    $st->bindValue(':ts',  $lastTs);
    $st->bindValue(':mac', $lastMac);
  }
  $st->bindValue(':lim', $LIMIT, PDO::PARAM_INT);
  $st->execute();
  $rows = $st->fetchAll();

  if (!$rows) {
    echo json_encode(['ok'=>1,'inserted'=>0,'skipped'=>[],'done'=>1,'message'=>'Backfill terminé']); exit;
  }

  // 5) préparations d'insertion sans doublons (compatible si UNIQUE manque)
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
    FROM DUAL
    WHERE NOT EXISTS (
      SELECT 1 FROM compteur_relevee
      WHERE mac_norm = :macnorm AND Timestamp = :ts
    )
  ");

  $inserted = 0; $skipped = [];
  $maxTs  = $lastTs; $maxMac = $lastMac;

  foreach ($rows as $r) {
    $macRaw = (string)$r['mac']; $macN = mac_norm($macRaw);
    if (!$macN) { $skipped[] = ['mac'=>$macRaw,'reason'=>'mac_invalid']; continue; }

    $ts   = (string)$r['date'];
    $pid  = (int)$r['pid'];
    $refC = (string)$r['ref_client'];
    $etat = isset($r['etat']) ? (int)$r['etat'] : null;
    $totalBW    = (int)$r['totalNB'];
    $totalColor = (int)$r['totalCouleur'];

    $ref   = $mapByMac[$macN] ?? null;
    $ip    = $ref['ip'] ?? null;
    $model = $ref['modele'] ?? null;
    $serial= $ref['serial'] ?? null;
    $status= is_null($etat) ? null : ($etat ? 'ONLINE' : 'OFFLINE');

    $t   = $lastTonerByPid[$pid] ?? null;
    $tb  = $t['tb'] ?? null; $tc = $t['tc'] ?? null; $tm = $t['tm'] ?? null; $ty = $t['ty'] ?? null;

    $ins->execute([
      ':ts'=>$ts, ':ip'=>$ip, ':nom'=>$refC ?: null, ':model'=>$model, ':serial'=>$serial, ':mac'=>$macRaw, ':status'=>$status,
      ':tb'=>$tb, ':tc'=>$tc, ':tm'=>$tm, ':ty'=>$ty,
      ':total_color'=>$totalColor, ':total_bw'=>$totalBW,
      ':macnorm'=>$macN
    ]);
    $aff = $ins->rowCount();
    $inserted += $aff;
    if ($aff === 0) { $skipped[] = ['mac'=>$macRaw,'ts'=>$ts,'reason'=>'exists']; }

    $macU = strtoupper($macN);
    if ($maxTs === null || $ts > $maxTs || ($ts === $maxTs && $macU > ($maxMac ?? ''))) { $maxTs = $ts; $maxMac = $macU; }
  }

  // 6) avance le curseur
  if ($maxTs !== $lastTs || $maxMac !== $lastMac) {
    $dst->exec("INSERT INTO ionos_backfill_cursor (id, last_ts, last_mac) VALUES (1, NULL, NULL)
                ON DUPLICATE KEY UPDATE last_ts=last_ts");
    $up = $dst->prepare("UPDATE ionos_backfill_cursor SET last_ts=:ts, last_mac=:mac WHERE id=1");
    $up->execute([':ts'=>$maxTs, ':mac'=>$maxMac]);
  }

  // 7) log
  $msg = json_encode(['source'=>'ionos_backfill','batch_limit'=>$LIMIT,'inserted'=>$inserted,'skipped'=>$skipped,'cursor'=>['last_ts'=>$maxTs,'last_mac'=>$maxMac]], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $dst->prepare("INSERT INTO import_run (ran_at, imported, skipped, ok, msg) VALUES (NOW(), :i, :s, 1, :m)")
     ->execute([':i'=>$inserted, ':s'=>count($skipped), ':m'=>$msg]);

  echo json_encode(['ok'=>1,'mode'=>'backfill','inserted'=>$inserted,'skipped'=>$skipped,'cursor'=>['last_ts'=>$maxTs,'last_mac'=>$maxMac]]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>0,'mode'=>'backfill','error'=>$e->getMessage()]);
  exit;
}
