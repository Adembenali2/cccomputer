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
// Helpers d'affichage
// -----------------------------------------------------------------------------
function logLine(string $msg): void {
    if (PHP_SAPI === 'cli') {
        echo $msg . PHP_EOL;
    } else {
        echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "<br>\n";
    }
}

// -----------------------------------------------------------------------------
// Constantes
// -----------------------------------------------------------------------------
$MAX_INSERT = 20; // ✅ Seulement 20 relevés par exécution

// Pour la version web : on détermine si on doit lancer l'import ou juste afficher le bouton
$isCli = (PHP_SAPI === 'cli');
$runImport = false;

if ($isCli) {
    $runImport = true;
} else {
    // On considère qu'on lance l'import seulement en POST avec le bouton "upload"
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['do_upload'])) {
        $runImport = true;
    }
}

// -----------------------------------------------------------------------------
// Fonction principale d'import (retourne un tableau de résultats)
// -----------------------------------------------------------------------------
function run_import(PDO $pdo, int $MAX_INSERT): array
{
    $results = [
        'inserted'      => 0,
        'skipped'       => 0,
        'errors'        => 0,
        'lastTimestamp' => null,
        'rowsInserted'  => [], // pour afficher les relevés insérés
        'candidates'    => 0,
    ];

    // 1) Récupération de la page HTML
    $url = 'https://cccomputer.fr/test_compteur.php';
    logLine("🔁 Récupération de la page : $url");

    $html = @file_get_contents($url);
    if ($html === false) {
        logLine("❌ Impossible de récupérer la page (file_get_contents a échoué)");
        return $results;
    }

    // 2) Parsing HTML avec DOM + XPath
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($dom);

    // On prend le premier tableau
    $table = $xpath->query('//table')->item(0);
    if (!$table) {
        logLine("❌ Aucun tableau <table> trouvé dans la page.");
        return $results;
    }

    // On essaie d'abord tbody/tr, sinon directement tr
    $rows = $xpath->query('.//tbody/tr', $table);
    if ($rows->length === 0) {
        $rows = $xpath->query('.//tr', $table);
    }

    logLine("✅ Nombre de lignes trouvées : " . $rows->length);

    // 3) Helpers pour lire les cellules
    /**
     * Retourne le texte nettoyé d'une cellule <td>
     */
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

    // 4) Vérifier la table + récupérer le dernier Timestamp
    try {
        $pdo->query("SELECT 1 FROM compteur_relevee_ancien LIMIT 1");
    } catch (Throwable $e) {
        logLine("❌ La table compteur_relevee_ancien n'existe pas ou est inaccessible : " . $e->getMessage());
        return $results;
    }

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
    $results['lastTimestamp'] = $lastTimestamp;

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

    // 5) Parcours des lignes HTML -> constitution d'un tableau à insérer
    $rowsData = [];

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

    $results['candidates'] = count($rowsData);
    logLine("ℹ️ Lignes candidates après filtrage sur le dernier Timestamp : " . count($rowsData));

    if (count($rowsData) === 0) {
        logLine("ℹ️ Aucun nouveau compteur à importer.");
        return $results;
    }

    // Tri par Timestamp croissant pour insérer dans l'ordre
    usort($rowsData, static function (array $a, array $b): int {
        return strcmp($a['ts'], $b['ts']);
    });

    // Limitation à MAX_INSERT relevées
    if (count($rowsData) > $MAX_INSERT) {
        $rowsData = array_slice($rowsData, 0, $MAX_INSERT);
        logLine("ℹ️ Limitation à $MAX_INSERT nouvelles relevées pour cette exécution.");
    }

    // 6) Insertion en base (max 20 lignes)
    $inserted = 0;
    $skipped  = 0;
    $errors   = 0;
    $rowsInserted = [];

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
            $rowsInserted[] = $data;
        } catch (Throwable $e) {
            logLine("⚠️ Erreur insertion (MAC=$mac, TS=$tsStr) : " . $e->getMessage());
            $errors++;
            continue;
        }
    }

    $results['inserted']     = $inserted;
    $results['skipped']      = $skipped;
    $results['errors']       = $errors;
    $results['rowsInserted'] = $rowsInserted;

    logLine("🎉 Import terminé.");
    logLine("➡️ Nouvelles lignes insérées : $inserted");
    logLine("➡️ Lignes ignorées (doublons MAC+Timestamp) : $skipped");
    logLine("➡️ Erreurs : $errors");

    return $results;
}

// -----------------------------------------------------------------------------
// Lancement (CLI ou Web)
// -----------------------------------------------------------------------------
if ($isCli) {
    // Mode CLI : on lance directement l'import
    $res = run_import($pdo, $MAX_INSERT);
    exit(0);
}

// -------------------------
// Mode HTTP (page avec bouton)
// -------------------------
if (!headers_sent()) {
    header('Content-Type: text/html; charset=utf-8');
}
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>Import compteur (20 par clic)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .btn-upload {
            display: inline-block;
            padding: 10px 20px;
            background: #007bff;
            color: #fff;
            text-decoration: none;
            border-radius: 4px;
            border: none;
            cursor: pointer;
        }
        .btn-upload:hover {
            background: #0056b3;
        }
        table {
            border-collapse: collapse;
            margin-top: 20px;
            width: 100%;
            max-width: 900px;
        }
        th, td {
            border: 1px solid #ccc;
            padding: 6px 8px;
            text-align: left;
            font-size: 13px;
        }
        th {
            background: #f2f2f2;
        }
        .summary {
            margin-top: 15px;
            font-size: 14px;
        }
        .info {
            margin-bottom: 15px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <h1>Import des compteurs</h1>

    <div class="info">
        Chaque clic sur le bouton ci-dessous va :<br>
        - Importer <strong>au maximum 20 nouveaux compteurs</strong> depuis la page distante.<br>
        - Ne prendre que les relevés avec un <strong>Timestamp &gt; au dernier déjà en base</strong>.<br>
    </div>

    <form method="post">
        <button type="submit" name="do_upload" class="btn-upload">Uploader 20 nouveaux compteurs</button>
    </form>

    <hr>

<?php
if ($runImport) {
    $res = run_import($pdo, $MAX_INSERT);
    ?>
    <div class="summary">
        <p><strong>Résultat de l'import :</strong></p>
        <ul>
            <li>Dernier Timestamp en base avant import : <strong><?php echo htmlspecialchars((string)($res['lastTimestamp'] ?? 'Aucun'), ENT_QUOTES, 'UTF-8'); ?></strong></li>
            <li>Lignes candidates après filtrage : <strong><?php echo (int)$res['candidates']; ?></strong></li>
            <li>Nouvelles lignes insérées : <strong><?php echo (int)$res['inserted']; ?></strong></li>
            <li>Lignes ignorées (doublons MAC+Timestamp) : <strong><?php echo (int)$res['skipped']; ?></strong></li>
            <li>Erreurs : <strong><?php echo (int)$res['errors']; ?></strong></li>
        </ul>
    </div>

    <?php if (!empty($res['rowsInserted'])): ?>
        <h2>Compteurs nouvellement uploadés (<?php echo count($res['rowsInserted']); ?>)</h2>
        <table>
            <thead>
                <tr>
                    <th>MAC</th>
                    <th>Timestamp</th>
                    <th>Status</th>
                    <th>Total BW</th>
                    <th>Total Couleur</th>
                    <th>Total Pages</th>
                    <th>Toner K</th>
                    <th>Toner C</th>
                    <th>Toner M</th>
                    <th>Toner Y</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($res['rowsInserted'] as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars((string)$row['mac'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)$row['ts'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['status'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['total_bw'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['total_color'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['total_pages'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['tk'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['tc'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['tm'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?php echo htmlspecialchars((string)($row['ty'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <p>Aucun nouveau compteur n'a été inséré lors de cette exécution.</p>
    <?php endif; ?>

    <?php
}
?>

</body>
</html>
