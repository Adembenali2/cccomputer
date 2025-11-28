<?php
// import_compteurs.php
// Import depuis URL HTML table vers Railway compteur_relevee_ancien
// Basé sur import_ancien_données.php - 20 compteurs par exécution, commence par les anciens
declare(strict_types=1);

// 1) Connexion DB Railway (destination) via db.php
logLine("🔧 Étape 1: Chargement de db.php");
require_once __DIR__ . '/../../includes/db.php';
logLine("✅ db.php chargé");

// Vérifier qu'on a bien un PDO
logLine("🔧 Étape 2: Vérification de la connexion PDO");
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
    logLine("❌ ERREUR: PDO non initialisé par includes/db.php");
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "Erreur : PDO non initialisé par includes/db.php\n";
    exit(1);
}

$pdo = $GLOBALS['pdo']; // Railway (destination)
logLine("✅ PDO Railway initialisé avec succès");

// Initialiser les compteurs
$inserted = 0;
$skipped = 0;
$ok = 1; // Par défaut OK
$errorMessage = null;
$MAX_INSERT = 20; // Maximum 20 relevés par exécution

// --- helper pour log (affichage dans le navigateur ou CLI) ---
function logLine(string $msg): void {
    $isCli = php_sapi_name() === 'cli';
    $timestamp = date('Y-m-d H:i:s');
    $msgWithTime = "[$timestamp] $msg";
    if ($isCli) {
        // En CLI, pas de HTML
        echo $msgWithTime . "\n";
    } else {
        // En HTTP, avec HTML
        echo htmlspecialchars($msgWithTime, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
    // Toujours logger dans error_log aussi pour le debug
    error_log("IMPORT_ANCIEN: $msgWithTime");
}

// 2) Vérifier la table compteur_relevee_ancien
logLine("🔧 Étape 3: Vérification de la table compteur_relevee_ancien");
try {
    $pdo->query("SELECT 1 FROM compteur_relevee_ancien LIMIT 1");
    logLine("✅ Table compteur_relevee_ancien accessible");
} catch (Throwable $e) {
    $errorMessage = "La table compteur_relevee_ancien n'existe pas ou est inaccessible : " . $e->getMessage();
    logLine("❌ ERREUR: $errorMessage");
    $ok = 0;
    goto log_import_run;
}

// 3) Récupérer le dernier Timestamp en base (pour ne prendre que les plus récents)
logLine("🔧 Étape 4: Récupération du dernier Timestamp");
$lastTimestamp = null;
try {
    $stmtLast = $pdo->query("SELECT MAX(Timestamp) AS max_ts FROM compteur_relevee_ancien");
    $rowLast  = $stmtLast->fetch(PDO::FETCH_ASSOC);
    if ($rowLast && $rowLast['max_ts'] !== null) {
        $lastTimestamp = $rowLast['max_ts'];
        logLine("ℹ️ Dernier Timestamp déjà en base : " . $lastTimestamp);
    } else {
        logLine("ℹ️ Aucune donnée existante en base, import complet possible.");
    }
} catch (Throwable $e) {
    logLine("⚠️ Impossible de récupérer le dernier Timestamp : " . $e->getMessage());
}

// 4) Télécharger le HTML depuis l'URL
logLine("🔧 Étape 5: Téléchargement depuis l'URL");
$url = 'https://cccomputer.fr/test_compteur.php';

try {
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (compatible; ImportBot/1.0)',
        ]
    ]);
    
    $html = @file_get_contents($url, false, $context);
    if ($html === false) {
        throw new Exception("Impossible de télécharger l'URL: $url");
    }
    
    logLine("✅ HTML téléchargé (" . strlen($html) . " bytes)");
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du téléchargement de l'URL : " . $e->getMessage();
    logLine("❌ ERREUR: $errorMessage");
    $ok = 0;
    goto log_import_run;
}

// 5) Parser le HTML table (même logique que import_ancien_données.php)
logLine("🔧 Étape 6: Parsing du HTML");
$rowsData = [];

try {
    // Utiliser DOMDocument pour parser le HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // On prend le premier tableau
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        throw new Exception("Aucun tableau <table> trouvé dans la page.");
    }
    
    // On essaie d'abord tbody/tr, sinon directement tr
    $rows = $xpath->query('.//tbody/tr', $table);
    if ($rows->length === 0) {
        $rows = $xpath->query('.//tr', $table);
    }
    
    logLine("✅ Nombre de lignes trouvées : " . $rows->length);
    
    // Helpers pour lire les cellules
    $getCellText = function (?DOMNode $td): string {
        if (!$td) {
            return '';
        }
        return trim($td->textContent ?? '');
    };
    
    /**
     * Extrait une valeur de toner (int) depuis la cellule :
     *  - cherche <div class="toner">80%</div> si présent
     *  - sinon, cherche un nombre dans tout le texte
     *  - retourne null si rien
     */
    $extractTonerValue = function (DOMXPath $xpath, DOMNode $td): ?int {
        $tonerDiv = $xpath->query('.//div[contains(@class, "toner")]', $td)->item(0);
        if ($tonerDiv) {
            $txt = trim($tonerDiv->textContent ?? '');
        } else {
            $txt = trim($td->textContent ?? '');
        }
        if ($txt === '') {
            return null;
        }
        if (preg_match('/-?\d+/', $txt, $m)) {
            return (int)$m[0];
        }
        return null;
    };
    
    // Parcours des lignes HTML -> constitution d'un tableau à insérer
    foreach ($rows as $row) {
        if (!$row instanceof DOMElement) {
            continue;
        }
        
        // Si la ligne contient des <th>, on considère que c'est un header
        if ($row->getElementsByTagName('th')->length > 0) {
            continue;
        }
        
        $cells = $row->getElementsByTagName('td');
        if ($cells->length < 10) {
            // Pas assez de colonnes, on ignore
            continue;
        }
        
        // Colonnes attendues (même structure que import_ancien_données.php) :
        // 0: Ref Client (non utilisé)
        // 1: MAC
        // 2: Date (Timestamp)
        // 3: Total NB
        // 4: Total Couleur
        // 5: État
        // 6: Toner K
        // 7: Toner C
        // 8: Toner M
        // 9: Toner Y
        
        $refClient = $getCellText($cells->item(0)); // pour info seulement
        $mac       = $getCellText($cells->item(1));
        $tsStr     = $getCellText($cells->item(2));
        $totalNB   = $getCellText($cells->item(3));
        $totalClr  = $getCellText($cells->item(4));
        $status    = $getCellText($cells->item(5));
        
        if ($mac === '' || $tsStr === '') {
            // Ligne incomplète, on ignore
            continue;
        }
        
        // Si on a déjà un dernier Timestamp, on ne garde que les plus récents
        if ($lastTimestamp !== null && $tsStr <= $lastTimestamp) {
            continue;
        }
        
        $totalBW    = is_numeric($totalNB)  ? (int)$totalNB  : 0;
        $totalColor = is_numeric($totalClr) ? (int)$totalClr : 0;
        $totalPages = $totalBW + $totalColor;
        
        $tk = $extractTonerValue($xpath, $cells->item(6));
        $tc = $extractTonerValue($xpath, $cells->item(7));
        $tm = $extractTonerValue($xpath, $cells->item(8));
        $ty = $extractTonerValue($xpath, $cells->item(9));
        
        $rowsData[] = [
            'mac'         => $mac,
            'ts'          => $tsStr,
            'status'      => $status !== '' ? $status : null,
            'tk'          => $tk,
            'tc'          => $tc,
            'tm'          => $tm,
            'ty'          => $ty,
            'total_pages' => $totalPages ?: null,
            'total_color' => $totalColor ?: null,
            'total_bw'    => $totalBW ?: null,
        ];
    }
    
    logLine("ℹ️ Lignes candidates après filtrage sur le dernier Timestamp : " . count($rowsData));
    
    if (count($rowsData) === 0) {
        logLine("ℹ️ Aucun nouveau compteur à importer.");
        goto log_import_run;
    }
    
    // Tri par Timestamp croissant pour insérer dans l'ordre (commence par les anciens)
    usort($rowsData, static function (array $a, array $b): int {
        return strcmp($a['ts'], $b['ts']);
    });
    
    // Limitation à MAX_INSERT relevées (20)
    if (count($rowsData) > $MAX_INSERT) {
        $rowsData = array_slice($rowsData, 0, $MAX_INSERT);
        logLine("ℹ️ Limitation à $MAX_INSERT nouvelles relevées pour cette exécution.");
    }
    
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du parsing HTML : " . $e->getMessage();
    logLine("❌ ERREUR: $errorMessage");
    logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
    logLine("❌ Trace: " . $e->getTraceAsString());
    $ok = 0;
    goto log_import_run;
}

// 6) Préparation des requêtes pour Railway
logLine("🔧 Étape 7: Préparation des requêtes SQL pour Railway");

// 6.a) Requête pour vérifier les doublons (MAC + Timestamp)
logLine("🔧 Préparation de la requête de vérification des doublons...");
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdo->prepare($sqlCheck);
logLine("✅ Requête de vérification préparée");

// 6.b) Requête INSERT
logLine("🔧 Préparation de la requête INSERT...");
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
      :ts,
      NULL,          -- IpAddress
      NULL,          -- Nom
      NULL,          -- Model
      NULL,          -- SerialNumber
      :mac,          -- MacAddress
      :status,       -- Status
      :tk,           -- TonerBlack
      :tc,           -- TonerCyan
      :tm,           -- TonerMagenta
      :ty,           -- TonerYellow
      :total_pages,  -- TotalPages
      NULL,          -- FaxPages
      NULL,          -- CopiedPages
      NULL,          -- PrintedPages
      NULL,          -- BWCopies
      NULL,          -- ColorCopies
      NULL,          -- MonoCopies
      NULL,          -- BichromeCopies
      NULL,          -- BWPrinted
      NULL,          -- BichromePrinted
      NULL,          -- MonoPrinted
      NULL,          -- ColorPrinted
      :total_color,  -- TotalColor
      :total_bw,     -- TotalBW
      NOW()          -- DateInsertion
    )
";
$stmtInsert = $pdo->prepare($sqlInsert);
logLine("✅ Requête INSERT préparée");

// 7) Insertion en base (max 20 lignes)
logLine("🔧 Étape 8: Insertion en base (max $MAX_INSERT lignes)");

foreach ($rowsData as $data) {
    $mac   = $data['mac'];
    $tsStr = $data['ts'];
    
    // Vérifier si déjà présent (MAC + Timestamp)
    try {
        $stmtCheck->execute([
            ':mac' => $mac,
            ':ts'  => $tsStr,
        ]);
        $existing = $stmtCheck->fetch();
    } catch (Throwable $e) {
        logLine("⚠️ Erreur lors du SELECT (MAC=$mac, TS=$tsStr) : " . $e->getMessage());
        continue;
    }
    
    if ($existing) {
        // Doublon, on saute
        $skipped++;
        continue;
    }
    
    // Insertion
    try {
        $stmtInsert->execute([
            ':ts'          => $tsStr,
            ':mac'         => $mac,
            ':status'      => $data['status'],
            ':tk'          => $data['tk'],
            ':tc'          => $data['tc'],
            ':tm'          => $data['tm'],
            ':ty'          => $data['ty'],
            ':total_pages' => $data['total_pages'],
            ':total_color' => $data['total_color'],
            ':total_bw'    => $data['total_bw'],
        ]);
        $inserted++;
        logLine("✅ Inséré: MAC=$mac, TS=$tsStr (inserted=$inserted)");
    } catch (Throwable $e) {
        logLine("⚠️ Erreur insertion (MAC=$mac, TS=$tsStr) : " . $e->getMessage());
        continue;
    }
}

logLine("🔧 Étape 8 terminée: inserted=$inserted, skipped=$skipped");

if ($inserted > 0 || $skipped > 0) {
    logLine("🎉 Import terminé.");
    logLine("➡️ Nouvelles lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (doublons MAC+Timestamp) : $skipped");
}

// 8) Enregistrement dans import_run pour suivi du dashboard
log_import_run:
logLine("🔧 Étape 9: Enregistrement dans import_run");
try {
    // Créer la table si elle n'existe pas
    logLine("🔧 Création/vérification de la table import_run...");
    $pdo->exec("
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
    logLine("✅ Table import_run vérifiée");
    
    $totalProcessed = $inserted + $skipped;
    logLine("🔧 Préparation du message JSON (processed=$totalProcessed, inserted=$inserted, skipped=$skipped)");
    
    $msgData = [
        'source'       => 'ancien_import',
        'processed'    => $totalProcessed,
        'inserted'     => $inserted,
        'skipped'      => $skipped,
        'url'          => $url,
        'last_timestamp' => $lastTimestamp,
        'max_insert'   => $MAX_INSERT,
    ];
    if ($errorMessage !== null) {
        $msgData['error'] = $errorMessage;
    }
    $msg = json_encode($msgData, JSON_UNESCAPED_UNICODE);
    logLine("✅ Message JSON créé: " . substr($msg, 0, 200) . "...");
    
    logLine("🔧 Insertion dans import_run...");
    $stmtLog = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    
    $stmtLog->execute([
        ':imported' => $inserted,
        ':skipped'  => $skipped,
        ':ok'       => $ok,
        ':msg'      => $msg
    ]);
    
    logLine("✅ Insertion dans import_run réussie (ID: " . $pdo->lastInsertId() . ")");
    
    if ($inserted === 0 && $skipped === 0) {
        logLine("✅ Import URL OK — 0 élément");
    } else {
        logLine("📝 Enregistrement dans import_run réussi.");
    }
    logLine("🎉 FIN DU SCRIPT - Tout s'est bien passé");
} catch (Throwable $e) {
    logLine("❌ ERREUR lors de l'enregistrement dans import_run : " . $e->getMessage());
    logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
    logLine("❌ Trace: " . $e->getTraceAsString());
}
