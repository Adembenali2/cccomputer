<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * /import/upload_compteur_ionos.php
 * - Lit HTML depuis l'URL Ionos (IONOS_URL)
 * - Traite au plus 20 relevés par run
 * - Plus anciens d'abord (mtime ASC)
 * - Dédup via (mac_norm, Timestamp) - la combinaison MAC + Time doit être unique
 * - Reprend depuis le dernier enregistrement importé (MAX(Timestamp))
 * - Journalise dans import_run (source=ionos_import)
 * - S'exécute automatiquement toutes les 2 minutes
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
// URL Ionos - à configurer via variable d'environnement ou modifier ici
$URL = getenv('IONOS_URL') ?: 'https://cccomputer.fr/test_compteur.php'; // TODO: Remplacer par l'URL Ionos réelle
$BATCH = 20; // Maximum 20 relevés par exécution

// ---------- Tables ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS import_run( id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ---------- Récupérer le dernier Timestamp importé (pour reprendre depuis le dernier enregistrement) ----------
$lastTimestamp = null;
try {
    // Récupère le timestamp maximum déjà importé pour reprendre après celui-ci
    $stmtLast = $pdo->query("SELECT MAX(Timestamp) AS max_ts FROM compteur_relevee_ancien");
    $rowLast  = $stmtLast->fetch(PDO::FETCH_ASSOC);
    if ($rowLast && $rowLast['max_ts'] !== null) {
        $lastTimestamp = $rowLast['max_ts'];
    }
} catch (Throwable $e) {
    // Table peut ne pas exister encore - on importe tout depuis le début
    error_log('upload_compteur_ionos: Error getting last timestamp: ' . $e->getMessage());
}

// ---------- Télécharger HTML ----------
$html = @file_get_contents($URL, false, stream_context_create([
    'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (compatible; ImportBot/1.0)']
]));
if($html === false){
    $errorMsg = json_encode([
        'source'=>'ionos_import',
        'processed'=>0,
        'inserted'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'error'=>'Failed to fetch URL: ' . $URL
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR IONOS Failed to fetch $URL\n";
    exit(1);
}

// ---------- Parser HTML ----------
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$table = $xpath->query('//table')->item(0);
if(!$table){
    $errorMsg = json_encode([
        'source'=>'ionos_import',
        'processed'=>0,
        'inserted'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'error'=>'No table found in HTML'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR IONOS No table found in HTML\n";
    exit(1);
}

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
    
    // Ne garder que les enregistrements plus récents que le dernier Timestamp importé
    // Cela permet de reprendre depuis le dernier enregistrement importé
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
    $msg = json_encode(['source'=>'ionos_import','processed'=>0,'inserted'=>0,'skipped'=>0,'batch'=>$BATCH], JSON_UNESCAPED_UNICODE);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,1,:m)")->execute([':m'=>$msg]);
    echo "OK IONOS no new data\n";
    exit(0);
}

// Tri par Timestamp croissant (ASC) - du plus récent non importé au plus ancien
// Cela garantit qu'on importe dans l'ordre chronologique
usort($rowsData, fn($a,$b)=>strcmp($a['ts'],$b['ts']));

// Limiter à BATCH (20 relevés)
$remaining = max(0, $totalCandidates - $BATCH);
if(count($rowsData) > $BATCH){
    $rowsData = array_slice($rowsData, 0, $BATCH);
}

// ---------- Requêtes ----------
// Vérification d'unicité : la combinaison MAC_ADDRESS + Timestamp doit être unique
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
$imported=0; $skipped=0; $added=[]; $errors=[];
try{
    foreach($rowsData as $data){
        $mac = $data['mac'];
        $tsStr = $data['ts'];
        
        // Vérifier doublon (unicité MAC + Timestamp)
        $stmtCheck->execute([':mac'=>$mac, ':ts'=>$tsStr]);
        if($stmtCheck->fetch()){ 
            $skipped++; 
            continue; 
        }
        
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
            $errors[] = "MAC:$mac TS:$tsStr - " . $e->getMessage();
            error_log('upload_compteur_ionos: Insert error for ' . $mac . '@' . $tsStr . ': ' . $e->getMessage());
        }
    }
    $pdo->commit();
}catch(Throwable $e){
    $pdo->rollBack();
    $imported=0; 
    $skipped=count($rowsData);
    $errors[] = "Transaction failed: " . $e->getMessage();
    error_log('upload_compteur_ionos: Transaction error: ' . $e->getMessage());
}

// ---------- Journal ----------
// Déterminer si l'import est réussi (ok=1) ou en erreur (ok=0)
$isOk = 1;
if (count($rowsData) > 0 && $imported === 0) {
    // On avait des candidats mais rien n'a été importé = erreur
    $isOk = 0;
}
if (count($errors) > 0 && $imported === 0) {
    // Des erreurs se sont produites et rien n'a été importé = erreur
    $isOk = 0;
}

$msg = json_encode([
    'source'=>'ionos_import',
    'processed'=>count($rowsData),
    'inserted'=>$imported,
    'skipped'=>$skipped,
    'batch'=>$BATCH,
    'remaining_estimate'=>$remaining,
    'last_timestamp'=>$lastTimestamp,
    'files'=>array_slice($added,0,50) // Limiter à 50 pour éviter un JSON trop gros
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),:i,:s,:ok,:m)")
    ->execute([':i'=>$imported, ':s'=>$skipped, ':ok'=>$isOk, ':m'=>$msg]);

// Message de sortie pour le dashboard
if ($isOk === 1) {
    if ($imported > 0) {
        echo "OK IONOS batch inserted=$imported skipped=$skipped remaining≈$remaining\n";
    } else {
        echo "OK IONOS no new data to import\n";
    }
} else {
    $errorDetails = count($errors) > 0 ? ' - ' . implode('; ', array_slice($errors, 0, 3)) : '';
    echo "ERROR IONOS batch inserted=$imported skipped=$skipped errors detected$errorDetails\n";
}

