<?php
// import_compteurs.php
// Import depuis le tableau HTML vers compteur_relevee_ancien
declare(strict_types=1);

// 1) Connexion DB Railway via db.php
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

$pdo = $GLOBALS['pdo'];
logLine("✅ PDO initialisé avec succès");

// Initialiser les compteurs
$inserted = 0;
$skipped = 0;
$ok = 1; // Par défaut OK
$errorMessage = null;
$totalRows = 0;

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

// 2) URL source
$sourceUrl = 'https://cccomputer.fr/test_compteur.php';

// 3) Récupération HTML avec timeout
logLine("🔧 Étape 3: Récupération de la page : $sourceUrl");

$context = stream_context_create([
    'http' => [
        'timeout' => 30, // 30 secondes max
        'ignore_errors' => true,
        'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ]
]);

logLine("🔧 Tentative de file_get_contents...");
$html = @file_get_contents($sourceUrl, false, $context);
if ($html === false) {
    $lastError = error_get_last();
    $errorMessage = "Impossible de récupérer la page (timeout ou erreur réseau)";
    logLine("❌ ERREUR: $errorMessage");
    if ($lastError) {
        logLine("❌ Détails erreur: " . $lastError['message']);
    }
    $ok = 0;
    goto log_import_run;
}

$htmlLength = strlen($html);
logLine("✅ HTML récupéré avec succès ($htmlLength octets)");

// 4) Parsing HTML avec DOM + XPath
logLine("🔧 Étape 4: Parsing HTML");
try {
    libxml_use_internal_errors(true);
    logLine("🔧 Création du DOMDocument...");
    $dom = new DOMDocument();
    logLine("🔧 Chargement du HTML dans le DOM...");
    $dom->loadHTML($html);
    $libxmlErrors = libxml_get_errors();
    if (!empty($libxmlErrors)) {
        logLine("⚠️ Avertissements libxml: " . count($libxmlErrors) . " erreurs (non bloquantes)");
    }
    libxml_clear_errors();
    logLine("✅ DOMDocument créé avec succès");

    logLine("🔧 Création du XPath...");
    $xpath = new DOMXPath($dom);
    logLine("✅ XPath créé");

    // Chercher le tableau principal
    logLine("🔧 Recherche du tableau <table>...");
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        logLine("❌ ERREUR: Aucun tableau <table> trouvé dans la page. Rien à importer.");
        logLine("🔧 Debug: Vérification du contenu HTML (premiers 500 caractères)...");
        logLine("🔧 HTML preview: " . substr($html, 0, 500));
        $rows = [];
        goto log_import_run;
    }
    logLine("✅ Tableau trouvé");
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du parsing HTML : " . $e->getMessage();
    logLine("❌ ERREUR: $errorMessage");
    logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
    logLine("❌ Trace: " . $e->getTraceAsString());
    $ok = 0;
    $rows = [];
    goto log_import_run;
}

// Récupérer les lignes du tableau
logLine("🔧 Étape 5: Extraction des lignes du tableau");
logLine("🔧 Recherche dans tbody/tr...");
$rows = $xpath->query('.//tbody/tr', $table);
if ($rows->length === 0) {
    logLine("⚠️ Aucune ligne dans tbody, recherche directe dans tr...");
    // Parfois il n'y a pas de <tbody>, on prend directement les <tr>
    $rows = $xpath->query('.//tr', $table);
}

logLine("✅ Nombre de lignes trouvées : " . $rows->length);
if ($rows->length === 0) {
    logLine("⚠️ ATTENTION: Aucune ligne trouvée dans le tableau");
}

// Helper pour récupérer le texte d'une cellule
function getCellText(DOMNode $td): string {
    return trim($td->textContent ?? '');
}

// Helper pour extraire un % depuis une cellule (peut contenir <div class="toner">80%</div>)
function extractTonerValue(DOMXPath $xpath, DOMNode $td): ?int {
    $tonerDiv = $xpath->query('.//div[contains(@class, "toner")]', $td)->item(0);
    if (!$tonerDiv) {
        // Fallback : chercher un nombre dans tout le texte
        $txt = trim($td->textContent ?? '');
    } else {
        $txt = trim($tonerDiv->textContent ?? '');
    }
    if ($txt === '') return null;

    if (preg_match('/-?\d+/', $txt, $m)) {
        return (int)$m[0];
    }
    return null;
}

// 5) Préparation des requêtes pour Railway
logLine("🔧 Étape 6: Préparation des requêtes SQL");
logLine("🔧 Préparation de la requête de vérification des doublons...");
// 5.a) Requête pour vérifier les doublons
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdo->prepare($sqlCheck);
logLine("✅ Requête de vérification préparée");

// 5.b) Requête INSERT
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
$stmtInsert = $pdo->prepare($sqlInsert);
logLine("✅ Requête INSERT préparée");

// 6) Parcours des lignes du tableau
logLine("🔧 Étape 7: Traitement des lignes du tableau");
$totalRows = 0;
$rowIndex = 0;
foreach ($rows as $row) {
    $rowIndex++;
    if ($rowIndex % 10 === 0) {
        logLine("🔧 Traitement ligne $rowIndex/$rows->length...");
    }
    if (!$row instanceof DOMElement) continue;

    $cells = $row->getElementsByTagName('td');
    
    // Ignorer les lignes header (th) ou lignes avec moins de colonnes
    if ($cells->length < 10) {
        // Vérifier si c'est un header
        $thCells = $row->getElementsByTagName('th');
        if ($thCells->length > 0) {
            continue; // C'est un header, on saute
        }
        continue; // Pas assez de colonnes
    }

    // Structure supposée du tableau HTML :
    // 0: Ref Client       (peut être utilisé pour Nom)
    // 1: MAC
    // 2: Date (Timestamp)
    // 3: Total NB
    // 4: Total Couleur
    // 5: État
    // 6: Toner K
    // 7: Toner C
    // 8: Toner M
    // 9: Toner Y

    $refClient = getCellText($cells->item(0));
    $mac = getCellText($cells->item(1));
    $tsStr = getCellText($cells->item(2));
    $totalNBStr = getCellText($cells->item(3));
    $totalCouleurStr = getCellText($cells->item(4));
    $etat = getCellText($cells->item(5));

    if ($mac === '' && $tsStr === '') {
        // Ligne vide, on saute
        logLine("⚠️ Ligne $rowIndex ignorée (vide)");
        continue;
    }

    $totalRows++;
    logLine("🔧 Traitement ligne $rowIndex: MAC=$mac, TS=$tsStr");

    $totalNB = is_numeric($totalNBStr) ? (int)$totalNBStr : 0;
    $totalCouleur = is_numeric($totalCouleurStr) ? (int)$totalCouleurStr : 0;
    $totalPages = $totalNB + $totalCouleur;

    $tonerK = extractTonerValue($xpath, $cells->item(6));
    $tonerC = extractTonerValue($xpath, $cells->item(7));
    $tonerM = extractTonerValue($xpath, $cells->item(8));
    $tonerY = extractTonerValue($xpath, $cells->item(9));

    // Timestamp
    $timestamp = $tsStr !== '' ? $tsStr : null;

    // 6.a) Vérifier si ce compteur existe déjà (MAC normalisée + Timestamp)
    logLine("🔧 Vérification doublon pour MAC=$mac, TS=$timestamp");
    try {
        $stmtCheck->execute([
            ':mac' => $mac,
            ':ts'  => $timestamp,
        ]);

        $existing = $stmtCheck->fetch();
        if ($existing) {
            $skipped++;
            logLine("⏭️ Ligne $rowIndex déjà présente, ignorée");
            continue;
        }
        logLine("✅ Pas de doublon trouvé");
    } catch (Throwable $e) {
        logLine("❌ ERREUR vérification doublon (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        logLine("❌ Trace: " . $e->getTraceAsString());
        continue;
    }

    // 6.b) Insertion en base
    logLine("🔧 Insertion en base pour ligne $rowIndex...");
    try {
        $stmtInsert->execute([
            ':ts'          => $timestamp,
            ':nom'         => $refClient ?: null,
            ':model'       => null, // Pas disponible dans le tableau HTML
            ':serial'      => null, // Pas disponible dans le tableau HTML
            ':mac'         => $mac ?: null,
            ':status'      => $etat ?: null,
            ':toner_k'     => $tonerK,
            ':toner_c'     => $tonerC,
            ':toner_m'     => $tonerM,
            ':toner_y'     => $tonerY,
            ':total_pages' => $totalPages > 0 ? $totalPages : null,
            ':total_color' => $totalCouleur > 0 ? $totalCouleur : null,
            ':total_bw'    => $totalNB > 0 ? $totalNB : null,
        ]);
        $inserted++;
        logLine("✅ Ligne $rowIndex insérée avec succès (inserted=$inserted)");
    } catch (Throwable $e) {
        logLine("❌ ERREUR insertion (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
        logLine("❌ Trace: " . $e->getTraceAsString());
        // Continue, mais on note qu'il y a eu une erreur
        continue;
    }
}

logLine("🔧 Étape 7 terminée: totalRows=$totalRows, inserted=$inserted, skipped=$skipped");

if ($inserted > 0 || $skipped > 0) {
    logLine("🎉 Import terminé.");
    logLine("➡️ Lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (déjà présentes MAC+Timestamp) : $skipped");
}

// 7) Enregistrement dans import_run pour suivi du dashboard
log_import_run:
logLine("🔧 Étape 8: Enregistrement dans import_run");
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
        'url'          => $sourceUrl,
        'cursor_index' => 0,
        'remaining'    => 0,
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
        logLine("✅ Import OK — 0 élément");
    } else {
        logLine("📝 Enregistrement dans import_run réussi.");
    }
    logLine("🎉 FIN DU SCRIPT - Tout s'est bien passé");
} catch (Throwable $e) {
    logLine("❌ ERREUR lors de l'enregistrement dans import_run : " . $e->getMessage());
    logLine("❌ Fichier: " . $e->getFile() . " ligne " . $e->getLine());
    logLine("❌ Trace: " . $e->getTraceAsString());
}
