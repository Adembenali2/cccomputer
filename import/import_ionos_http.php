<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

/**
 * /import/import_ionos_http.php
 * - Lit JSON depuis IONOS_EXPORT_URL
 * - Traite au plus IONOS_BATCH_SIZE (défaut 20), plus anciens d'abord
 * - Dédup via UNIQUE(mac_norm, Timestamp) (UPSERT)
 * - Curseur ionos_cursor(last_ts,last_mac) pour avancer par run
 * - Journalise dans import_run (source=ionos)
 */

// ---------- ENV MySQL Railway ----------
(function (): void {
    $needs = !getenv('MYSQLHOST') || !getenv('MYSQLDATABASE') || !getenv('MYSQLUSER');
    if (!$needs) return;
    $url = getenv('MYSQL_PUBLIC_URL') ?: getenv('DATABASE_URL') ?: '';
    if (!$url) return;
    $p = parse_url($url);
    if (!$p || empty($p['host']) || empty($p['user']) || empty($p['path'])) return;
    putenv("MYSQLHOST={$p['host']}");
    putenv("MYSQLPORT=" . ($p['port'] ?? '3306'));
    putenv("MYSQLUSER=" . urldecode($p['user']));
    putenv("MYSQLPASSWORD=" . (isset($p['pass']) ? urldecode($p['pass']) : ''));
    putenv("MYSQLDATABASE=" . ltrim($p['path'], '/'));
})();

// ---------- DB ----------
$DB = dirname(__DIR__).'/includes/db.php';
if(!is_file($DB)){ http_response_code(500); exit("No includes/db.php\n"); }
require_once $DB;
if(!isset($pdo)||!($pdo instanceof PDO)){ http_response_code(500); exit("No \$pdo\n"); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- Config ----------
$EXPORT_URL = getenv('IONOS_EXPORT_URL') ?: '';
if($EXPORT_URL===''){ http_response_code(500); exit("IONOS_EXPORT_URL missing\n"); }
$BATCH = max(1,(int)(getenv('IONOS_BATCH_SIZE') ?: 20));

// ---------- Helpers ----------
function normalizeMacColoned(?string $mac): ?string {
    if ($mac===null) return null;
    $hex = strtoupper(preg_replace('/[^0-9A-F]/i','',$mac));
    if(strlen($hex)!==12) return null;
    return implode(':', str_split($hex,2));
}
function iOrNull($v): ?int { return (is_numeric($v)?(int)$v:null); }
function fetchJson(string $url): ?array {
    if(function_exists('curl_init')){
        $ch=curl_init($url);
        curl_setopt_array($ch,[
            CURLOPT_RETURNTRANSFER=>true, CURLOPT_FOLLOWLOCATION=>true,
            CURLOPT_CONNECTTIMEOUT=>15, CURLOPT_TIMEOUT=>45,
            CURLOPT_HTTPHEADER=>['Accept: application/json','User-Agent: ionos-importer/1.0']
        ]);
        $body=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
        if($body===false || $code>=400) return null;
    } else {
        $ctx=stream_context_create(['http'=>['timeout'=>45,'header'=>"Accept: application/json\r\nUser-Agent: ionos-importer/1.0\r\n"]]);
        $body=@file_get_contents($url,false,$ctx);
        if($body===false) return null;
    }
    $json=json_decode($body,true);
    return is_array($json)?$json:null;
}

// ---------- Tables ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS ionos_cursor (id TINYINT PRIMARY KEY DEFAULT 1, last_ts DATETIME NULL, last_mac CHAR(12) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->exec("INSERT IGNORE INTO ionos_cursor(id,last_ts,last_mac) VALUES (1,NULL,NULL)");
$pdo->exec("CREATE TABLE IF NOT EXISTS import_run( id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
try{ $pdo->exec("ALTER TABLE `compteur_relevee` ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`,`Timestamp`)"); }catch(Throwable $e){}

// ---------- Curseur ----------
$cur=$pdo->query("SELECT last_ts,last_mac FROM ionos_cursor WHERE id=1")->fetch(PDO::FETCH_ASSOC) ?: ['last_ts'=>null,'last_mac'=>null];
$lastTs=$cur['last_ts'] ?: null; $lastMac=$cur['last_mac'] ?: null;

// ---------- Fetch JSON ----------
$payload = fetchJson($EXPORT_URL);
if(!$payload || empty($payload['ok'])){ http_response_code(500); exit("Invalid JSON\n"); }
$items = is_array($payload['items'] ?? null) ? $payload['items'] : [];

// ---------- Normaliser + Trier ----------
$norm=[];
foreach($items as $r){
    $mac = normalizeMacColoned($r['mac'] ?? null); if(!$mac) continue;
    $ts  = strtotime((string)($r['date'] ?? '')); if(!$ts) continue;
    $norm[]=['mac'=>$mac,'mac_norm'=>str_replace(':','',$mac),'ts'=>date('Y-m-d H:i:s',$ts),'raw'=>$r];
}
usort($norm, fn($a,$b)=>strcmp($a['ts'],$b['ts']) ?: strcmp($a['mac_norm'],$b['mac_norm']));

// ---------- Filtrer > curseur ----------
$eligible=[];
foreach($norm as $x){
    if($lastTs && $x['ts'] < $lastTs) continue;
    if($lastTs && $x['ts'] === $lastTs && $lastMac && $x['mac_norm'] <= $lastMac) continue;
    $eligible[]=$x;
}
$batch = array_slice($eligible,0,$BATCH);
$remaining = max(0, count($eligible)-count($batch));

// ---------- UPSERT ----------
$sql="
INSERT INTO compteur_relevee
 (MacAddress, `Timestamp`,
  TotalBW, TotalColor, TotalPages,
  TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
  Status, SerialNumber, Model, Nom)
VALUES
 (:mac,:ts,:bw,:col,:pages,:k,:c,:m,:y,:status,:sn,:model,:nom)
ON DUPLICATE KEY UPDATE
 TotalBW=VALUES(TotalBW), TotalColor=VALUES(TotalColor), TotalPages=VALUES(TotalPages),
 TonerBlack=VALUES(TonerBlack), TonerCyan=VALUES(TonerCyan),
 TonerMagenta=VALUES(TonerMagenta), TonerYellow=VALUES(TonerYellow),
 Status=VALUES(Status)
";
$ins=$pdo->prepare($sql);

$pdo->beginTransaction();
$imported=0;$skipped=0;$lastTsNew=$lastTs;$lastMacNew=$lastMac;
try{
    foreach($batch as $x){
        $r=$x['raw'];
        $bw=iOrNull($r['totalNB']??null); $cc=iOrNull($r['totalCouleur']??null); $pages=($bw??0)+($cc??0);
        $t=is_array($r['toners']??null)?$r['toners']:[];
        $bind=[
            ':mac'=>$x['mac'], ':ts'=>$x['ts'],
            ':bw'=>$bw, ':col'=>$cc, ':pages'=>$pages,
            ':k'=>iOrNull($t['k']??null), ':c'=>iOrNull($t['c']??null), ':m'=>iOrNull($t['m']??null), ':y'=>iOrNull($t['y']??null),
            ':status'=>iOrNull($r['etat']??null),
            ':sn'=>isset($r['serial'])?(string)$r['serial']:null,
            ':model'=>isset($r['model'])?(string)$r['model']:null,
            ':nom'=>isset($r['nom'])?(string)$r['nom']:null,
        ];
        try{ $ins->execute($bind); $imported++; $lastTsNew=$x['ts']; $lastMacNew=$x['mac_norm']; }
        catch(Throwable $e){ $skipped++; }
    }
    $pdo->commit();
}catch(Throwable $e){ $pdo->rollBack(); $imported=0; $skipped=count($batch); }

// ---------- Curseur + log ----------
if($imported>0){
  $u=$pdo->prepare("UPDATE ionos_cursor SET last_ts=?, last_mac=? WHERE id=1");
  $u->execute([$lastTsNew,$lastMacNew]);
}
$msg=json_encode(['source'=>'ionos','processed'=>count($batch),'inserted'=>$imported,'skipped'=>$skipped,'remaining_estimate'=>$remaining],JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),:i,:s,:ok,:m)")
    ->execute([':i'=>$imported,':s'=>$skipped,':ok'=>($skipped?0:1),':m'=>$msg]);

echo "OK IONOS batch inserted=$imported skipped=$skipped remaining≈$remaining\n";
