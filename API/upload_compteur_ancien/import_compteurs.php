<?php
// import_compteurs.php
// Import depuis URL HTML table vers Railway compteur_relevee_ancien
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
$totalRows = 0;
$batchSize = 100; // Maximum rows per run

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

// 2) Créer/assurer la table app_kv pour le cursor
logLine("🔧 Étape 3: Vérification de la table app_kv");
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS app_kv (
            k VARCHAR(64) PRIMARY KEY,
            v TEXT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    logLine("✅ Table app_kv vérifiée");
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de la création de app_kv : " . $e->getMessage());
}

// 3) Récupérer le cursor (dernière position traitée)
logLine("🔧 Étape 4: Récupération du cursor");
$cursorKey = 'ancien_import_cursor';
$cursorValue = null;

try {
    $stmt = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
    $stmt->execute([$cursorKey]);
    $cursorValue = $stmt->fetchColumn();
    if ($cursorValue !== false && $cursorValue !== null) {
        logLine("✅ Cursor trouvé: " . $cursorValue);
    } else {
        logLine("ℹ️ Aucun cursor trouvé, démarrage depuis le début");
    }
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de la récupération du cursor : " . $e->getMessage());
}

// 4) Télécharger et parser le HTML depuis l'URL
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

// 5) Parser le HTML table
logLine("🔧 Étape 6: Parsing du HTML");
$rows = [];

try {
    // Utiliser DOMDocument pour parser le HTML
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Chercher toutes les tables
    $tables = $xpath->query('//table');
    if ($tables->length === 0) {
        throw new Exception("Aucune table trouvée dans le HTML");
    }
    
    logLine("✅ " . $tables->length . " table(s) trouvée(s)");
    
    // Prendre la première table (ou chercher la bonne)
    $table = $tables->item(0);
    $tableRows = $xpath->query('.//tr', $table);
    
    if ($tableRows->length === 0) {
        throw new Exception("Aucune ligne trouvée dans la table");
    }
    
    logLine("✅ " . $tableRows->length . " ligne(s) trouvée(s) dans la table");
    
    // Extraire les en-têtes (première ligne)
    $headers = [];
    $firstRow = $tableRows->item(0);
    $headerCells = $xpath->query('.//th | .//td', $firstRow);
    foreach ($headerCells as $cell) {
        $headers[] = trim($cell->textContent);
    }
    
    logLine("✅ En-têtes détectés: " . implode(', ', array_slice($headers, 0, 10)) . (count($headers) > 10 ? '...' : ''));
    
    // Normaliser les noms de colonnes (case-insensitive, avec mapping)
    $headerMap = [];
    $fieldMapping = [
        'timestamp' => 'Timestamp',
        'date' => 'Timestamp',
        'datetime' => 'Timestamp',
        'ipaddress' => 'IpAddress',
        'ip' => 'IpAddress',
        'nom' => 'Nom',
        'name' => 'Nom',
        'model' => 'Model',
        'modele' => 'Model',
        'serialnumber' => 'SerialNumber',
        'serial' => 'SerialNumber',
        'macaddress' => 'MacAddress',
        'mac' => 'MacAddress',
        'status' => 'Status',
        'etat' => 'Status',
        'tonerblack' => 'TonerBlack',
        'toner_noir' => 'TonerBlack',
        'tonercyan' => 'TonerCyan',
        'toner_cyan' => 'TonerCyan',
        'tonermagenta' => 'TonerMagenta',
        'toner_magenta' => 'TonerMagenta',
        'toneryellow' => 'TonerYellow',
        'toner_jaune' => 'TonerYellow',
        'totalpages' => 'TotalPages',
        'total_pages' => 'TotalPages',
        'faxpages' => 'FaxPages',
        'copiedpages' => 'CopiedPages',
        'printedpages' => 'PrintedPages',
        'bwcopies' => 'BWCopies',
        'colorcopies' => 'ColorCopies',
        'monocopies' => 'MonoCopies',
        'bichromecopies' => 'BichromeCopies',
        'bwprinted' => 'BWPrinted',
        'bichromeprinted' => 'BichromePrinted',
        'monoprinted' => 'MonoPrinted',
        'colorprinted' => 'ColorPrinted',
        'totalcolor' => 'TotalColor',
        'total_couleur' => 'TotalColor',
        'totalbw' => 'TotalBW',
        'total_nb' => 'TotalBW',
        'totalnb' => 'TotalBW',
    ];
    
    foreach ($headers as $idx => $header) {
        $normalized = strtolower(trim($header));
        if (isset($fieldMapping[$normalized])) {
            $headerMap[$idx] = $fieldMapping[$normalized];
        } else {
            // Essayer de trouver une correspondance partielle
            foreach ($fieldMapping as $key => $value) {
                if (strpos($normalized, $key) !== false) {
                    $headerMap[$idx] = $value;
                    break;
                }
            }
        }
    }
    
    logLine("✅ Mapping des colonnes: " . count($headerMap) . " colonnes mappées");
    
    // Parser les lignes de données (skip header)
    for ($i = 1; $i < $tableRows->length; $i++) {
        $row = $tableRows->item($i);
        $cells = $xpath->query('.//td', $row);
        
        if ($cells->length === 0) continue;
        
        $data = [];
        foreach ($headerMap as $colIdx => $fieldName) {
            if ($colIdx < $cells->length) {
                $cellValue = trim($cells->item($colIdx)->textContent);
                $data[$fieldName] = $cellValue !== '' ? $cellValue : null;
            }
        }
        
        // Vérifier que MacAddress et Timestamp sont présents
        if (empty($data['MacAddress']) || empty($data['Timestamp'])) {
            continue; // Skip rows without required fields
        }
        
        $rows[] = $data;
    }
    
    $totalRows = count($rows);
    logLine("✅ " . $totalRows . " ligne(s) de données parsées");
    
    if ($totalRows === 0) {
        logLine("ℹ️ Aucune ligne de données valide à importer.");
        goto log_import_run;
    }
    
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du parsing HTML : " . $e->getMessage();
    logLine("❌ ERREUR: $errorMessage");
    logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
    logLine("❌ Trace: " . $e->getTraceAsString());
    $ok = 0;
    goto log_import_run;
}

// 6) Appliquer le cursor (skip les lignes déjà traitées)
$cursorIndex = 0;
if ($cursorValue !== null && $cursorValue !== false) {
    $cursorIndex = (int)$cursorValue;
    if ($cursorIndex > 0) {
        if ($cursorIndex >= $totalRows) {
            // Cursor au-delà de la taille actuelle, réinitialiser
            logLine("ℹ️ Cursor ($cursorIndex) au-delà de la taille actuelle ($totalRows), réinitialisation");
            $cursorIndex = 0;
        } else {
            logLine("🔧 Application du cursor: skip des " . $cursorIndex . " premières lignes");
            $rows = array_slice($rows, $cursorIndex);
            logLine("✅ " . count($rows) . " ligne(s) restantes après application du cursor");
        }
    }
}

// Limiter à batchSize lignes
$rowsToProcess = count($rows);
if ($rowsToProcess > $batchSize) {
    logLine("🔧 Limitation à $batchSize lignes (batch processing)");
    $rows = array_slice($rows, 0, $batchSize);
}

// 7) Préparation des requêtes pour Railway
logLine("🔧 Étape 7: Préparation des requêtes SQL pour Railway");

// 7.a) Requête pour vérifier les doublons
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

// 7.b) Requête INSERT
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
      :ts,          -- Timestamp
      :ip,          -- IpAddress
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
      :fax_pages,   -- FaxPages
      :copied_pages, -- CopiedPages
      :printed_pages, -- PrintedPages
      :bw_copies,   -- BWCopies
      :color_copies, -- ColorCopies
      :mono_copies, -- MonoCopies
      :bichrome_copies, -- BichromeCopies
      :bw_printed,  -- BWPrinted
      :bichrome_printed, -- BichromePrinted
      :mono_printed, -- MonoPrinted
      :color_printed, -- ColorPrinted
      :total_color, -- TotalColor
      :total_bw,    -- TotalBW
      NOW()         -- DateInsertion
    )
";
$stmtInsert = $pdo->prepare($sqlInsert);
logLine("✅ Requête INSERT préparée");

// 8) Helper pour convertir les valeurs
function parseValue($value, $type = 'string') {
    if ($value === null || $value === '') {
        return null;
    }
    
    switch ($type) {
        case 'int':
            $v = filter_var($value, FILTER_VALIDATE_INT);
            return $v !== false ? $v : null;
        case 'datetime':
            if (is_numeric($value)) {
                return date('Y-m-d H:i:s', (int)$value);
            }
            $ts = strtotime($value);
            return $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
        default:
            return trim((string)$value);
    }
}

// 9) Traitement de chaque relevé
logLine("🔧 Étape 8: Traitement de " . count($rows) . " relevés...");

$currentIndex = $cursorIndex; // Start from cursor position

foreach ($rows as $idx => $r) {
    $mac = parseValue($r['MacAddress'] ?? null);
    $timestamp = parseValue($r['Timestamp'] ?? null, 'datetime');
    
    if (empty($mac) || empty($timestamp)) {
        $skipped++;
        logLine("⚠️ Ligne ignorée (MAC ou Timestamp vide)");
        $currentIndex++;
        continue;
    }
    
    logLine("🔧 Traitement: MAC=$mac, TS=$timestamp");
    
    // Vérifier les doublons
    try {
        $stmtCheck->execute([
            ':mac' => $mac,
            ':ts'  => $timestamp,
        ]);
        
        $existing = $stmtCheck->fetch();
        if ($existing) {
            $skipped++;
            logLine("⏭️ Déjà présent, ignoré");
            $currentIndex++;
            continue;
        }
    } catch (Throwable $e) {
        logLine("❌ ERREUR vérification doublon (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        $currentIndex++;
        continue;
    }
    
    // Insertion
    try {
        $stmtInsert->execute([
            ':ts'              => $timestamp,
            ':ip'              => parseValue($r['IpAddress'] ?? null),
            ':nom'             => parseValue($r['Nom'] ?? null),
            ':model'           => parseValue($r['Model'] ?? null),
            ':serial'          => parseValue($r['SerialNumber'] ?? null),
            ':mac'             => $mac,
            ':status'          => parseValue($r['Status'] ?? null),
            ':toner_k'         => parseValue($r['TonerBlack'] ?? null, 'int'),
            ':toner_c'         => parseValue($r['TonerCyan'] ?? null, 'int'),
            ':toner_m'         => parseValue($r['TonerMagenta'] ?? null, 'int'),
            ':toner_y'         => parseValue($r['TonerYellow'] ?? null, 'int'),
            ':total_pages'     => parseValue($r['TotalPages'] ?? null, 'int'),
            ':fax_pages'       => parseValue($r['FaxPages'] ?? null, 'int'),
            ':copied_pages'    => parseValue($r['CopiedPages'] ?? null, 'int'),
            ':printed_pages'   => parseValue($r['PrintedPages'] ?? null, 'int'),
            ':bw_copies'       => parseValue($r['BWCopies'] ?? null, 'int'),
            ':color_copies'    => parseValue($r['ColorCopies'] ?? null, 'int'),
            ':mono_copies'     => parseValue($r['MonoCopies'] ?? null, 'int'),
            ':bichrome_copies' => parseValue($r['BichromeCopies'] ?? null, 'int'),
            ':bw_printed'      => parseValue($r['BWPrinted'] ?? null, 'int'),
            ':bichrome_printed' => parseValue($r['BichromePrinted'] ?? null, 'int'),
            ':mono_printed'     => parseValue($r['MonoPrinted'] ?? null, 'int'),
            ':color_printed'   => parseValue($r['ColorPrinted'] ?? null, 'int'),
            ':total_color'     => parseValue($r['TotalColor'] ?? null, 'int'),
            ':total_bw'        => parseValue($r['TotalBW'] ?? null, 'int'),
        ]);
        $inserted++;
        $currentIndex++;
        logLine("✅ Inséré avec succès (inserted=$inserted)");
    } catch (Throwable $e) {
        logLine("❌ ERREUR insertion (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
        $currentIndex++;
        continue;
    }
}

logLine("🔧 Étape 8 terminée: inserted=$inserted, skipped=$skipped");

// 10) Mettre à jour le cursor
logLine("🔧 Étape 9: Mise à jour du cursor");
try {
    // Si on a traité toutes les lignes disponibles, réinitialiser le cursor
    if ($currentIndex >= $totalRows) {
        logLine("ℹ️ Toutes les lignes ont été traitées, réinitialisation du cursor");
        $currentIndex = 0;
    }
    
    $stmtCursor = $pdo->prepare("REPLACE INTO app_kv (k, v) VALUES (?, ?)");
    $stmtCursor->execute([$cursorKey, (string)$currentIndex]);
    logLine("✅ Cursor mis à jour: $currentIndex / $totalRows");
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de la mise à jour du cursor : " . $e->getMessage());
}

if ($inserted > 0 || $skipped > 0) {
    logLine("🎉 Import terminé.");
    logLine("➡️ Lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (déjà présentes MAC+Timestamp) : $skipped");
    logLine("➡️ Cursor position : $currentIndex / $totalRows");
}

// 11) Enregistrement dans import_run pour suivi du dashboard
log_import_run:
logLine("🔧 Étape 10: Enregistrement dans import_run");
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
        'cursor_index' => $currentIndex,
        'total_rows'   => $totalRows,
        'remaining'    => max(0, $totalRows - $currentIndex),
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

