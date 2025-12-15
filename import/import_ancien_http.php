<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors','1');

/**
 * /import/import_ancien_http.php
 * 
 * Script d'import des relevés de compteurs depuis une page web externe vers la base Railway.
 * 
 * FONCTIONNEMENT :
 * 1. Récupère le HTML depuis https://cccomputer.fr/test_compteur.php
 * 2. Parse le tableau HTML pour extraire les données des relevés
 * 3. Détecte automatiquement les colonnes via l'en-tête de la table (ou utilise un mapping par défaut)
 * 4. Filtre les enregistrements déjà importés (reprend depuis MAX(Timestamp))
 * 5. Insère les nouveaux relevés dans compteur_relevee_ancien
 * 6. Utilise INSERT ... ON DUPLICATE KEY UPDATE pour gérer les doublons via la contrainte UNIQUE (mac_norm, Timestamp)
 * 7. Journalise les résultats dans import_run
 * 
 * CONTRAINTE D'UNICITÉ :
 * - La table compteur_relevee_ancien a une contrainte UNIQUE sur (mac_norm, Timestamp)
 * - Cette contrainte garantit qu'un même couple MAC + Timestamp ne peut exister qu'une seule fois
 * - En cas de doublon, les données sont mises à jour (ON DUPLICATE KEY UPDATE)
 * 
 * CONFIGURATION :
 * - $URL : URL de la page source (par défaut: https://cccomputer.fr/test_compteur.php)
 * - $BATCH : Nombre maximum de relevés traités par exécution (par défaut: 100)
 * 
 * EXÉCUTION :
 * - Exécution automatique toutes les 2 minutes via le dashboard (run_import_web_if_due.php)
 * - Peut être exécuté manuellement : php import/import_ancien_http.php
 * - Peut être planifié via CRON (ex: toutes les 2 minutes)
 * - Utilise les variables d'environnement Railway pour la connexion DB (MYSQLHOST, MYSQLDATABASE, etc.)
 * - Importe 100 lignes maximum par exécution (configurable via $BATCH)
 * 
 * DONNÉES IMPORTÉES :
 * - Timestamp : Date et heure du relevé
 * - MacAddress : Adresse MAC du photocopieur
 * - Model : Modèle du photocopieur (si disponible)
 * - SerialNumber : Numéro de série (si disponible)
 * - Nom : Référence client (si disponible)
 * - Status : État du compteur (si disponible)
 * - TonerBlack, TonerCyan, TonerMagenta, TonerYellow : Niveaux de toner
 * - TotalBW, TotalColor, TotalPages : Compteurs d'impression
 * 
 * MAINTENANCE :
 * - Les logs sont écrits dans import_run avec source='WEB_COMPTEUR'
 * - Les erreurs sont loggées via error_log() et incluses dans le message JSON
 * - Le script reprend automatiquement depuis le dernier Timestamp importé
 * 
 * INTÉGRATION :
 * - Ce script est appelé via import/run_import_web_if_due.php (similaire à run_import_if_due.php pour SFTP)
 * - Le dashboard affiche le statut via import/last_import_web.php
 * - Utilise la même table import_run que l'import SFTP, avec source='WEB_COMPTEUR' pour différencier
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
$BATCH = 20; // Maximum 20 lignes par exécution (comme demandé)

// ---------- Tables ----------
$pdo->exec("CREATE TABLE IF NOT EXISTS import_run( id INT AUTO_INCREMENT PRIMARY KEY, ran_at DATETIME NOT NULL, imported INT NOT NULL, skipped INT NOT NULL, ok TINYINT(1) NOT NULL, msg TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

// ---------- S'assurer que la contrainte UNIQUE existe sur (mac_norm, Timestamp) ----------
// Cette contrainte garantit l'unicité du couple MAC + Timestamp
try {
    // Vérifier si la contrainte existe déjà avant de la créer
    $stmt = $pdo->query("
        SELECT COUNT(*) as cnt
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'compteur_relevee_ancien'
        AND CONSTRAINT_NAME = 'uniq_mac_ts_ancien'
        AND CONSTRAINT_TYPE = 'UNIQUE'
    ");
    $exists = (int)$stmt->fetchColumn() > 0;
    
    if (!$exists) {
        $pdo->exec("ALTER TABLE `compteur_relevee_ancien` ADD UNIQUE KEY `uniq_mac_ts_ancien` (`mac_norm`,`Timestamp`)");
        error_log('import_ancien_http: Contrainte UNIQUE créée sur compteur_relevee_ancien');
    }
} catch (Throwable $e) {
    // La contrainte existe déjà ou erreur - continuer
    error_log('import_ancien_http: Contrainte UNIQUE déjà présente ou erreur: ' . $e->getMessage());
}

// ---------- Récupérer le dernier Timestamp importé (pour reprendre depuis le dernier enregistrement) ----------
$lastTimestamp = null;
try {
    // Récupère le timestamp maximum déjà importé pour reprendre après celui-ci
    $stmtLast = $pdo->prepare("SELECT MAX(Timestamp) AS max_ts FROM compteur_relevee_ancien");
    $stmtLast->execute();
    $rowLast  = $stmtLast->fetch(PDO::FETCH_ASSOC);
    if ($rowLast && $rowLast['max_ts'] !== null) {
        $lastTimestamp = $rowLast['max_ts'];
    }
} catch (Throwable $e) {
    // Table peut ne pas exister encore - on importe tout depuis le début
    error_log('import_ancien_http: Error getting last timestamp: ' . $e->getMessage());
}

// ---------- Télécharger HTML ----------
$html = @file_get_contents($URL, false, stream_context_create([
    'http' => ['timeout' => 30, 'user_agent' => 'Mozilla/5.0 (compatible; ImportBot/1.0)']
]));
if($html === false){
    $errorMsg = json_encode([
        'source'=>'WEB_COMPTEUR',
        'ok'=>0,
        'processed'=>0,
        'inserted'=>0,
        'updated'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'reason'=>'download_html_failed',
        'error'=>'Failed to fetch URL: ' . $URL
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR ANCIEN Failed to fetch $URL\n";
    exit(1);
}

// ---------- Parser HTML (même logique que import_ancien_données.php) ----------
libxml_use_internal_errors(true);
$dom = new DOMDocument();
@$dom->loadHTML($html);
libxml_clear_errors();
$xpath = new DOMXPath($dom);

$table = $xpath->query('//table')->item(0);
if(!$table){
    $errorMsg = json_encode([
        'source'=>'WEB_COMPTEUR',
        'ok'=>0,
        'processed'=>0,
        'inserted'=>0,
        'updated'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'reason'=>'mapping_failed',
        'error'=>'No table found in HTML'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR ANCIEN No table found in HTML\n";
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

// ---------- Détection automatique des colonnes via l'en-tête de la table ----------
// On cherche l'en-tête pour mapper les colonnes correctement
$headerRow = $xpath->query('.//thead/tr | .//tr[th]', $table)->item(0);
$columnMap = [];
if($headerRow){
    $headerCells = $headerRow->getElementsByTagName('th');
    if($headerCells->length === 0) $headerCells = $headerRow->getElementsByTagName('td');
    
    for($i = 0; $i < $headerCells->length; $i++){
        $headerText = strtolower(trim($headerCells->item($i)->textContent ?? ''));
        if(strpos($headerText, 'mac') !== false) $columnMap['mac'] = $i;
        elseif(strpos($headerText, 'date') !== false || strpos($headerText, 'relevé') !== false) $columnMap['date'] = $i;
        elseif(strpos($headerText, 'ref') !== false && strpos($headerText, 'client') !== false) $columnMap['ref_client'] = $i;
        elseif(strpos($headerText, 'marque') !== false) $columnMap['marque'] = $i;
        elseif(strpos($headerText, 'modèle') !== false || strpos($headerText, 'model') !== false) $columnMap['modele'] = $i;
        elseif(strpos($headerText, 'série') !== false || strpos($headerText, 'serial') !== false) $columnMap['serial'] = $i;
        elseif(strpos($headerText, 'total nb') !== false || strpos($headerText, 'total bw') !== false) $columnMap['total_nb'] = $i;
        elseif(strpos($headerText, 'total couleur') !== false || strpos($headerText, 'total color') !== false) $columnMap['total_couleur'] = $i;
        elseif(strpos($headerText, 'toner k') !== false || strpos($headerText, 'toner black') !== false) $columnMap['toner_k'] = $i;
        elseif(strpos($headerText, 'toner c') !== false || strpos($headerText, 'toner cyan') !== false) $columnMap['toner_c'] = $i;
        elseif(strpos($headerText, 'toner m') !== false || strpos($headerText, 'toner magenta') !== false) $columnMap['toner_m'] = $i;
        elseif(strpos($headerText, 'toner y') !== false || strpos($headerText, 'toner yellow') !== false) $columnMap['toner_y'] = $i;
        elseif(strpos($headerText, 'état') !== false || strpos($headerText, 'status') !== false) $columnMap['status'] = $i;
    }
}

// Fallback: mapping par défaut si la détection automatique échoue
// Basé sur l'image fournie: ID, Date, Ref Client, Marque, Modèle, MAC, N° série, Total NB, Total Couleur, Compteur mois, Toner K/C/M/Y
if(empty($columnMap['mac']) || empty($columnMap['date'])){
    $columnMap = [
        'mac' => 5,        // Colonne MAC (index 5 d'après l'image)
        'date' => 1,       // Colonne Date relevé (index 1)
        'ref_client' => 2, // Colonne Ref Client (index 2)
        'marque' => 3,     // Colonne Marque (index 3)
        'modele' => 4,     // Colonne Modèle (index 4)
        'serial' => 6,     // Colonne N° de série (index 6)
        'total_nb' => 7,   // Colonne Total NB (index 7)
        'total_couleur' => 8, // Colonne Total Couleur (index 8)
        'toner_k' => 10,   // Colonne Toner K (index 10)
        'toner_c' => 11,   // Colonne Toner C (index 11)
        'toner_m' => 12,   // Colonne Toner M (index 12)
        'toner_y' => 13,   // Colonne Toner Y (index 13)
        'status' => 9,     // Colonne Compteur du mois (index 9) - utilisé comme status
    ];
}

// Validation du mapping des colonnes essentielles après fallback
if (empty($columnMap['mac']) || empty($columnMap['date'])) {
    $errorMsg = json_encode([
        'source'=>'WEB_COMPTEUR',
        'ok'=>0,
        'processed'=>0,
        'inserted'=>0,
        'updated'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'reason'=>'mapping_failed',
        'error'=>'Impossible de détecter les colonnes MAC et Date dans le tableau HTML'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,0,:m)")
        ->execute([':m'=>$errorMsg]);
    http_response_code(500);
    echo "ERROR ANCIEN Colonnes MAC/Date non détectées\n";
    exit(1);
}

$rowsData = [];
foreach($rows as $row){
    if(!$row instanceof DOMElement) continue;
    if($row->getElementsByTagName('th')->length > 0) continue; // Skip header
    
    $cells = $row->getElementsByTagName('td');
    // On a besoin d'au moins les colonnes MAC et Date
    $minCells = max($columnMap['mac'] ?? 0, $columnMap['date'] ?? 0) + 1;
    if($cells->length < $minCells) continue;
    
    // Extraction des données selon le mapping détecté
    $mac = isset($columnMap['mac']) ? $getCellText($cells->item($columnMap['mac'])) : '';
    $tsStr = isset($columnMap['date']) ? $getCellText($cells->item($columnMap['date'])) : '';
    
    if($mac === '' || $tsStr === '') continue;
    
    // Ne garder que les enregistrements plus récents que le dernier Timestamp importé
    // Cela permet de reprendre depuis le dernier enregistrement importé
    if($lastTimestamp !== null && $tsStr <= $lastTimestamp) continue;
    
    // Extraction des autres colonnes (optionnelles)
    $refClient = isset($columnMap['ref_client']) ? $getCellText($cells->item($columnMap['ref_client'])) : null;
    $marque = isset($columnMap['marque']) ? $getCellText($cells->item($columnMap['marque'])) : null;
    $modele = isset($columnMap['modele']) ? $getCellText($cells->item($columnMap['modele'])) : null;
    $serial = isset($columnMap['serial']) ? $getCellText($cells->item($columnMap['serial'])) : null;
    $totalNB = isset($columnMap['total_nb']) ? $getCellText($cells->item($columnMap['total_nb'])) : '';
    $totalClr = isset($columnMap['total_couleur']) ? $getCellText($cells->item($columnMap['total_couleur'])) : '';
    $status = isset($columnMap['status']) ? $getCellText($cells->item($columnMap['status'])) : '';
    
    $totalBW = is_numeric($totalNB) ? (int)$totalNB : 0;
    $totalColor = is_numeric($totalClr) ? (int)$totalClr : 0;
    $totalPages = $totalBW + $totalColor;
    
    $tk = isset($columnMap['toner_k']) ? $extractTonerValue($xpath, $cells->item($columnMap['toner_k'])) : null;
    $tc = isset($columnMap['toner_c']) ? $extractTonerValue($xpath, $cells->item($columnMap['toner_c'])) : null;
    $tm = isset($columnMap['toner_m']) ? $extractTonerValue($xpath, $cells->item($columnMap['toner_m'])) : null;
    $ty = isset($columnMap['toner_y']) ? $extractTonerValue($xpath, $cells->item($columnMap['toner_y'])) : null;
    
    $rowsData[] = [
        'mac' => $mac,
        'ts' => $tsStr,
        'ref_client' => $refClient !== '' ? $refClient : null,
        'marque' => $marque !== '' ? $marque : null,
        'modele' => $modele !== '' ? $modele : null,
        'serial' => $serial !== '' ? $serial : null,
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
    $msg = json_encode([
        'source'=>'WEB_COMPTEUR',
        'ok'=>1,
        'processed'=>0,
        'inserted'=>0,
        'updated'=>0,
        'skipped'=>0,
        'batch'=>$BATCH,
        'reason'=>'no_new_data'
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),0,0,1,:m)")->execute([':m'=>$msg]);
    echo "OK ANCIEN no new data\n";
    exit(0);
}

// Tri par Timestamp croissant (ASC) - du plus récent non importé au plus ancien
// Cela garantit qu'on importe dans l'ordre chronologique
usort($rowsData, fn($a,$b)=>strcmp($a['ts'],$b['ts']));

// Limiter à BATCH
$remaining = max(0, $totalCandidates - $BATCH);
if(count($rowsData) > $BATCH){
    $rowsData = array_slice($rowsData, 0, $BATCH);
}

// ---------- Requêtes ----------
// Utilisation de INSERT ... ON DUPLICATE KEY UPDATE pour gérer automatiquement les doublons
// La contrainte UNIQUE sur (mac_norm, Timestamp) garantit l'unicité
// Si un doublon est détecté, on met à jour les données (ou on ignore selon le besoin)
$sqlInsert = "
    INSERT INTO compteur_relevee_ancien (
      Timestamp, IpAddress, Nom, Model, SerialNumber, MacAddress, Status,
      TonerBlack, TonerCyan, TonerMagenta, TonerYellow,
      TotalPages, FaxPages, CopiedPages, PrintedPages,
      BWCopies, ColorCopies, MonoCopies, BichromeCopies,
      BWPrinted, BichromePrinted, MonoPrinted, ColorPrinted,
      TotalColor, TotalBW, DateInsertion
    ) VALUES (
      :ts, NULL, :nom, :model, :serial, :mac, :status,
      :tk, :tc, :tm, :ty,
      :total_pages, NULL, NULL, NULL,
      NULL, NULL, NULL, NULL,
      NULL, NULL, NULL, NULL,
      :total_color, :total_bw, NOW()
    )
    ON DUPLICATE KEY UPDATE
      Nom = VALUES(Nom),
      Model = VALUES(Model),
      SerialNumber = VALUES(SerialNumber),
      Status = VALUES(Status),
      TonerBlack = VALUES(TonerBlack),
      TonerCyan = VALUES(TonerCyan),
      TonerMagenta = VALUES(TonerMagenta),
      TonerYellow = VALUES(TonerYellow),
      TotalPages = VALUES(TotalPages),
      TotalColor = VALUES(TotalColor),
      TotalBW = VALUES(TotalBW),
      DateInsertion = NOW()
";
$stmtInsert = $pdo->prepare($sqlInsert);

// ---------- Insertion ----------
// Utilisation de la contrainte UNIQUE pour gérer automatiquement les doublons
// INSERT ... ON DUPLICATE KEY UPDATE met à jour les données si le couple (mac_norm, Timestamp) existe déjà
$pdo->beginTransaction();
$imported=0; $skipped=0; $updated=0; $added=[]; $errors=[];
// Initialiser ok=1 par défaut (succès) - sera modifié en cas d'erreur
$isOk = 1;
$reason = null;
try{
    foreach($rowsData as $data){
        $mac = $data['mac'];
        $tsStr = $data['ts'];
        
        // Insert ou Update selon la contrainte UNIQUE
        try{
            $stmtInsert->execute([
                ':ts'=>$tsStr, 
                ':mac'=>$mac, 
                ':nom'=>$data['ref_client'] ?? null, // Utilise Ref Client comme Nom
                ':model'=>$data['modele'] ?? null,
                ':serial'=>$data['serial'] ?? null,
                ':status'=>$data['status'],
                ':tk'=>$data['tk'], 
                ':tc'=>$data['tc'], 
                ':tm'=>$data['tm'], 
                ':ty'=>$data['ty'],
                ':total_pages'=>$data['total_pages'],
                ':total_color'=>$data['total_color'],
                ':total_bw'=>$data['total_bw'],
            ]);
            
            // Vérifier si c'était un INSERT (1 ligne affectée) ou un UPDATE (2 lignes affectées)
            $affectedRows = $stmtInsert->rowCount();
            if($affectedRows === 1){
                // Nouvel enregistrement inséré
                $imported++;
                $added[] = "$mac@$tsStr";
            } elseif($affectedRows === 2){
                // Enregistrement mis à jour (doublon détecté et mis à jour)
                $updated++;
                $skipped++; // Compté comme "skipped" car ce n'était pas un nouvel enregistrement
            } else {
                // Aucune ligne affectée (cas rare)
                $skipped++;
            }
        }catch(Throwable $e){
            $skipped++;
            $errors[] = "MAC:$mac TS:$tsStr - " . $e->getMessage();
            error_log('import_ancien_http: Insert error for ' . $mac . '@' . $tsStr . ': ' . $e->getMessage());
        }
    }
    $pdo->commit();
}catch(Throwable $e){
    $pdo->rollBack();
    $imported=0; 
    $updated=0;
    $skipped=count($rowsData);
    $errors[] = "Transaction failed: " . $e->getMessage();
    error_log('import_ancien_http: Transaction error: ' . $e->getMessage());
    // En cas d'exception transaction, ok=0
    $isOk = 0;
    $reason = 'transaction_failed';
}

// ---------- Journal ----------
// Déterminer si l'import est réussi (ok=1) ou en erreur (ok=0)
// ok=1 si le script a réussi (même si 0 ligne insérée)
// ok=0 uniquement si exception / download HTML fail / mapping fail / insert fail
// Note: $isOk et $reason peuvent déjà être définis dans le catch de transaction (transaction_failed)

// Si pas déjà défini par une exception transaction, déterminer le statut
if ($isOk === 1 && $reason === null) {
    if (count($rowsData) === 0) {
        // Aucune nouvelle donnée = succès (rien à importer)
        $reason = 'no_new_data';
    } elseif (count($rowsData) > 0 && $imported === 0 && count($errors) > 0) {
        // Cas d'erreur : on avait des candidats mais rien n'a été importé ET il y a des erreurs
        // Erreur lors de l'insertion = échec
        $isOk = 0;
        $reason = 'insert_failed';
    } elseif (count($rowsData) > 0 && $imported === 0 && $updated === 0 && count($errors) === 0) {
        // Cas étrange : candidats mais rien inséré ni mis à jour ni erreur = probablement un problème
        // Mais on considère ça comme OK si pas d'erreur explicite
        $reason = 'no_new_data';
    }
    // Si $imported > 0 ou $updated > 0, c'est un succès, on garde ok=1 et reason=null
}

$msg = json_encode([
    'source'=>'WEB_COMPTEUR',
    'ok'=>$isOk,
    'processed'=>count($rowsData),
    'inserted'=>$imported,
    'updated'=>$updated ?? 0, // Nombre d'enregistrements mis à jour (doublons)
    'skipped'=>$skipped,
    'batch'=>$BATCH,
    'remaining_estimate'=>$remaining,
    'last_timestamp'=>$lastTimestamp,
    'reason'=>$reason,
    'files'=>array_slice($added,0,20), // Limiter à 20 pour éviter un JSON trop gros
    'errors'=>count($errors) > 0 ? array_slice($errors,0,10) : null // Limiter les erreurs affichées
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$pdo->prepare("INSERT INTO import_run(ran_at,imported,skipped,ok,msg) VALUES (NOW(),:i,:s,:ok,:m)")
    ->execute([':i'=>$imported, ':s'=>$skipped, ':ok'=>$isOk, ':m'=>$msg]);

// Message de sortie pour le dashboard
if ($isOk === 1) {
    if ($imported > 0) {
        $updateMsg = isset($updated) && $updated > 0 ? " updated=$updated" : "";
        echo "OK ANCIEN batch inserted=$imported$updateMsg skipped=$skipped remaining≈$remaining\n";
    } else {
        echo "OK ANCIEN no new data to import\n";
    }
} else {
    $errorDetails = count($errors) > 0 ? ' - ' . implode('; ', array_slice($errors, 0, 3)) : '';
    echo "ERROR ANCIEN batch inserted=$imported skipped=$skipped errors detected$errorDetails\n";
}

