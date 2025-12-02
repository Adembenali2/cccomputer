<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * /import/upload_compteur.php
 * - Importe depuis SFTP des CSV (format Champ,Valeur) vers compteur_relevee
 * - Traite au plus N fichiers par run (IMPORT_BATCH_SIZE, défaut 20)
 * - Plus anciens d'abord (mtime ASC)
 * - Dédup via (mac_norm, Timestamp)
 * - Journalise dans import_run (msg JSON "source":"sftp", ...).
 */

// ---------- Normaliser ENV MySQL Railway si nécessaire ----------
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
$DB = dirname(__DIR__) . '/includes/db.php';
if (!is_file($DB)) { http_response_code(500); exit("No includes/db.php\n"); }
require_once $DB;
if (!isset($pdo) || !($pdo instanceof PDO)) { http_response_code(500); exit("No \$pdo\n"); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ---------- SFTP ----------
require_once dirname(__DIR__) . '/vendor/autoload.php';
use phpseclib3\Net\SFTP;

$sftp_host  = getenv('SFTP_HOST') ?: 'home298245733.1and1-data.host';
$sftp_user  = getenv('SFTP_USER') ?: '';
$sftp_pass  = getenv('SFTP_PASS') ?: '';
$sftp_port  = (int)(getenv('SFTP_PORT') ?: 22);
$sftp_path  = getenv('SFTP_PATH') ?: '/';
$timeout    = (int)(getenv('SFTP_TIMEOUT') ?: 30);
$BATCH_SIZE = max(1, (int)(getenv('IMPORT_BATCH_SIZE') ?: 20));

$sftp = new SFTP($sftp_host, $sftp_port, $timeout);
if (!$sftp->login($sftp_user, $sftp_pass)) { http_response_code(500); exit("SFTP login failed\n"); }
@$sftp->mkdir('/processed'); @$sftp->mkdir('/errors');

function sftp_safe_move(SFTP $s, string $from, string $dir): void {
    $b = basename($from);
    $to = rtrim($dir,'/').'/'.$b;
    if ($s->rename($from,$to)) return;
    $alt = rtrim($dir,'/').'/'.pathinfo($b,PATHINFO_FILENAME).'_'.date('Ymd_His').'.'.pathinfo($b,PATHINFO_EXTENSION);
    $s->rename($from,$alt);
}

// ---------- CSV util ----------
function parse_csv_kv(string $file): array {
    $out=[]; $h=@fopen($file,'r'); if(!$h) return $out;
    $first = fgets($h) ?: '';
    $sep = (substr_count($first,';') > substr_count($first,',')) ? ';' : ',';
    rewind($h);
    while(($row=fgetcsv($h,0,$sep))!==false){
        if(count($row)<2) continue;
        $k=trim((string)$row[0]); $v=trim((string)$row[1]);
        if ($k!=='' && !(strcasecmp($k,'Champ')===0 && strcasecmp($v,'Valeur')===0)) $out[$k]=($v===''?null:$v);
    }
    fclose($h);
    return $out;
}

$FIELDS = [
 'Timestamp','IpAddress','Nom','Model','SerialNumber','MacAddress','Status',
 'TonerBlack','TonerCyan','TonerMagenta','TonerYellow',
 'TotalPages','FaxPages','CopiedPages','PrintedPages',
 'BWCopies','ColorCopies','MonoCopies','BichromeCopies',
 'BWPrinted','BichromePrinted','MonoPrinted','ColorPrinted','TotalColor','TotalBW'
];

// ---------- Tables (si manquent) ----------
$pdo->exec("
CREATE TABLE IF NOT EXISTS import_run(
 id INT AUTO_INCREMENT PRIMARY KEY,
 ran_at DATETIME NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
$pdo->exec("
CREATE TABLE IF NOT EXISTS compteur_relevee (
  id int NOT NULL AUTO_INCREMENT,
  `Timestamp` datetime DEFAULT NULL,
  `IpAddress` varchar(50) DEFAULT NULL,
  `Nom` varchar(255) DEFAULT NULL,
  `Model` varchar(100) DEFAULT NULL,
  `SerialNumber` varchar(100) DEFAULT NULL,
  `MacAddress` varchar(50) DEFAULT NULL,
  `Status` varchar(50) DEFAULT NULL,
  `TonerBlack` int DEFAULT NULL,
  `TonerCyan` int DEFAULT NULL,
  `TonerMagenta` int DEFAULT NULL,
  `TonerYellow` int DEFAULT NULL,
  `TotalPages` int DEFAULT NULL,
  `FaxPages` int DEFAULT NULL,
  `CopiedPages` int DEFAULT NULL,
  `PrintedPages` int DEFAULT NULL,
  `BWCopies` int DEFAULT NULL,
  `ColorCopies` int DEFAULT NULL,
  `MonoCopies` int DEFAULT NULL,
  `BichromeCopies` int DEFAULT NULL,
  `BWPrinted` int DEFAULT NULL,
  `BichromePrinted` int DEFAULT NULL,
  `MonoPrinted` int DEFAULT NULL,
  `ColorPrinted` int DEFAULT NULL,
  `TotalColor` int DEFAULT NULL,
  `TotalBW` int DEFAULT NULL,
  `DateInsertion` datetime DEFAULT NULL,
  `mac_norm` char(12) GENERATED ALWAYS AS (replace(upper(`MacAddress`),':','')) STORED,
  PRIMARY KEY (`id`),
  KEY `ix_compteur_date` (`Timestamp`),
  KEY `ix_compteur_mac_ts` (`mac_norm`,`Timestamp`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");
try { $pdo->exec("ALTER TABLE `compteur_relevee` ADD UNIQUE KEY `uniq_mac_ts` (`mac_norm`,`Timestamp`)"); } catch(Throwable $e){}

// ---------- Prepare insert-if-missing ----------
$cols = implode(',', $FIELDS).',DateInsertion';
$ph   = ':'.implode(',:',$FIELDS).',NOW()';
$sql  = "
INSERT INTO compteur_relevee ($cols)
SELECT $ph FROM DUAL
WHERE NOT EXISTS (
  SELECT 1 FROM compteur_relevee
  WHERE mac_norm = REPLACE(UPPER(:_mac_chk),':','')
    AND Timestamp = :_ts_chk
)";
$st = $pdo->prepare($sql);

// ---------- Lister, trier, couper ----------
$raw = $sftp->rawlist($sftp_path) ?: [];
$files=[];
$pat='/^COPIEUR_MAC-([A-F0-9:\-]+)_(\d{8}_\d{6})\.csv$/i';
foreach($raw as $name=>$meta){
  if($name==='.'||$name==='..') continue;
  if(!is_array($meta) || ($meta['type']??0)!==1) continue;
  if(!preg_match($pat,(string)$name)) continue;
  $files[]=['name'=>$name,'path'=>rtrim($sftp_path,'/').'/'.$name,'mtime'=>(int)($meta['mtime']??0)];
}
usort($files,fn($a,$b)=>$a['mtime']<=>$b['mtime']);
$batch=array_slice($files,0,$BATCH_SIZE);

// ---------- Traiter ----------
$proc=0;$ins=0;$err=0;$added=[];
foreach($batch as $f){
  $tmp=tempnam(sys_get_temp_dir(),'csv_');
  if(!$sftp->get($f['path'],$tmp)){
    $err++; sftp_safe_move($sftp,$f['path'],'/errors'); @unlink($tmp); continue;
  }
  $kv=parse_csv_kv($tmp); @unlink($tmp);
  $vals=[]; foreach($FIELDS as $k) $vals[$k]=$kv[$k]??null;
  if(empty($vals['MacAddress']) || empty($vals['Timestamp'])){
    $err++; sftp_safe_move($sftp,$f['path'],'/errors'); continue;
  }
  // Validation et conversion de la date
  $timestamp = strtotime((string)$vals['Timestamp']);
  if ($timestamp === false) {
    $err++; sftp_safe_move($sftp,$f['path'],'/errors'); continue;
  }
  $vals['Timestamp'] = date('Y-m-d H:i:s', $timestamp);
  try{
    $pdo->beginTransaction();
    $bind=[]; foreach($FIELDS as $k){ $bind[":$k"]=$vals[$k]; }
    $bind[':_mac_chk']=$vals['MacAddress']; $bind[':_ts_chk']=$vals['Timestamp'];
    $st->execute($bind);
    if($st->rowCount()===1){ $ins++; $added[]=$f['name']; }
    $pdo->commit();
    sftp_safe_move($sftp,$f['path'],'/processed');
    $proc++;
  }catch(Throwable $e){
    $pdo->rollBack(); $err++; sftp_safe_move($sftp,$f['path'],'/errors');
  }
}

// ---------- Journal ----------
$msg=json_encode(['source'=>'sftp','processed'=>$proc,'inserted'=>$ins,'errors'=>$err,'batch'=>$BATCH_SIZE,'files'=>array_slice($added,0,50)],JSON_UNESCAPED_UNICODE);
$pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),:i,:s,:ok,:m)")
    ->execute([':i'=>$proc-$err, ':s'=>$err, ':ok'=>($err?0:1), ':m'=>$msg]);

echo "OK SFTP batch proc=$proc ins=$ins err=$err\n";
