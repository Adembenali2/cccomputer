<?php
// import_compteurs_ancien.php
declare(strict_types=1);

// 🔗 Chemin vers ton db.php (adapter si besoin)
require_once __DIR__ . '/includes/db.php';

// Récupération du PDO Railways
if (!isset($GLOBALS['pdo']) || !$GLOBALS['pdo'] instanceof PDO) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo "Erreur : PDO non initialisé (vérifier includes/db.php)\n";
    exit(1);
}

$pdo = $GLOBALS['pdo'];

// -----------------------------------------------------------------------------
// Helpers d'affichage (CL I vs HTTP)
// -----------------------------------------------------------------------------
function logLine(string $msg): void {
    if (PHP_SAPI === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

// -----------------------------------------------------------------------------
// 1) Récupération de la page HTML
// -----------------------------------------------------------------------------
$url = 'https://cccomputer.fr/test_compteur.php';
logLine("🔁 Récupération de la page : $url");

$html = @file_get_contents($url);
if ($html === false) {
    logLine("❌ Impossible de récupérer la page (file_get_contents a échoué)");
    exit(1);
}

// -----------------------------------------------------------------------------
// 2) Parsing HTML avec DOM + XPath
// -----------------------------------------------------------------------------
libxml_use_internal_errors(true);
$dom = new DOMDocument();
$dom->loadHTML($html);
libxml_clear_errors();

$xpath = new DOMXPath($dom);

// On prend le premier tableau
$table = $xpath->query('//table')->item(0);
if (!$table) {
    logLine("❌ Aucun tableau <table> trouvé dans la page.");
    exit(1);
}

// On essaie d'abord tbody/tr, sinon directement tr
$rows = $xpath->query('.//tbody/tr', $table);
if ($rows->length === 0) {
    $rows = $xpath->query('.//tr', $table);
}

logLine("✅ Nombre de lignes trouvées : " . $rows->length);

// -----------------------------------------------------------------------------
// 3) Helpers pour lire les cellules
// -----------------------------------------------------------------------------
/**
 * Retourne le texte nettoyé d'une cellule <td>
 */
function getCellText(?DOMNode $td): string {
    if (!$td) {
        return '';
    }
    return trim($td->textContent ?? '');
}

/**
 * Extrait une valeur de toner (int) depuis la cellule :
 *  - cherche <div class="toner">80%</div> si présent
 *  - sinon, cherche un nombre dans tout le texte
 *  - retourne null si rien
 */
function extractTonerValue(DOMXPath $xpath, DOMNode $td): ?int {
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
}

// -----------------------------------------------------------------------------
// 4) Préparation des requêtes SQL (Railway: compteur_relevee_ancien)
// -----------------------------------------------------------------------------

// Vérifier si la table existe (optionnel, mais pratique pour le debug)
try {
    $pdo->query("SELECT 1 FROM compteur_relevee_ancien LIMIT 1");
} catch (Throwable $e) {
    logLine("❌ La table compteur_relevee_ancien n'existe pas ou est inaccessible : " . $e->getMessage());
    exit(1);
}

// Requête pour vérifier si la ligne existe (unicité MAC + Timestamp via mac_norm)
$sqlCheck = "
    SELECT id
    FROM compteur_relevee_ancien
    WHERE mac_norm = REPLACE(UPPER(:mac), ':', '')
      AND Timestamp <=> :ts
    LIMIT 1
";
$stmtCheck = $pdo->prepare($sqlCheck);

// Requête INSERT
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

// -----------------------------------------------------------------------------
// 5) Parcours des lignes et insertion
// -----------------------------------------------------------------------------
$inserted = 0;
$skipped  = 0;
$errors   = 0;

// On parcourt toutes les lignes <tr>
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

    // Colonnes attendues :
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

    $refClient = getCellText($cells->item(0)); // pour info seulement
    $mac       = getCellText($cells->item(1));
    $tsStr     = getCellText($cells->item(2));
    $totalNB   = getCellText($cells->item(3));
    $totalClr  = getCellText($cells->item(4));
    $status    = getCellText($cells->item(5));

    if ($mac === '' || $tsStr === '') {
        // Ligne incomplète, on ignore
        continue;
    }

    $totalBW    = is_numeric($totalNB)  ? (int)$totalNB  : 0;
    $totalColor = is_numeric($totalClr) ? (int)$totalClr : 0;
    $totalPages = $totalBW + $totalColor;

    $tk = extractTonerValue($xpath, $cells->item(6));
    $tc = extractTonerValue($xpath, $cells->item(7));
    $tm = extractTonerValue($xpath, $cells->item(8));
    $ty = extractTonerValue($xpath, $cells->item(9));

    // Vérifier si déjà présent (MAC + Timestamp)
    try {
        $stmtCheck->execute([
            ':mac' => $mac,
            ':ts'  => $tsStr,
        ]);
        $existing = $stmtCheck->fetch();
    } catch (Throwable $e) {
        logLine("⚠️ Erreur lors du SELECT (MAC=$mac, TS=$tsStr) : " . $e->getMessage());
        $errors++;
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
            ':status'      => $status !== '' ? $status : null,
            ':tk'          => $tk,
            ':tc'          => $tc,
            ':tm'          => $tm,
            ':ty'          => $ty,
            ':total_pages' => $totalPages ?: null,
            ':total_color' => $totalColor ?: null,
            ':total_bw'    => $totalBW ?: null,
        ]);
        $inserted++;
    } catch (Throwable $e) {
        logLine("⚠️ Erreur insertion (MAC=$mac, TS=$tsStr) : " . $e->getMessage());
        $errors++;
        continue;
    }
}

// -----------------------------------------------------------------------------
// 6) Résumé
// -----------------------------------------------------------------------------
logLine("🎉 Import terminé.");
logLine("➡️ Lignes insérées : $inserted");
logLine("➡️ Lignes ignorées (doublons MAC+Timestamp) : $skipped");
logLine("➡️ Erreurs : $errors");

if (PHP_SAPI === 'cli') {
    exit(0);
}
