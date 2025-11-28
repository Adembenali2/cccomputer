<?php
// import_compteurs.php
// Import depuis le tableau HTML vers compteur_relevee_ancien
declare(strict_types=1);

// 1) Connexion DB Railway via db.php
require_once __DIR__ . '/../../includes/db.php';

// Vérifier qu'on a bien un PDO
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo "Erreur : PDO non initialisé par includes/db.php\n";
    exit(1);
}

$pdo = $GLOBALS['pdo'];

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

// 2) URL source
$sourceUrl = 'https://cccomputer.fr/test_compteur.php';

// 3) Récupération HTML avec timeout
logLine("🔁 Récupération de la page : $sourceUrl");

$context = stream_context_create([
    'http' => [
        'timeout' => 30, // 30 secondes max
        'ignore_errors' => true,
    ]
]);

$html = @file_get_contents($sourceUrl, false, $context);
if ($html === false) {
    $errorMessage = "Impossible de récupérer la page (timeout ou erreur réseau)";
    logLine("❌ $errorMessage");
    $ok = 0;
    goto log_import_run;
}

// 4) Parsing HTML avec DOM + XPath
try {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // Chercher le tableau principal
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        logLine("⚠️ Aucun tableau <table> trouvé dans la page. Rien à importer.");
        $rows = [];
        goto log_import_run;
    }
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du parsing HTML : " . $e->getMessage();
    logLine("❌ $errorMessage");
    $ok = 0;
    $rows = [];
    goto log_import_run;
}

// Récupérer les lignes du tableau
$rows = $xpath->query('.//tbody/tr', $table);
if ($rows->length === 0) {
    // Parfois il n'y a pas de <tbody>, on prend directement les <tr>
    $rows = $xpath->query('.//tr', $table);
}

logLine("✅ Nombre de lignes trouvées : " . $rows->length);

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
// 5.a) Requête pour vérifier les doublons
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdo->prepare($sqlCheck);

// 5.b) Requête INSERT
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

// 6) Parcours des lignes du tableau
$totalRows = 0;
foreach ($rows as $row) {
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
        continue;
    }

    $totalRows++;

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

    // 6.b) Insertion en base
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

// 7) Enregistrement dans import_run pour suivi du dashboard
log_import_run:
try {
    // Créer la table si elle n'existe pas
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
    
    $totalProcessed = $inserted + $skipped;
    
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
    
    if ($inserted === 0 && $skipped === 0) {
        logLine("✅ Import OK — 0 élément");
    } else {
        logLine("📝 Enregistrement dans import_run réussi.");
    }
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de l'enregistrement dans import_run : " . $e->getMessage());
}
