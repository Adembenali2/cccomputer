<?php
// import_compteurs.php
// Import depuis la base IONOS vers Railway compteur_relevee_ancien
declare(strict_types=1);

// 1) Connexion DB Railway (destination) via db.php
require_once __DIR__ . '/../../includes/db.php';

// Vérifier qu'on a bien un PDO
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "Erreur : PDO non initialisé par includes/db.php\n";
    exit(1);
}

$pdoDst = $GLOBALS['pdo']; // Railway (destination)

// Initialiser les compteurs
$inserted = 0;
$skipped = 0;
$ok = 1; // Par défaut OK
$errorMessage = null;
$totalRows = 0;

// --- helper pour log (affichage dans le navigateur ou CLI) ---
function logLine(string $msg): void {
    $isCli = php_sapi_name() === 'cli';
    if ($isCli) {
        // En CLI, pas de HTML
        echo $msg . "\n";
    } else {
        // En HTTP, avec HTML
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

// 2) Connexion IONOS (source)
$srcHost = 'db550618985.db.1and1.com';
$srcPort = 3306;
$srcDb   = 'db550618985';
$srcUser = 'dbo550618985';
$srcPass = 'kcamsoncamson';

try {
    $pdoSrc = new PDO(
        "mysql:host=$srcHost;port=$srcPort;dbname=$srcDb;charset=utf8mb4",
        $srcUser,
        $srcPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    $errorMessage = "Erreur de connexion à IONOS : " . $e->getMessage();
    logLine("❌ $errorMessage");
    $ok = 0;
    goto log_import_run;
}

// 3) Sélection des 30 derniers relevés par imprimante depuis IONOS
logLine("🔁 Connexion à IONOS et récupération des relevés...");

try {
    $sqlCompteurs = "
        SELECT
            x.compteur_id,
            x.printerinfo_id AS printer_id,
            x.compteur_date,
            x.totalNB,
            x.totalCouleur,
            x.compteur_du_mois,
            pi.refClient,
            pi.marque,
            pi.modele,
            pi.mac,
            pi.serialNum,
            pi.etat AS etat_imprimante
        FROM (
            SELECT
                ci.id              AS compteur_id,
                ci.printerinfo_id,
                ci.`date`          AS compteur_date,
                ci.totalNB,
                ci.totalCouleur,
                ci.compteur_du_mois,
                @rn := IF(@cur_printer = ci.printerinfo_id, @rn + 1, 1) AS rn,
                @cur_printer := ci.printerinfo_id AS cur_printer
            FROM compteur_info ci
            JOIN (SELECT @rn := 0, @cur_printer := 0) vars
            ORDER BY ci.printerinfo_id, ci.`date` DESC
        ) AS x
        INNER JOIN printer_info pi ON x.printerinfo_id = pi.id
        WHERE x.rn <= 30
        ORDER BY x.compteur_date DESC, x.printerinfo_id ASC
    ";
    
    $rows = $pdoSrc->query($sqlCompteurs)->fetchAll();
    $totalRows = count($rows);
    logLine("✅ Nombre de relevés trouvés : $totalRows");
    
    if ($totalRows === 0) {
        logLine("ℹ️ Aucun relevé à importer.");
        goto log_import_run;
    }
} catch (Throwable $e) {
    $errorMessage = "Erreur lors de la récupération des relevés IONOS : " . $e->getMessage();
    logLine("❌ $errorMessage");
    $ok = 0;
    goto log_import_run;
}

// 4) Préchargement de l'historique des toners depuis consommable
logLine("🔍 Chargement de l'historique des toners...");

$tonerHistory = [];
$refClients = array_unique(array_filter(array_column($rows, 'refClient')));
$printerIds = array_unique(array_filter(array_column($rows, 'printer_id'), function($id) {
    return is_numeric($id) && (int)$id > 0;
}));

if (!empty($refClients)) {
    try {
        $placeholders = implode(',', array_fill(0, count($refClients), '?'));
        $sqlConsommable = "
            SELECT id, `date`, ref_client, tmp_arr
            FROM consommable
            WHERE tmp_arr IS NOT NULL
              AND tmp_arr <> ''
              AND ref_client IN ($placeholders)
        ";
        
        $stmtConsommable = $pdoSrc->prepare($sqlConsommable);
        $stmtConsommable->execute($refClients);
        $consommableRows = $stmtConsommable->fetchAll();
        
        logLine("📦 " . count($consommableRows) . " entrées consommable trouvées");
        
        foreach ($consommableRows as $row) {
            $tmpArr = $row['tmp_arr'];
            if (empty($tmpArr)) continue;
            
            $data = @unserialize($tmpArr, ['allowed_classes' => false]);
            if ($data === false) continue;
            
            // Handle 2 possible shapes: associative array OR array[0] = associative array
            if (isset($data[0]) && is_array($data[0])) {
                $entries = $data;
            } else {
                $entries = [$data];
            }
            
            foreach ($entries as $arr) {
                if (!is_array($arr)) continue;
                
                $printerId = isset($arr['printer_id']) ? (int)$arr['printer_id'] : 0;
                if ($printerId <= 0 || !in_array($printerId, $printerIds)) continue;
                
                // Compute timestamp
                $ts = null;
                if (!empty($arr['tdate'])) {
                    $ts = is_numeric($arr['tdate']) ? (int)$arr['tdate'] : strtotime($arr['tdate']);
                } elseif (!empty($arr['cdate'])) {
                    $ts = is_numeric($arr['cdate']) ? (int)$arr['cdate'] : strtotime($arr['cdate']);
                } elseif (!empty($arr['date'])) {
                    $ts = is_numeric($arr['date']) ? (int)$arr['date'] : strtotime($arr['date']);
                } else {
                    $ts = strtotime($row['date']);
                }
                
                if ($ts === false || $ts === null) continue;
                
                // Read toner fields
                $tNoir  = $arr['toner_noir']    ?? null;
                $tCyan  = $arr['toner_cyan']    ?? null;
                $tMag   = $arr['toner_magenta'] ?? null;
                $tJaune = $arr['toner_jaune']   ?? null;
                
                // Skip if all 4 colors are null
                if ($tNoir === null && $tCyan === null && $tMag === null && $tJaune === null) {
                    continue;
                }
                
                if (!isset($tonerHistory[$printerId])) {
                    $tonerHistory[$printerId] = [];
                }
                
                $tonerHistory[$printerId][] = [
                    'ts' => $ts,
                    'k'  => $tNoir,
                    'c'  => $tCyan,
                    'm'  => $tMag,
                    'y'  => $tJaune,
                ];
            }
        }
        
        // Sort each printer's history by timestamp ASC
        foreach ($tonerHistory as $pid => &$events) {
            usort($events, function($a, $b) {
                return $a['ts'] <=> $b['ts'];
            });
        }
        unset($events);
        
        logLine("✅ Historique des toners chargé pour " . count($tonerHistory) . " imprimantes");
    } catch (Throwable $e) {
        logLine("⚠️ Erreur lors du chargement de l'historique des toners : " . $e->getMessage());
        // Continue anyway, toners will be null
    }
}

// 5) Helper pour trouver les toners à un timestamp donné
function findTonersForPrinterAtTs(array $tonerHistory, int $printerId, int $ts): array {
    if (!isset($tonerHistory[$printerId]) || empty($tonerHistory[$printerId])) {
        return ['k' => null, 'c' => null, 'm' => null, 'y' => null];
    }
    
    $events = $tonerHistory[$printerId];
    $best   = null;
    
    foreach ($events as $ev) {
        if ($ev['ts'] <= $ts) {
            $best = $ev;
        } else {
            break;
        }
    }
    
    if ($best === null) {
        return ['k' => null, 'c' => null, 'm' => null, 'y' => null];
    }
    
    foreach (['k', 'c', 'm', 'y'] as $col) {
        if ($best[$col] !== null) {
            $v = (int)$best[$col];
            if ($v > 100) $v = 100;
            if ($v < -100) $v = -100;
            $best[$col] = $v;
        }
    }
    
    return [
        'k' => $best['k'],
        'c' => $best['c'],
        'm' => $best['m'],
        'y' => $best['y'],
    ];
}

// 6) Préparation des requêtes pour Railway
// 6.a) Requête pour vérifier les doublons
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdoDst->prepare($sqlCheck);

// 6.b) Requête INSERT
$sqlInsert = "
    INSERT INTO compteur_relevee_ancien (
      Timestamp,
      IpAddress,
      Nom,
      Model,
      SerialNumber,
      MacAddress,
      Status,
      TonerBlack,
      TonerCyan,
      TonerMagenta,
      TonerYellow,
      TotalPages,
      FaxPages,
      CopiedPages,
      PrintedPages,
      BWCopies,
      ColorCopies,
      MonoCopies,
      BichromeCopies,
      BWPrinted,
      BichromePrinted,
      MonoPrinted,
      ColorPrinted,
      TotalColor,
      TotalBW,
      DateInsertion
    ) VALUES (
      :ts,          -- Timestamp
      NULL,         -- IpAddress
      :nom,         -- Nom
      :model,       -- Model
      :serial,      -- SerialNumber
      :mac,         -- MacAddress
      :status,      -- Status
      :toner_k,     -- TonerBlack
      :toner_c,     -- TonerCyan
      :toner_m,     -- TonerMagenta
      :toner_y,     -- TonerYellow
      :total_pages, -- TotalPages
      NULL,         -- FaxPages
      NULL,         -- CopiedPages
      NULL,         -- PrintedPages
      NULL,         -- BWCopies
      NULL,         -- ColorCopies
      NULL,         -- MonoCopies
      NULL,         -- BichromeCopies
      NULL,         -- BWPrinted
      NULL,         -- BichromePrinted
      NULL,         -- MonoPrinted
      NULL,         -- ColorPrinted
      :total_color, -- TotalColor
      :total_bw,    -- TotalBW
      NOW()         -- DateInsertion
    )
";
$stmtInsert = $pdoDst->prepare($sqlInsert);

// 7) Traitement de chaque relevé
logLine("🔄 Traitement de $totalRows relevés...");

foreach ($rows as $r) {
    $printerId = (int)$r['printer_id'];
    $mac = $r['mac'] ?? '';
    $timestamp = $r['compteur_date'] ?? null;
    
    if (empty($mac) || empty($timestamp)) {
        $skipped++;
        continue;
    }
    
    // Calculer les toners pour ce timestamp
    $tsUnix = strtotime($timestamp);
    if ($tsUnix === false) {
        $tsUnix = time();
    }
    
    $toners = findTonersForPrinterAtTs($tonerHistory, $printerId, $tsUnix);
    
    // Mapping des données
    $totalBW = (int)($r['totalNB'] ?? 0);
    $totalColor = (int)($r['totalCouleur'] ?? 0);
    $totalPages = $totalBW + $totalColor;
    
    // Vérifier les doublons
    try {
        $stmtCheck->execute([
            ':mac' => $mac,
            ':ts'  => $timestamp,
        ]);
        
        $existing = $stmtCheck->fetch();
        if ($existing) {
            $skipped++;
            continue;
        }
    } catch (Throwable $e) {
        logLine("⚠️ Erreur vérification doublon (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        continue;
    }
    
    // Insertion
    try {
        $stmtInsert->execute([
            ':ts'          => $timestamp,
            ':nom'         => $r['refClient'] ?? null,
            ':model'       => $r['modele'] ?? null,
            ':serial'      => $r['serialNum'] ?? null,
            ':mac'         => $mac,
            ':status'      => $r['etat_imprimante'] ?? null,
            ':toner_k'     => $toners['k'],
            ':toner_c'     => $toners['c'],
            ':toner_m'     => $toners['m'],
            ':toner_y'     => $toners['y'],
            ':total_pages' => $totalPages > 0 ? $totalPages : null,
            ':total_color' => $totalColor > 0 ? $totalColor : null,
            ':total_bw'    => $totalBW > 0 ? $totalBW : null,
        ]);
        $inserted++;
    } catch (Throwable $e) {
        logLine("⚠️ Erreur insertion (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        // Continue, mais on note qu'il y a eu une erreur
        continue;
    }
}

if ($inserted > 0 || $skipped > 0) {
    logLine("🎉 Import terminé.");
    logLine("➡️ Lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (déjà présentes MAC+Timestamp) : $skipped");
}

// 8) Enregistrement dans import_run pour suivi du dashboard
log_import_run:
try {
    // Créer la table si elle n'existe pas
    $pdoDst->exec("
        CREATE TABLE IF NOT EXISTS import_run (
            id INT NOT NULL AUTO_INCREMENT,
            ran_at DATETIME NOT NULL,
            imported INT NOT NULL,
            skipped INT NOT NULL,
            ok TINYINT(1) NOT NULL,
            msg TEXT,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    
    $totalProcessed = $inserted + $skipped;
    
    $msgData = [
        'source'       => 'ancien_import',
        'processed'    => $totalProcessed,
        'inserted'     => $inserted,
        'skipped'      => $skipped,
        'url'          => 'IONOS_DB',
        'cursor_index' => 0,
        'remaining'    => 0,
    ];
    if ($errorMessage !== null) {
        $msgData['error'] = $errorMessage;
    }
    $msg = json_encode($msgData, JSON_UNESCAPED_UNICODE);
    
    $stmtLog = $pdoDst->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    
    $stmtLog->execute([
        ':imported' => $inserted,
        ':skipped'  => $skipped,
        ':ok'       => $ok,
        ':msg'      => $msg
    ]);
    
    if ($inserted === 0 && $skipped === 0) {
        logLine("✅ Import IONOS OK — 0 élément");
    } else {
        logLine("📝 Enregistrement dans import_run réussi.");
    }
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de l'enregistrement dans import_run : " . $e->getMessage());
}
