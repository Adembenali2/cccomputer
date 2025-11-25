<?php
// import_compteurs.php
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

// 2) URL source : ta page IONOS
$sourceUrl = 'https://cccomputer.fr/test_compteur.php';

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
    if (!headers_sent()) {
        http_response_code(500);
    }
    logLine("❌ Impossible de récupérer la page (file_get_contents a échoué).");
    exit(1);
}

// 4) Parsing HTML avec DOM + XPath
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// On suppose : un tableau principal avec les lignes de compteurs
$table = $xpath->query('//table')->item(0);
if (!$table) {
    logLine("❌ Aucun tableau <table> trouvé dans la page.");
    exit;
}

$rows = $xpath->query('.//tbody/tr', $table);
if ($rows->length === 0) {
    // parfois il n'y a pas de <tbody>, du coup on prend directement les <tr>
    $rows = $xpath->query('.//tr', $table);
}

logLine("✅ Nombre de lignes trouvées : " . $rows->length);

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

$inserted = 0;
$skipped  = 0;

// 6) Parcours des lignes du tableau
foreach ($rows as $row) {
    if (!$row instanceof DOMElement) continue;

    $cells = $row->getElementsByTagName('td');
    if ($cells->length < 10) {
        // pas assez de colonnes, on ignore
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
        // ligne vide / bizarre, on saute
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
        logLine("⏭️ Déjà présent, on saute (MAC={$mac}, TS={$timestamp})");
        continue;
    }

    // 6.b Insertion en base
    try {
        $stmtInsert->execute([
            $timestamp,              // Timestamp
            $mac ?: null,           // MacAddress
            $etat ?: null,          // Status
            $tonerK,                // TonerBlack
            $tonerC,                // TonerCyan
            $tonerM,                // TonerMagenta
            $tonerY,                // TonerYellow
            $totalPages ?: null,    // TotalPages
            $totalCouleur ?: null,  // TotalColor
            $totalNB ?: null,       // TotalBW
        ]);
        $inserted++;
    } catch (Throwable $e) {
        logLine("⚠️ Erreur insertion (MAC=$mac, TS=$timestamp) : " . $e->getMessage());
        continue;
    }
}

logLine("🎉 Import terminé.");
logLine("➡️ Lignes insérées : $inserted");
logLine("➡️ Lignes ignorées (déjà présentes MAC+Timestamp) : $skipped");

// 7) Enregistrement dans import_run pour suivi du dashboard
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
    $hasError = ($inserted === 0 && $totalProcessed > 0 && $skipped > 0);
    
    $msg = json_encode([
        'source' => 'ancien_import',
        'processed' => $totalProcessed,
        'inserted' => $inserted,
        'skipped' => $skipped,
        'url' => $sourceUrl
    ], JSON_UNESCAPED_UNICODE);
    
    $stmtLog = $pdo->prepare("
        INSERT INTO import_run (ran_at, imported, skipped, ok, msg)
        VALUES (NOW(), :imported, :skipped, :ok, :msg)
    ");
    
    $stmtLog->execute([
        ':imported' => $inserted,
        ':skipped'  => $skipped,
        ':ok'       => ($hasError ? 0 : 1),
        ':msg'      => $msg
    ]);
    
    logLine("📝 Enregistrement dans import_run réussi.");
} catch (Throwable $e) {
    logLine("⚠️ Erreur lors de l'enregistrement dans import_run : " . $e->getMessage());
}