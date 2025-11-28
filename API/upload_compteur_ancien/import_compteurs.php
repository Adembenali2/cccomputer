<?php
// import_compteurs.php
// Import par batch de 100 relevés max depuis le tableau HTML
declare(strict_types=1);

// 1) Connexion DB via ton db.php
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

// 2) Configuration
$sourceUrl = 'https://cccomputer.fr/test_compteur.php';
$BATCH_SIZE = 100; // Maximum de relevés à traiter par exécution

// Initialiser les compteurs dès le début (avant tout traitement)
$inserted = 0;
$skipped = 0;
$ok = 1; // Par défaut OK
$errorMessage = null;
$maxProcessedIndex = -1; // Pour le curseur
$lastCursorIndex = -1; // Pour le curseur
$totalRows = 0; // Pour les stats

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

// --- 3) Récupération HTML ---
logLine("🔁 Récupération de la page : $sourceUrl");

$html = @file_get_contents($sourceUrl);
if ($html === false) {
    $errorMessage = "Impossible de récupérer la page (file_get_contents a échoué)";
    logLine("❌ $errorMessage");
    $ok = 0; // Erreur
    goto log_import_run;
}

// 4) Parsing HTML avec DOM + XPath
try {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // On suppose : un tableau principal avec les lignes de compteurs
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        logLine("⚠️ Aucun tableau <table> trouvé dans la page. Rien à importer.");
        // On continue pour créer quand même une entrée dans import_run
        $rowsToProcess = [];
        goto log_import_run;
    }
} catch (Throwable $e) {
    $errorMessage = "Erreur lors du parsing HTML : " . $e->getMessage();
    logLine("❌ $errorMessage");
    $ok = 0;
    $rowsToProcess = [];
    goto log_import_run;
}

$rows = $xpath->query('.//tbody/tr', $table);
if ($rows->length === 0) {
    // parfois il n'y a pas de <tbody>, du coup on prend directement les <tr>
    $rows = $xpath->query('.//tr', $table);
}

logLine("✅ Nombre de lignes trouvées : " . $rows->length);

// 4.1) Créer la table de curseur si elle n'existe pas
$pdo->exec("
    CREATE TABLE IF NOT EXISTS app_kv (
        k VARCHAR(64) PRIMARY KEY,
        v TEXT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

// 4.2) Récupérer le curseur (dernière position traitée)
// On stocke le dernier index de ligne traitée dans app_kv
$cursorKey = 'ancien_import_cursor';
$stmtCursor = $pdo->prepare("SELECT v FROM app_kv WHERE k = ? LIMIT 1");
$stmtCursor->execute([$cursorKey]);
$lastCursorIndex = $stmtCursor->fetchColumn();
$lastCursorIndex = $lastCursorIndex !== false ? (int)$lastCursorIndex : -1;

logLine("📍 Curseur actuel : ligne " . ($lastCursorIndex + 1));

// 4.3) Convertir toutes les lignes en tableau et filtrer celles déjà traitées
$rowsArray = [];
foreach ($rows as $index => $row) {
    // Ignorer la première ligne si c'est un header (th)
    if ($row instanceof DOMElement) {
        $firstCell = $row->getElementsByTagName('th')->item(0);
        if ($firstCell) {
            continue; // C'est un header, on saute
        }
    }
    $rowsArray[] = ['index' => $index, 'row' => $row];
}

$totalRows = count($rowsArray);
logLine("📊 Total de lignes de données : $totalRows");

// Filtrer les lignes déjà traitées (celles avec index <= lastCursorIndex)
$rowsToProcess = array_filter($rowsArray, function($item) use ($lastCursorIndex) {
    return $item['index'] > $lastCursorIndex;
});

$rowsToProcess = array_values($rowsToProcess); // Réindexer
$remainingCount = count($rowsToProcess);

logLine("📋 Lignes restantes à traiter : $remainingCount");

// Limiter au batch size
if ($remainingCount > $BATCH_SIZE) {
    $rowsToProcess = array_slice($rowsToProcess, 0, $BATCH_SIZE);
    logLine("✅ Limité à {$BATCH_SIZE} relevés pour ce batch (sur {$remainingCount} restants)");
}

// helper pour récupérer texte d'une cellule
function getCellText(DOMNode $td): string {
    return trim($td->textContent ?? '');
}

// helper pour extraire un % depuis <div class="toner">80%</div>
function extractTonerValue(DOMXPath $xpath, DOMNode $td): ?int {
    $tonerDiv = $xpath->query('.//div[contains(@class, "toner")]', $td)->item(0);
    if (!$tonerDiv) {
        // fallback : chercher un nombre dans tout le texte
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

// 5) Préparation de la requête INSERT dans compteur_relevee_ancien
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
      ?,          -- Timestamp
      NULL,       -- IpAddress
      NULL,       -- Nom
      NULL,       -- Model
      NULL,       -- SerialNumber
      ?,          -- MacAddress
      ?,          -- Status
      ?,          -- TonerBlack
      ?,          -- TonerCyan
      ?,          -- TonerMagenta
      ?,          -- TonerYellow
      ?,          -- TotalPages
      NULL,       -- FaxPages
      NULL,       -- CopiedPages
      NULL,       -- PrintedPages
      NULL,       -- BWCopies
      NULL,       -- ColorCopies
      NULL,       -- MonoCopies
      NULL,       -- BichromeCopies
      NULL,       -- BWPrinted
      NULL,       -- BichromePrinted
      NULL,       -- MonoPrinted
      NULL,       -- ColorPrinted
      ?,          -- TotalColor
      ?,          -- TotalBW
      NOW()       -- DateInsertion
    )
";

$stmtInsert = $pdo->prepare($sqlInsert);

// 🔍 Requête pour vérifier si le compteur existe déjà (unicité MAC + Timestamp)
// On utilise la colonne calculée mac_norm : REPLACE(UPPER(MacAddress), ':', '')
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdo->prepare($sqlCheck);

// 6) Parcours des lignes du tableau (batch limité)
if (empty($rowsToProcess)) {
    logLine("ℹ️ Aucune ligne à traiter pour ce batch.");
    goto log_import_run;
}

$maxProcessedIndex = $lastCursorIndex; // Pour mettre à jour le curseur

foreach ($rowsToProcess as $item) {
    $row = $item['row'];
    $rowIndex = $item['index'];
    
    if (!$row instanceof DOMElement) {
        $maxProcessedIndex = max($maxProcessedIndex, $rowIndex);
        continue;
    }

    $cells = $row->getElementsByTagName('td');
    if ($cells->length < 10) {
        // pas assez de colonnes, on ignore mais on met à jour le curseur
        $maxProcessedIndex = max($maxProcessedIndex, $rowIndex);
        continue;
    }

    // On suppose la structure suivante :
    // 0: Ref Client       (non utilisé)
    // 1: MAC
    // 2: Date (Timestamp)
    // 3: Total NB
    // 4: Total Couleur
    // 5: État
    // 6: Toner K
    // 7: Toner C
    // 8: Toner M
    // 9: Toner Y

    $refClient = getCellText($cells->item(0)); // ignoré, juste pour info éventuelle
    $mac   = getCellText($cells->item(1));
    $tsStr = getCellText($cells->item(2));
    $totalNBStr      = getCellText($cells->item(3));
    $totalCouleurStr = getCellText($cells->item(4));
    $etat = getCellText($cells->item(5));

    if ($mac === '' && $tsStr === '') {
        // ligne vide / bizarre, on saute mais on met à jour le curseur
        $maxProcessedIndex = max($maxProcessedIndex, $rowIndex);
        continue;
    }

    $totalNB      = is_numeric($totalNBStr) ? (int)$totalNBStr : 0;
    $totalCouleur = is_numeric($totalCouleurStr) ? (int)$totalCouleurStr : 0;
    $totalPages   = $totalNB + $totalCouleur;

    $tonerK = extractTonerValue($xpath, $cells->item(6));
    $tonerC = extractTonerValue($xpath, $cells->item(7));
    $tonerM = extractTonerValue($xpath, $cells->item(8));
    $tonerY = extractTonerValue($xpath, $cells->item(9));

    // Timestamp tel quel (MySQL acceptera un DATETIME ou NULL)
    $timestamp = $tsStr !== '' ? $tsStr : null;

    // 🔍 6.a Vérifier si ce compteur existe déjà (MAC normalisée + Timestamp)
    $stmtCheck->execute([
        ':mac' => $mac,
        ':ts'  => $timestamp,
    ]);

    $existing = $stmtCheck->fetch();
    if ($existing) {
        $skipped++;
        // Même si déjà présent, on met à jour le curseur pour ne pas le retraiter
        $maxProcessedIndex = max($maxProcessedIndex, $rowIndex);
        continue;
    }

    // 6.b Insertion en base
    try {
        $stmtInsert->execute([
            $timestamp,              // 1. Timestamp
            $mac ?: null,            // 2. MacAddress
            $etat ?: null,           // 3. Status
            $tonerK,                 // 4. TonerBlack
            $tonerC,                 // 5. TonerCyan
            $tonerM,                 // 6. TonerMagenta
            $tonerY,                 // 7. TonerYellow
            $totalPages ?: null,     // 8. TotalPages
            $totalCouleur ?: null,   // 9. TotalColor
            $totalNB ?: null,        // 10. TotalBW
        ]);
        $inserted++;
        $maxProcessedIndex = max($maxProcessedIndex, $rowIndex);
    } catch (Throwable $e) {
        logLine("⚠️ Erreur insertion (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        // En cas d'erreur, on ne met pas à jour le curseur pour réessayer plus tard
        // On continue, mais on note qu'il y a eu une erreur
        // On ne met pas $ok = 0 ici car d'autres insertions peuvent réussir
        continue;
    }
}

// 6.c Mettre à jour le curseur si on a traité des lignes
if ($maxProcessedIndex > $lastCursorIndex) {
    try {
        $stmtUpdateCursor = $pdo->prepare("REPLACE INTO app_kv (k, v) VALUES (?, ?)");
        $stmtUpdateCursor->execute([$cursorKey, (string)$maxProcessedIndex]);
        logLine("✅ Curseur mis à jour : ligne " . ($maxProcessedIndex + 1));
    } catch (Throwable $e) {
        logLine("⚠️ Erreur lors de la mise à jour du curseur : " . $e->getMessage());
    }
}

if ($inserted > 0 || $skipped > 0) {
    logLine("🎉 Import terminé.");
    logLine("➡️ Lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (déjà présentes MAC+Timestamp) : $skipped");
}

// 7) Enregistrement dans import_run pour suivi du dashboard (toujours exécuté)
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
    // ok=1 si pas d'erreur (même s'il n'y a rien à importer, c'est OK)
    // ok=0 seulement en cas d'erreur réelle (tentative d'insertion qui a échoué, ou erreur de récupération)
    
    // S'assurer que les variables de curseur sont définies
    if (!isset($maxProcessedIndex)) {
        $maxProcessedIndex = -1;
    }
    if (!isset($lastCursorIndex)) {
        $lastCursorIndex = -1;
    }
    if (!isset($totalRows)) {
        $totalRows = 0;
    }
    
    $currentCursor = $maxProcessedIndex > $lastCursorIndex ? $maxProcessedIndex : $lastCursorIndex;
    $msgData = [
        'source' => 'ancien_import',
        'processed' => $totalProcessed,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'url' => $sourceUrl,
        'cursor_index' => $currentCursor,
        'remaining' => max(0, $totalRows - $currentCursor - 1)
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
        logLine("✅ Import IONOS OK — 0 élément");
    } else {
        logLine("📝 Enregistrement dans import_run réussi.");
    }
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de l'enregistrement dans import_run : " . $e->getMessage());
}