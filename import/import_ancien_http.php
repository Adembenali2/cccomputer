<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

/**
 * /import/import_ancien_http.php
 * - Lit HTML depuis https://cccomputer.fr/test_compteur.php
 * - Traite au plus 20 relevés par run, plus anciens d'abord
 * - Dédup via (mac_norm, Timestamp)
 * - Utilise MAX(Timestamp) pour ne prendre que les plus récents
 * - Journalise dans import_run (source=ancien_import)
 * - Basé sur import_ancien_données.php
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
$URL = 'https://cccomputer.fr/test_compteur.php';
$BATCH = 20; // Maximum 20 relevés par exécution

// ---------- Tables ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS import_run( id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ---------- Récupérer le dernier Timestamp en base ----------
$lastTimestamp = null;
try {
    $stmtLast = $pdo->query("SELECT MAX(Timestamp) AS max_ts FROM compteur_relevee_ancien");
    $rowLast  = $stmtLast->fetch(PDO::FETCH_ASSOC);
    if ($rowLast && $rowLast['max_ts'] !== null) {
        $lastTimestamp = $rowLast['max_ts'];
    }
} catch (Throwable $e) {
    // Table peut ne pas exister encore
}

// ---------- Télécharger HTML ----------
$html = @file_get_contents($URL, false, stream_context_create([
    'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (compatible; ImportBot/1.0)']
]));
if($html === false){ http_response_code(500); exit("Failed to fetch $URL\n"); }

// ---------- Parser HTML (même logique que import_ancien_données.php) ----------
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$table = $xpath->query('//table')->item(0);
if(!$table){ http_response_code(500); exit("No table found\n"); }

$rows = $xpath->query('.//tbody/tr', $table);
if($rows->length === 0){ $rows = $xpath->query('.//tr', $table); }

$getCellText = function (?DOMNode $td): string {
    if (!$td) return '';
    return trim($td->textContent ?? '');
};

$extractTonerValue = function (DOMXPath $xpath, DOMNode $td): ?int {
    $tonerDiv = $xpath->query('.//div[contains(@class, "toner")]', $td)->item(0);
    $txt = $tonerDiv ? trim($tonerDiv->textContent ?? '') : trim($td->textContent ?? '');
    if($txt === '') return null;
    if(preg_match('/-?\d+/', $txt, $m)) return (int)$m[0];
    return null;
};

$rowsData = [];
foreach($rows as $row){
    if(!$row instanceof DOMElement) continue;
    if($row->getElementsByTagName('th')->length > 0) continue; // Skip header
    
    $cells = $row->getElementsByTagName('td');
    if($cells->length < 10) continue;
    
    // Colonnes: 0=Ref Client, 1=MAC, 2=Date, 3=Total NB, 4=Total Couleur, 5=État, 6-9=Toner K/C/M/Y
    $mac = $getCellText($cells->item(1));
    $tsStr = $getCellText($cells->item(2));
    $totalNB = $getCellText($cells->item(3));
    $totalClr = $getCellText($cells->item(4));
    $status = $getCellText($cells->item(5));
    
    if($mac === '' || $tsStr === '') continue;
    
    // Ne garder que les plus récents que le dernier Timestamp
    if($lastTimestamp !== null && $tsStr <= $lastTimestamp) continue;
    
    $totalBW = is_numeric($totalNB) ? (int)$totalNB : 0;
    $totalColor = is_numeric($totalClr) ? (int)$totalClr : 0;
    $totalPages = $totalBW + $totalColor;
    
    $tk = $extractTonerValue($xpath, $cells->item(6));
    $tc = $extractTonerValue($xpath, $cells->item(7));
    $tm = $extractTonerValue($xpath, $cells->item(8));
    $ty = $extractTonerValue($xpath, $cells->item(9));
    
    $rowsData[] = [
        'mac' => $mac,
        'ts' => $tsStr,
        'status' => $status !== '' ? $status : null,
        'tk' => $tk,
        'tc' => $tc,
        'tm' => $tm,
        'ty' => $ty,
        'total_pages' => $totalPages ?: null,
        'total_color' => $totalColor ?: null,
        'total_bw' => $totalBW ?: null,
    ];
}

$totalCandidates = count($rowsData);

if($totalCandidates === 0){
    $msg = json_encode(['source'=>'ancien_import','processed'=>0,'inserted'=>0,'skipped'=>0,'batch'=>$BATCH], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,1,:m)")->execute([':m'=>$msg]);
    echo "OK ANCIEN no new data\n";
    exit(0);
}

// Tri par Timestamp croissant (anciens en premier)
usort($rowsData, fn($a,$b)=>strcmp($a['ts'],$b['ts']));

// Limiter à BATCH
$remaining = max(0, $totalCandidates - $BATCH);
if(count($rowsData) > $BATCH){
    $rowsData = array_slice($rowsData, 0, $BATCH);
}

// ---------- Requêtes ----------
$sqlCheck = "SELECT id FROM compteur_relevee_ancien WHERE mac_norm = REPLACE(UPPER(:mac), ':', '') AND Timestamp <=> :ts LIMIT 1";
$stmtCheck = $pdo->prepare($sqlCheck);

$sqlInsert = "
    INSERT INTO compteur_relevee_ancien (
      Timestamp, IpAddress, Nom, Model, SerialNumber, MacAddress, Status,
      TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
      TotalPages, FaxPages, CopiedPages, PrintedPages,
      BWCopies, ColorCopies, MonoCopies, BichromeCopies,
      BWPrinted, BichromePrinted, MonoPrinted, ColorPrinted,
      TotalColor, TotalBW, DateInsertion
    ) VALUES (
      :ts, NULL, NULL, NULL, NULL, :mac, :status,
      :tk, :tc, :tm, :ty,
      :total_pages, NULL, NULL, NULL,
      NULL, NULL, NULL, NULL,
      NULL, NULL, NULL, NULL,
      :total_color, :total_bw, NOW()
    )
";
$stmtInsert = $pdo->prepare($sqlInsert);

// ---------- Insertion ----------
$pdo->beginTransaction();
$imported=0; $skipped=0; $added=[];
try{
    foreach($rowsData as $data){
        $mac = $data['mac'];
        $tsStr = $data['ts'];
        
        // Vérifier doublon
        $stmtCheck->execute([':mac'=>$mac, ':ts'=>$tsStr]);
        if($stmtCheck->fetch()){ $skipped++; continue; }
        
        // Insert
        try{
            $stmtInsert->execute([
                ':ts'=>$tsStr, ':mac'=>$mac, ':status'=>$data['status'],
                ':tk'=>$data['tk'], ':tc'=>$data['tc'], ':tm'=>$data['tm'], ':ty'=>$data['ty'],
                ':total_pages'=>$data['total_pages'],
                ':total_color'=>$data['total_color'],
                ':total_bw'=>$data['total_bw'],
            ]);
            $imported++;
            $added[] = "$mac@$tsStr";
        }catch(Throwable $e){
            $skipped++;
        }
    }
    $pdo->commit();
}catch(Throwable $e){
    $pdo->rollBack();
    $imported=0; $skipped=count($rowsData);
}

// ---------- Journal ----------
$msg = json_encode([
    'source'=>'ancien_import',
    'processed'=>count($rowsData),
    'inserted'=>$imported,
    'skipped'=>$skipped,
    'batch'=>$BATCH,
    'remaining_estimate'=>$remaining,
    'files'=>array_slice($added,0,50)
], JSON_UNESCAPED_UNICODE);

$pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),:i,:s,:ok,:m)")
    ->execute([':i'=>$imported, ':s'=>$skipped, ':ok'=>($skipped>0?0:1), ':m'=>$msg]);

echo "OK ANCIEN batch inserted=$imported skipped=$skipped remaining≈$remaining\n";

