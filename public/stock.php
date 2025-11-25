<?php
/**
 * Page de gestion du stock
 * Affiche les différents types de produits en stock (papier, toners, LCD, PC)
 * 
 * @package CCComputer
 * @version 3.0 - Design moderne Dashboard avec alignement parfait des tableaux
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

// Vérification des permissions
authorize_page('stock', []);

// Configuration PDO pour les erreurs
try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    error_log('Erreur configuration PDO dans stock.php: ' . $e->getMessage());
}

// Génération du token CSRF
ensureCsrfToken();

// ====================================================================
// GESTION DES MESSAGES FLASH
// ====================================================================
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Message de succès depuis paramètre GET (validation stricte)
$allowedTypes = ['papier', 'toner', 'lcd', 'pc'];
if (isset($_GET['added']) && in_array($_GET['added'], $allowedTypes, true)) {
    $typeNames = [
        'papier' => 'papier',
        'toner' => 'toner',
        'lcd' => 'LCD',
        'pc' => 'PC'
    ];
    $typeName = $typeNames[$_GET['added']] ?? 'produit';
    $flash = [
        'type' => 'success',
        'msg' => ucfirst($typeName) . ' ajouté avec succès dans le stock.'
    ];
}

// ====================================================================
// FONCTIONS UTILITAIRES
// ====================================================================

/**
 * Formate une date pour l'affichage
 */
function formatTimestamp(?string $timestamp): ?string
{
    if (empty($timestamp)) {
        return null;
    }
    
    try {
        $dt = new DateTime($timestamp);
        return $dt->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        error_log('Erreur formatage date dans stock.php: ' . $e->getMessage());
        return (string)$timestamp;
    }
}

/**
 * Extrait la marque depuis un modèle
 */
function extractMarque(string $model): string
{
    $model = trim($model);
    if (empty($model)) {
        return '—';
    }
    
    $parts = preg_split('/\s+/', $model);
    return ($parts && $parts[0] !== '') ? $parts[0] : '—';
}

/**
 * Détermine le statut d'un photocopieur
 */
function determineStatut(string $rawStatus): string
{
    $raw = strtoupper(trim($rawStatus));
    if (empty($raw)) {
        return 'stock';
    }
    
    $okValues = ['OK', 'ONLINE', 'NORMAL', 'READY', 'PRINT', 'IDLE', 'STANDBY', 'SLEEP', 'AVAILABLE'];
    return in_array($raw, $okValues, true) ? 'stock' : 'en panne';
}

// ====================================================================
// RÉCUPÉRATION DES PHOTOCOPIEURS NON ATTRIBUÉS
// ====================================================================
$copiers = [];
try {
    $sql = "
        WITH v_compteur_last AS (
            SELECT r.*,
                   ROW_NUMBER() OVER (PARTITION BY r.mac_norm ORDER BY r.`Timestamp` DESC) AS rn
            FROM compteur_relevee r
            WHERE r.mac_norm IS NOT NULL AND r.mac_norm <> ''
        )
        SELECT
            v.mac_norm,
            v.MacAddress,
            v.SerialNumber,
            v.Model,
            v.Nom,
            v.`Timestamp` AS last_ts,
            v.TotalBW,
            v.TotalColor,
            v.Status AS raw_status
        FROM v_compteur_last v
        LEFT JOIN photocopieurs_clients pc ON pc.mac_norm = v.mac_norm
        WHERE v.rn = 1
          AND pc.id_client IS NULL
        ORDER BY
            v.Model IS NULL, v.Model,
            v.SerialNumber IS NULL, v.SerialNumber,
            v.MacAddress
        LIMIT 500
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($rows as $r) {
        $model = trim($r['Model'] ?? '');
        $marque = extractMarque($model);
        $statut = determineStatut($r['raw_status'] ?? '');
        
        $copiers[] = [
            'id' => $r['mac_norm'] ?? '',
            'mac' => $r['MacAddress'] ?: '',
            'marque' => $marque,
            'modele' => $model ?: ($r['Nom'] ?: '—'),
            'sn' => $r['SerialNumber'] ?: '—',
            'compteur_bw' => is_numeric($r['TotalBW']) ? (int)$r['TotalBW'] : null,
            'compteur_color' => is_numeric($r['TotalColor']) ? (int)$r['TotalColor'] : null,
            'statut' => $statut,
            'emplacement' => 'dépôt',
            'last_ts' => formatTimestamp($r['last_ts'] ?? null),
        ];
    }
} catch (PDOException $e) {
    error_log('stock.php (photocopieurs non attribués) SQL error: ' . $e->getMessage());
    $copiers = [];
}

// ====================================================================
// RÉCUPÉRATION DU PAPIER
// ====================================================================
$papers = safeFetchAll(
    $pdo,
    "SELECT paper_id, marque, modele, poids, qty_stock 
     FROM v_paper_stock 
     ORDER BY marque, modele, poids",
    [],
    'stock_papier'
);

// ====================================================================
// RÉCUPÉRATION DES TONERS
// ====================================================================
$tonersRaw = safeFetchAll(
    $pdo,
    "SELECT toner_id, marque, modele, couleur, qty_stock 
     FROM v_toner_stock 
     ORDER BY marque, modele, couleur",
    [],
    'stock_toner'
);

$toners = [];
foreach ($tonersRaw as $r) {
    $toners[] = [
        'id' => (int)($r['toner_id'] ?? 0),
        'marque' => $r['marque'] ?? '',
        'modele' => $r['modele'] ?? '',
        'couleur' => $r['couleur'] ?? '',
        'qty' => (int)($r['qty_stock'] ?? 0),
    ];
}

// ====================================================================
// RÉCUPÉRATION DES LCD
// ====================================================================
$lcdRaw = safeFetchAll(
    $pdo,
    "SELECT lcd_id, marque, reference, etat, modele, taille, resolution, connectique, prix, qty_stock 
     FROM v_lcd_stock 
     ORDER BY marque, modele, taille",
    [],
    'stock_lcd'
);

$lcd = [];
foreach ($lcdRaw as $r) {
    $lcd[] = [
        'id' => (int)($r['lcd_id'] ?? 0),
        'marque' => $r['marque'] ?? '',
        'reference' => $r['reference'] ?? '',
        'etat' => $r['etat'] ?? '',
        'modele' => $r['modele'] ?? '',
        'taille' => (int)($r['taille'] ?? 0),
        'resolution' => $r['resolution'] ?? '',
        'connectique' => $r['connectique'] ?? '',
        'prix' => isset($r['prix']) && $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty' => (int)($r['qty_stock'] ?? 0),
    ];
}

// ====================================================================
// RÉCUPÉRATION DES PC
// ====================================================================
$pcRaw = safeFetchAll(
    $pdo,
    "SELECT pc_id, etat, reference, marque, modele, cpu, ram, stockage, os, gpu, reseau, ports, prix, qty_stock 
     FROM v_pc_stock 
     ORDER BY marque, modele, reference",
    [],
    'stock_pc'
);

$pc = [];
foreach ($pcRaw as $r) {
    $pc[] = [
        'id' => (int)($r['pc_id'] ?? 0),
        'etat' => $r['etat'] ?? '',
        'reference' => $r['reference'] ?? '',
        'marque' => $r['marque'] ?? '',
        'modele' => $r['modele'] ?? '',
        'cpu' => $r['cpu'] ?? '',
        'ram' => $r['ram'] ?? '',
        'stockage' => $r['stockage'] ?? '',
        'os' => $r['os'] ?? '',
        'gpu' => $r['gpu'] ?? '',
        'reseau' => $r['reseau'] ?? '',
        'ports' => $r['ports'] ?? '',
        'prix' => isset($r['prix']) && $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty' => (int)($r['qty_stock'] ?? 0),
    ];
}

// ====================================================================
// CALCUL DES STATISTIQUES
// ====================================================================
$totalPapier = array_sum(array_map(function ($p) {
    return (int)($p['qty_stock'] ?? 0);
}, $papers));

$totalToners = array_sum(array_map(function ($t) {
    return (int)($t['qty'] ?? 0);
}, $toners));

$totalLCD = array_sum(array_map(function ($l) {
    return (int)($l['qty'] ?? 0);
}, $lcd));

$totalPC = array_sum(array_map(function ($p) {
    return (int)($p['qty'] ?? 0);
}, $pc));

// Détection des stocks faibles
$stockFaible = [
    'papier' => array_filter($papers, function ($p) {
        return (int)($p['qty_stock'] ?? 0) <= 5;
    }),
    'toners' => array_filter($toners, function ($t) {
        return (int)($t['qty'] ?? 0) <= 3;
    }),
    'lcd' => array_filter($lcd, function ($l) {
        return (int)($l['qty'] ?? 0) <= 2;
    }),
    'pc' => array_filter($pc, function ($p) {
        return (int)($p['qty'] ?? 0) <= 2;
    }),
];

$nbStockFaible = count($stockFaible['papier']) 
    + count($stockFaible['toners']) 
    + count($stockFaible['lcd']) 
    + count($stockFaible['pc']);

// Normalisation des données papier pour le dataset
$papersNormalized = [];
foreach ($papers as $p) {
    $paperId = $p['paper_id'] ?? null;
    if (empty($paperId)) {
        continue;
    }
    
    $papersNormalized[] = [
        'id' => (int)$paperId,
        'paper_id' => (int)$paperId,
        'marque' => $p['marque'] ?? '',
        'modele' => $p['modele'] ?? '',
        'poids' => $p['poids'] ?? '',
        'qty' => (int)($p['qty_stock'] ?? 0),
        'qty_stock' => (int)($p['qty_stock'] ?? 0),
    ];
}

// Préparation des datasets pour JavaScript
$datasets = [
    'copiers' => $copiers,
    'lcd' => $lcd,
    'pc' => $pc,
    'toners' => $toners,
    'papier' => $papersNormalized
];

$sectionImages = [
    'photocopieurs' => '/assets/img/stock/photocopieurs.jpg',
    'lcd' => '/assets/img/stock/lcd.jpg',
    'pc' => '/assets/img/stock/pc.jpg',
    'toners' => '/assets/img/stock/toners.jpg',
    'papier' => '/assets/img/stock/papier.jpg',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="<?= h($_SESSION['csrf_token'] ?? '') ?>">
    <title>Stock - CCComputer</title>

    <link rel="stylesheet" href="/assets/css/main.css" />
    <link rel="stylesheet" href="/assets/css/stock.css" />
    <style>
        /* Styles spécifiques pour le scanner de caméra */
        #reader {
            position: relative;
        }
        
        #reader video,
        #reader canvas {
            width: 100% !important;
            max-width: 100% !important;
            height: auto !important;
            display: block !important;
            border-radius: var(--radius-md);
        }
        
        #reader #qr-shaded-region {
            border: 2px solid var(--accent-primary) !important;
            border-radius: 8px;
        }
        
        /* Forcer l'affichage de la vidéo */
        #reader video[style*="display: none"] {
            display: block !important;
        }
        
        /* Style pour le conteneur de scan */
        #cameraScanArea {
            background: var(--bg-secondary);
            border-radius: var(--radius-md);
            padding: 1rem;
        }
    </style>
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
    <!-- En-tête de page -->
    <div class="page-header">
        <h1 class="page-title">Gestion du Stock</h1>
        <p class="page-subtitle">Vue d'ensemble complète de votre inventaire — disposition <strong>dynamique</strong> selon le contenu</p>
    </div>

    <!-- Messages flash -->
    <?php if ($flash && isset($flash['type'])): ?>
        <div class="flash <?= h($flash['type']) ?>" role="alert">
            <?= h($flash['msg'] ?? '') ?>
        </div>
    <?php endif; ?>

    <!-- Statistiques globales -->
    <section class="stock-meta" aria-label="Statistiques du stock">
        <div class="meta-card" data-type="papier">
            <div class="meta-card-icon">📄</div>
            <span class="meta-card-label">Total Papier</span>
            <strong class="meta-card-value"><?= h((string)$totalPapier) ?></strong>
        </div>
        <div class="meta-card" data-type="toners">
            <div class="meta-card-icon">🖨️</div>
            <span class="meta-card-label">Total Toners</span>
            <strong class="meta-card-value"><?= h((string)$totalToners) ?></strong>
        </div>
        <div class="meta-card" data-type="lcd">
            <div class="meta-card-icon">🖥️</div>
            <span class="meta-card-label">Total LCD</span>
            <strong class="meta-card-value"><?= h((string)$totalLCD) ?></strong>
        </div>
        <div class="meta-card" data-type="pc">
            <div class="meta-card-icon">💻</div>
            <span class="meta-card-label">Total PC</span>
            <strong class="meta-card-value"><?= h((string)$totalPC) ?></strong>
        </div>
        <?php if ($nbStockFaible > 0): ?>
            <div class="meta-card meta-warning" data-type="warning">
                <div class="meta-card-icon">⚠️</div>
                <span class="meta-card-label">Stock faible</span>
                <strong class="meta-card-value"><?= h((string)$nbStockFaible) ?></strong>
            </div>
        <?php endif; ?>
    </section>

    <!-- Barre de recherche -->
    <div class="filters-row">
        <div class="search-wrapper">
            <input 
                type="text" 
                id="q" 
                placeholder="Rechercher dans le stock (référence, modèle, SN, MAC, CPU…)" 
                aria-label="Filtrer le stock"
                autocomplete="off" />
            <button 
                type="button" 
                class="search-clear-btn" 
                id="clearSearch" 
                aria-label="Effacer la recherche" 
                title="Effacer">
                ×
            </button>
        </div>
        <span class="search-results-count" id="searchResultsCount" style="display: none;" aria-live="polite"></span>
    </div>

    <!-- Section Scanner Code-Barres -->
    <section class="barcode-scanner-section" style="margin-bottom: 1.5rem; background: var(--bg-primary); border: 1px solid var(--border-color); border-radius: var(--radius-lg); padding: 1.5rem; box-shadow: var(--shadow-sm);">
        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1rem;">
            <h3 style="margin: 0; font-size: 1.1rem; font-weight: 700; color: var(--text-primary);">
                📷 Scanner Code-Barres
            </h3>
            <button 
                type="button" 
                id="toggleScanner" 
                class="btn btn-primary btn-sm"
                aria-label="Ouvrir/Fermer le scanner">
                Ouvrir Scanner
            </button>
        </div>
        
        <div id="scannerContainer" style="display: none;">
            <div style="display: flex; gap: 1rem; margin-bottom: 1rem; align-items: center;">
                <button 
                    type="button" 
                    id="startCameraScan" 
                    class="btn btn-primary"
                    style="flex: 1;">
                    📹 Démarrer la Caméra
                </button>
                <button 
                    type="button" 
                    id="stopCameraScan" 
                    class="btn btn-secondary"
                    style="flex: 1; display: none;">
                    ⏹️ Arrêter Scanner
                </button>
                <div id="libraryStatus" style="font-size: 0.75rem; color: var(--text-muted); padding: 0.5rem; min-width: 200px;">
                    <span id="libraryStatusText">⏳ Chargement de la bibliothèque...</span>
                    <div id="libraryHelp" style="display: none; margin-top: 0.25rem; font-size: 0.7rem; color: var(--text-muted);">
                        Si le chargement échoue, rechargez la page (F5)
                    </div>
                </div>
            </div>
            
            <!-- Zone de prévisualisation vidéo caméra -->
            <div id="cameraScanArea" style="display: none; margin-bottom: 1rem;">
                <div style="text-align: center; margin-bottom: 0.5rem; color: var(--text-secondary); font-size: 0.875rem;">
                    Positionnez le code-barres dans le cadre
                </div>
                <div id="reader" style="width: 100%; max-width: 500px; min-height: 300px; margin: 0 auto; border: 2px solid var(--accent-primary); border-radius: var(--radius-md); padding: 1rem; background: var(--bg-secondary); position: relative; overflow: hidden; display: flex; align-items: center; justify-content: center;"></div>
                <div style="text-align: center; margin-top: 0.5rem; color: var(--text-muted); font-size: 0.75rem;">
                    Le scan se fera automatiquement dès la détection
                </div>
            </div>
            
            <!-- Zone de résultat -->
            <div id="scanResult" style="display: none; margin-top: 1rem; padding: 1rem; background: #dcfce7; border-radius: var(--radius-md); border: 1px solid #86efac;">
                <div id="scanResultContent" style="color: #166534; font-weight: 600;"></div>
            </div>
            
            <!-- Messages d'erreur -->
            <div id="scanError" style="display: none; margin-top: 1rem; padding: 1rem; background: #fee2e2; color: #991b1b; border-radius: var(--radius-md); border: 1px solid #fecaca;">
                <strong>Erreur :</strong> <span id="scanErrorText"></span>
            </div>
        </div>
    </section>

    <!-- Grille Masonry 2 colonnes -->
    <div id="stockMasonry" class="stock-masonry">
        
        <!-- Section Toners -->
        <section class="card-section" data-section="toners" aria-labelledby="section-toners-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['toners']) ?>" 
                        class="section-icon" 
                        alt="Toners" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-toners-title" class="section-title">Toners</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn btn-primary btn-sm btn-add" 
                        data-add-type="toner"
                        aria-label="Ajouter un toner">
                        <span aria-hidden="true">+</span> Ajouter toner
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="toners" role="table" aria-label="Liste des toners">
                    <colgroup>
                        <col class="col-couleur">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-text">Couleur</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($toners)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun toner en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($toners as $t): ?>
                                <tr 
                                    data-type="toners" 
                                    data-id="<?= h((string)$t['id']) ?>"
                                    data-search="<?= h(strtolower($t['marque'] . ' ' . $t['modele'] . ' ' . $t['couleur'])) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du toner <?= h($t['modele']) ?>">
                                    <td class="col-text" title="<?= h($t['couleur']) ?>"><?= h($t['couleur']) ?></td>
                                    <td class="col-text" title="<?= h($t['modele']) ?>"><?= h($t['modele']) ?></td>
                                    <td class="col-number td-metric <?= (int)$t['qty'] === 0 ? 'is-zero' : '' ?>"><?= (int)$t['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section Papier -->
        <section class="card-section" data-section="papier" aria-labelledby="section-papier-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['papier']) ?>" 
                        class="section-icon" 
                        alt="Papier" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-papier-title" class="section-title">Papier</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn btn-primary btn-sm btn-add" 
                        data-add-type="papier"
                        aria-label="Ajouter du papier">
                        <span aria-hidden="true">+</span> Ajouter papier
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="papier" role="table" aria-label="Liste du papier">
                    <colgroup>
                        <col class="col-qty">
                        <col class="col-modele">
                        <col class="col-poids">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-number">Qté</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-text">Poids</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($papers)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun papier en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($papers as $p): ?>
                                <?php if (!empty($p['paper_id'])): ?>
                                <tr 
                                    data-type="papier" 
                                    data-id="<?= h((string)$p['paper_id']) ?>"
                                    data-search="<?= h(strtolower(($p['marque'] ?? '') . ' ' . ($p['modele'] ?? '') . ' ' . ($p['poids'] ?? ''))) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du papier <?= h($p['modele'] ?? '') ?>">
                                    <td class="col-number td-metric"><?= (int)($p['qty_stock'] ?? 0) ?></td>
                                    <td class="col-text" title="<?= h($p['modele'] ?? '—') ?>"><?= h($p['modele'] ?? '—') ?></td>
                                    <td class="col-text" title="<?= h($p['poids'] ?? '—') ?>"><?= h($p['poids'] ?? '—') ?></td>
                                </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section LCD -->
        <section class="card-section" data-section="lcd" aria-labelledby="section-lcd-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['lcd']) ?>" 
                        class="section-icon" 
                        alt="Écrans LCD" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-lcd-title" class="section-title">LCD</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn btn-primary btn-sm btn-add" 
                        data-add-type="lcd"
                        aria-label="Ajouter un écran LCD">
                        <span aria-hidden="true">+</span> Ajouter LCD
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="lcd" role="table" aria-label="Liste des écrans LCD">
                    <colgroup>
                        <col class="col-etat">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-state">État</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lcd)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun LCD en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($lcd as $row): ?>
                                <tr
                                    data-type="lcd" 
                                    data-id="<?= h((string)$row['id']) ?>"
                                    data-search="<?= h(strtolower($row['modele'] . ' ' . $row['reference'] . ' ' . $row['marque'] . ' ' . $row['resolution'] . ' ' . $row['connectique'])) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails de l'écran LCD <?= h($row['modele']) ?>">
                                    <td class="col-state"><?= stateBadge($row['etat']) ?></td>
                                    <td class="col-text" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                                    <td class="col-number td-metric"><?= (int)$row['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Section PC -->
        <section class="card-section" data-section="pc" aria-labelledby="section-pc-title">
            <div class="section-head">
                <div class="head-left">
                    <img 
                        src="<?= h($sectionImages['pc']) ?>" 
                        class="section-icon" 
                        alt="PC reconditionnés" 
                        loading="lazy" 
                        onerror="this.style.display='none'">
                    <h2 id="section-pc-title" class="section-title">PC reconditionnés</h2>
                </div>
                <div class="head-right">
                    <button 
                        type="button" 
                        class="btn btn-primary btn-sm btn-add" 
                        data-add-type="pc"
                        aria-label="Ajouter un PC">
                        <span aria-hidden="true">+</span> Ajouter PC
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock click-rows" data-section="pc" role="table" aria-label="Liste des PC reconditionnés">
                    <colgroup>
                        <col class="col-etat">
                        <col class="col-modele">
                        <col class="col-qty">
                    </colgroup>
                    <thead>
                        <tr>
                            <th scope="col" class="col-state">État</th>
                            <th scope="col" class="col-text">Modèle</th>
                            <th scope="col" class="col-number">Qté</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pc)): ?>
                            <tr>
                                <td colspan="3" class="col-empty">
                                    <em>Aucun PC en stock</em>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pc as $row): ?>
                                <tr
                                    data-type="pc" 
                                    data-id="<?= h((string)$row['id']) ?>"
                                    data-search="<?= h(strtolower($row['modele'] . ' ' . $row['reference'] . ' ' . $row['marque'] . ' ' . $row['cpu'] . ' ' . $row['os'] . ' ' . $row['ram'] . ' ' . $row['stockage'])) ?>"
                                    role="button"
                                    tabindex="0"
                                    aria-label="Voir les détails du PC <?= h($row['modele']) ?>">
                                    <td class="col-state"><?= stateBadge($row['etat']) ?></td>
                                    <td class="col-text" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                                    <td class="col-number td-metric"><?= (int)$row['qty'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div><!-- /#stockMasonry -->
</div><!-- /.page-container -->

<!-- ===== Modale détails (Photocopieurs / LCD / PC) ===== -->
<div id="detailOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="detailModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="modalTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="modalTitle">Détails</h3>
        <button 
            type="button" 
            id="modalClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <div class="detail-grid" id="detailGrid"></div>
    </div>
    <div class="modal-footer" style="padding: 1rem; border-top: 1px solid var(--border-color); display: flex; justify-content: flex-end; gap: 0.5rem;">
        <button type="button" id="modalCloseFooter" class="btn btn-secondary">Fermer</button>
    </div>
</div>

<!-- ===== Modale ajout produit ===== -->
<div id="addOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="addModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="addModalTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="addModalTitle">Ajouter</h3>
        <button 
            type="button" 
            id="addModalClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <form id="addForm" novalidate>
            <div id="addFields" class="detail-grid"></div>
            <div class="modal-actions" style="margin-top:1rem; display:flex; gap:.5rem; justify-content:flex-end;">
                <button type="button" id="addCancel" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
            <div id="addError" class="form-error" style="color:#c00; margin-top:.5rem; display:none;" role="alert"></div>
        </form>
    </div>
</div>

<!-- ===== Modale résultats scan code-barres ===== -->
<div id="barcodeResultOverlay" class="modal-overlay" aria-hidden="true" role="presentation"></div>
<div 
    id="barcodeResultModal" 
    class="modal" 
    role="dialog" 
    aria-modal="true" 
    aria-labelledby="barcodeResultTitle" 
    style="display:none;">
    <div class="modal-header">
        <h3 id="barcodeResultTitle">Résultat du Scan</h3>
        <button 
            type="button" 
            id="barcodeResultClose" 
            class="icon-btn icon-btn--close" 
            aria-label="Fermer la modale">
            ×
        </button>
    </div>
    <div class="modal-body">
        <div id="barcodeResultContent" class="detail-grid"></div>
    </div>
</div>

<!-- Bibliothèque html5-qrcode via CDN avec fallback -->
<script>
(function() {
    'use strict';
    
    // Fonction pour charger la bibliothèque html5-qrcode
    function loadHtml5Qrcode() {
        return new Promise(function(resolve, reject) {
            // Vérifier si déjà chargé
            if (typeof Html5Qrcode !== 'undefined') {
                console.log('html5-qrcode déjà chargé');
                resolve();
                return;
            }
            
            // Liste des CDN à essayer
            const cdnUrls = [
                'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
                'https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js',
                'https://cdnjs.cloudflare.com/ajax/libs/html5-qrcode/2.3.8/html5-qrcode.min.js'
            ];
            
            let currentIndex = 0;
            
            function tryLoadCDN(index) {
                if (index >= cdnUrls.length) {
                    const errorMsg = 'Impossible de charger html5-qrcode depuis tous les CDN. Vérifiez votre connexion internet.';
                    console.error(errorMsg);
                    reject(new Error(errorMsg));
                    return;
                }
                
                const script = document.createElement('script');
                script.src = cdnUrls[index];
                script.async = true;
                script.crossOrigin = 'anonymous';
                
                script.onload = function() {
                    // Attendre que la bibliothèque s'initialise (augmenter le délai)
                    let attempts = 0;
                    const maxAttempts = 20; // 2 secondes max
                    
                    const checkLibrary = setInterval(function() {
                        attempts++;
                        if (typeof Html5Qrcode !== 'undefined') {
                            clearInterval(checkLibrary);
                            console.log('✓ html5-qrcode chargé depuis:', cdnUrls[index]);
                            resolve();
                        } else if (attempts >= maxAttempts) {
                            clearInterval(checkLibrary);
                            // Essayer le CDN suivant
                            console.warn('Timeout: Html5Qrcode non défini après chargement, essai CDN suivant...');
                            tryLoadCDN(index + 1);
                        }
                    }, 100);
                };
                
                script.onerror = function() {
                    console.warn('✗ Échec chargement depuis:', cdnUrls[index]);
                    // Essayer le CDN suivant
                    tryLoadCDN(index + 1);
                };
                
                document.head.appendChild(script);
            }
            
            tryLoadCDN(0);
        });
    }
    
    // Charger la bibliothèque au chargement de la page
    window.html5QrcodeLoaded = loadHtml5Qrcode();
    
    // Mettre à jour le statut de chargement
    window.html5QrcodeLoaded.then(function() {
        console.log('✓ Bibliothèque html5-qrcode chargée avec succès');
        window.html5QrcodeReady = true;
        // Mettre à jour l'indicateur visuel
        setTimeout(function() {
            const statusEl = document.getElementById('libraryStatusText');
            if (statusEl) {
                statusEl.textContent = '✓ Bibliothèque prête';
                statusEl.style.color = '#16a34a';
            }
        }, 100);
    }).catch(function(err) {
        console.error('Erreur chargement html5-qrcode:', err);
        window.html5QrcodeLoadError = true;
        window.html5QrcodeReady = false;
        // Mettre à jour l'indicateur visuel
        setTimeout(function() {
            const statusEl = document.getElementById('libraryStatusText');
            if (statusEl) {
                statusEl.textContent = '✗ Erreur de chargement - Rechargez la page';
                statusEl.style.color = '#dc2626';
            }
        }, 100);
        
        // Afficher l'aide si erreur après 5 secondes
        setTimeout(function() {
            if (typeof Html5Qrcode === 'undefined' && !window.html5QrcodeReady) {
                const helpEl = document.getElementById('libraryHelp');
                if (helpEl) {
                    helpEl.style.display = 'block';
                }
            }
        }, 5000);
    });
})();
</script>

<script>
// S'assurer que le DOM est chargé avant d'exécuter les scripts
(function() {
    'use strict';
    
    // Référence globale pour la fonction open de la modale détails
    let detailModalOpen = null;
    
    function initStockScripts() {
        console.log('Initialisation des scripts stock...');
        initFilter();
        initDetailModal();
        initAddModal();
        console.log('Scripts stock initialisés');
    }

    // Attendre que le DOM soit complètement chargé
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            setTimeout(initStockScripts, 100);
        });
    } else {
        setTimeout(initStockScripts, 100);
    }

    /* ===== Filtre + réordonnancement ===== */
    function initFilter() {
        const q = document.getElementById('q');
        const mason = document.getElementById('stockMasonry');
        const clearBtn = document.getElementById('clearSearch');
        const resultsCount = document.getElementById('searchResultsCount');
        const allRows = Array.from(document.querySelectorAll('.tbl-stock tbody tr'));

        function visibleRowCount(section) {
            const rows = section.querySelectorAll('tbody tr');
            let n = 0;
            rows.forEach(function(r) {
                if (r.style.display !== 'none') {
                    n++;
                }
            });
            return n;
        }
        
        function getTotalVisibleRows() {
            return allRows.filter(function(tr) {
                return tr.style.display !== 'none';
            }).length;
        }
        
        function updateResultsCount() {
            if (!resultsCount) {
                return;
            }
            const visible = getTotalVisibleRows();
            const total = allRows.length;
            if (q && q.value.trim()) {
                resultsCount.textContent = visible + ' / ' + total + ' résultats';
                resultsCount.style.display = 'inline-block';
            } else {
                resultsCount.style.display = 'none';
            }
        }
        
        function reorderSections() {
            if (!mason) {
                return;
            }
            const sections = Array.from(mason.querySelectorAll('.card-section'));
            const scored = sections.map(function(s, i) {
                return {
                    el: s,
                    score: visibleRowCount(s),
                    idx: i
                };
            });
            scored.sort(function(a, b) {
                return (b.score - a.score) || (a.idx - b.idx);
            });
            scored.forEach(function(x) {
                mason.appendChild(x.el);
            });
        }
        
        function norm(s) {
            return (s || '').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        
        let filterTimeout = null;
        function applyFilter() {
            if (!q) {
                return;
            }
            const v = norm(q.value || '');
            document.querySelectorAll('.tbl-stock tbody tr').forEach(function(tr) {
                const t = norm(tr.getAttribute('data-search') || '');
                const isVisible = !v || t.includes(v);
                tr.style.display = isVisible ? '' : 'none';
            });
            reorderSections();
            updateResultsCount();
            
            // Masquer les sections vides avec animation
            document.querySelectorAll('.card-section').forEach(function(section) {
                const hasVisible = section.querySelectorAll('tbody tr[style=""]').length > 0;
                if (!hasVisible && v) {
                    section.style.opacity = '0.5';
                    section.style.transform = 'scale(0.98)';
                } else {
                    section.style.opacity = '1';
                    section.style.transform = 'scale(1)';
                }
            });
        }
        
        // Debounce pour améliorer les performances
        if (q) {
            const searchWrapper = q.parentElement;
            
            function updateSearchState() {
                if (q.value.trim()) {
                    searchWrapper.classList.add('has-value');
                } else {
                    searchWrapper.classList.remove('has-value');
                }
            }
            
            q.addEventListener('input', function() {
                updateSearchState();
                clearTimeout(filterTimeout);
                filterTimeout = setTimeout(applyFilter, 200);
            });
            
            q.addEventListener('focus', function() {
                searchWrapper.classList.add('focused');
            });
            
            q.addEventListener('blur', function() {
                searchWrapper.classList.remove('focused');
            });
            
            updateSearchState();
        }
        
        // Bouton clear
        if (clearBtn) {
            clearBtn.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                if (q) {
                    q.value = '';
                    q.focus();
                    applyFilter();
                }
            });
        }
        
        // Initialisation
        reorderSections();
        updateResultsCount();
        
        if ('ResizeObserver' in window) {
            const ro = new ResizeObserver(function() {
                reorderSections();
            });
            mason.querySelectorAll('.card-section').forEach(function(sec) {
                ro.observe(sec);
            });
        }
    }

    /* ===== Datasets popup ===== */
    const DATASETS = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

    /* Helpers sûrs (XSS) */
    function escapeText(s) {
        return (s == null) ? '—' : String(s);
    }
    
    function addField(grid, label, value, options) {
        options = options || {};
        const card = document.createElement('div');
        card.className = 'field-card';
        const lbl = document.createElement('div');
        lbl.className = 'lbl';
        lbl.textContent = label;
        const val = document.createElement('div');
        val.className = 'val';
        if (options.html) {
            val.innerHTML = value ?? '—';
        } else {
            val.textContent = escapeText(value);
        }
        card.appendChild(lbl);
        card.appendChild(val);
        grid.appendChild(card);
    }
    
    function badgeEtat(e) {
        e = String(e || '').toUpperCase();
        if (!['A', 'B', 'C'].includes(e)) {
            return '<span class="state state-na">—</span>';
        }
        return '<span class="state state-' + e + '">' + e + '</span>';
    }

    /* ===== Modal détails ===== */
    function initDetailModal() {
        const overlay = document.getElementById('detailOverlay');
        const modal = document.getElementById('detailModal');
        const close = document.getElementById('modalClose');
        const grid = document.getElementById('detailGrid');
        const titleEl = document.getElementById('modalTitle');

        if (!overlay || !modal || !close || !grid || !titleEl) {
            console.error('Éléments de la modale de détails manquants');
            return;
        }

        let lastFocused = null;
        
        function focusFirst() {
            const f = modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
            if (f.length) {
                f[0].focus();
            }
        }
        
        function trapFocus(e) {
            if (e.key !== 'Tab') {
                return;
            }
            const f = Array.from(modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])'));
            if (!f.length) {
                return;
            }
            const first = f[0];
            const last = f[f.length - 1];
            if (e.shiftKey && document.activeElement === first) {
                e.preventDefault();
                last.focus();
            } else if (!e.shiftKey && document.activeElement === last) {
                e.preventDefault();
                first.focus();
            }
        }
        
        function onKeydown(e) {
            if (e.key === 'Escape') {
                closeFn();
            }
            if (e.key === 'Tab') {
                trapFocus(e);
            }
        }
        
        function open() {
            lastFocused = document.activeElement;
            document.body.classList.add('modal-open');
            overlay.setAttribute('aria-hidden', 'false');
            overlay.style.display = 'block';
            modal.style.display = 'block';
            document.addEventListener('keydown', onKeydown);
            focusFirst();
        }
        
        function closeFn() {
            document.body.classList.remove('modal-open');
            overlay.setAttribute('aria-hidden', 'true');
            overlay.style.display = 'none';
            modal.style.display = 'none';
            document.removeEventListener('keydown', onKeydown);
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus();
            }
        }
        
        // Exposer open globalement pour être accessible depuis handleRowClick
        detailModalOpen = open;
        
        if (close) {
            close.addEventListener('click', closeFn);
        }
        const closeFooter = document.getElementById('modalCloseFooter');
        if (closeFooter) {
            closeFooter.addEventListener('click', closeFn);
        }
        if (overlay) {
            overlay.addEventListener('click', closeFn);
        }

        function renderDetails(type, row) {
            if (!grid || !titleEl) {
                console.error('Grid ou titleEl manquant');
                return;
            }
            
            grid.innerHTML = '';
            const typeNames = {
                'copiers': 'PHOTOCOPIEUR',
                'lcd': 'LCD',
                'pc': 'PC',
                'toners': 'TONER',
                'papier': 'PAPIER'
            };
            const displayName = row.modele ?? row.reference ?? row.marque ?? 'Détails';
            titleEl.textContent = displayName + ' — ' + (typeNames[type] || type.toUpperCase());
            
            if (type === 'copiers') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'N° Série', row.sn);
                addField(grid, 'Adresse MAC', row.mac);
                addField(grid, 'Compteur N&B', new Intl.NumberFormat('fr-FR').format(row.compteur_bw || 0));
                addField(grid, 'Compteur Couleur', new Intl.NumberFormat('fr-FR').format(row.compteur_color || 0));
                addField(grid, 'Statut', row.statut);
                addField(grid, 'Emplacement', row.emplacement);
                if (row.last_ts) {
                    addField(grid, 'Dernière relève', row.last_ts);
                }
            } else if (type === 'lcd') {
                addField(grid, 'État', badgeEtat(row.etat), {html: true});
                addField(grid, 'Référence', row.reference);
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Taille', (row.taille ? row.taille + '"' : '—'));
                addField(grid, 'Résolution', row.resolution);
                addField(grid, 'Connectique', row.connectique);
                addField(grid, 'Prix', row.prix != null ? new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(row.prix) : '—');
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'pc') {
                addField(grid, 'État', badgeEtat(row.etat), {html: true});
                addField(grid, 'Référence', row.reference);
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'CPU', row.cpu);
                addField(grid, 'RAM', row.ram);
                addField(grid, 'Stockage', row.stockage);
                addField(grid, 'OS', row.os);
                addField(grid, 'GPU', row.gpu);
                addField(grid, 'Réseau', row.reseau);
                addField(grid, 'Ports', row.ports);
                addField(grid, 'Prix', row.prix != null ? new Intl.NumberFormat('fr-FR', {style: 'currency', currency: 'EUR'}).format(row.prix) : '—');
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'toners') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Couleur', row.couleur);
                addField(grid, 'Quantité', row.qty);
            } else if (type === 'papier') {
                addField(grid, 'Marque', row.marque);
                addField(grid, 'Modèle', row.modele);
                addField(grid, 'Poids', row.poids);
                addField(grid, 'Quantité', row.qty_stock ?? row.qty ?? 0);
            }
            
            // Ajouter le bouton d'impression d'étiquettes
            if (row.id && type !== 'copiers') {
                const printBtnWrapper = document.createElement('div');
                printBtnWrapper.className = 'field-card';
                printBtnWrapper.style.gridColumn = '1 / -1';
                printBtnWrapper.style.textAlign = 'center';
                printBtnWrapper.style.padding = '1rem';
                
                const printBtn = document.createElement('button');
                printBtn.type = 'button';
                printBtn.className = 'btn btn-primary';
                printBtn.textContent = '🖨️ Imprimer Étiquettes (24)';
                printBtn.style.padding = '0.75rem 1.5rem';
                printBtn.style.fontSize = '1rem';
                printBtn.addEventListener('click', function() {
                    printLabels(type, row.id, row.modele || row.reference || row.marque || 'Produit');
                });
                
                printBtnWrapper.appendChild(printBtn);
                grid.appendChild(printBtnWrapper);
            }
        }
        
        // Fonction pour ouvrir la page d'impression
        function printLabels(type, productId, productName) {
            const url = `/public/print_labels.php?type=${encodeURIComponent(type)}&id=${encodeURIComponent(productId)}&name=${encodeURIComponent(productName)}`;
            window.open(url, '_blank');
        }

        // Fonction pour gérer le clic sur une ligne
        function handleRowClick(tr, e) {
            // Ne pas ouvrir si on clique sur un bouton, un lien ou un input
            if (e && e.target) {
                const clickedElement = e.target.closest('button, a, input, select, .btn-add');
                if (clickedElement) {
                    return;
                }
            }
            
            // Ne pas ouvrir si l'utilisateur est en train de sélectionner du texte
            if (e) {
                const selection = window.getSelection();
                if (selection && selection.toString().trim().length > 0) {
                    return;
                }
            }
            
            if (e) {
                e.preventDefault();
                e.stopPropagation();
            }
            
            const type = tr.getAttribute('data-type');
            const id = tr.getAttribute('data-id');
            
            if (!type || !id) {
                console.warn('Type ou ID manquant:', {type: type, id: id});
                return;
            }
            
            const rows = (DATASETS[type] || []);
            
            if (rows.length === 0) {
                console.warn('Dataset vide pour type:', type);
                return;
            }
            
            // Chercher la ligne correspondante (gérer différents formats d'ID)
            const searchId = String(id).trim();
            let row = rows.find(function(r) {
                // Essayer avec id
                if (r.id !== undefined && String(r.id).trim() === searchId) {
                    return true;
                }
                // Essayer avec paper_id
                if (r.paper_id !== undefined && String(r.paper_id).trim() === searchId) {
                    return true;
                }
                // Essayer avec toner_id
                if (r.toner_id !== undefined && String(r.toner_id).trim() === searchId) {
                    return true;
                }
                return false;
            });
            
            if (!row) {
                console.warn('Ligne non trouvée dans le dataset:', {
                    type: type,
                    searchedId: id
                });
                return;
            }
            
            renderDetails(type, row);
            
            // Utiliser la référence globale
            if (detailModalOpen && typeof detailModalOpen === 'function') {
                detailModalOpen();
            } else {
                console.error('La fonction open() n\'est pas définie!');
            }
        }
        
        // Utiliser la délégation d'événements au niveau du document
        document.addEventListener('click', function(e) {
            // Ignorer si on clique sur un bouton d'ajout
            if (e.target.closest('.btn-add')) {
                return;
            }
            
            // Trouver la ligne la plus proche avec data-type et data-id
            const tr = e.target.closest('tbody tr[data-type][data-id]');
            if (tr) {
                handleRowClick(tr, e);
            }
        });
        
        // Rendre les lignes visuellement cliquables et ajouter support clavier
        const clickableRows = document.querySelectorAll('tbody tr[data-type][data-id]');
        
        clickableRows.forEach(function(tr) {
            tr.style.cursor = 'pointer';
            tr.tabIndex = 0;
            tr.setAttribute('role', 'button');
            tr.setAttribute('aria-label', 'Afficher les détails');
            
            // Support clavier
            tr.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    e.stopPropagation();
                    handleRowClick(tr, null);
                }
            });
        });
    }

    /* ===== Modale ajout produit (papier / toner / lcd / pc) ===== */
    function initAddModal() {
        const overlay = document.getElementById('addOverlay');
        const modal = document.getElementById('addModal');
        const titleEl = document.getElementById('addModalTitle');
        const btnClose = document.getElementById('addModalClose');
        const btnCancel = document.getElementById('addCancel');
        const form = document.getElementById('addForm');
        const fieldsContainer = document.getElementById('addFields');
        const errorBox = document.getElementById('addError');

        let currentType = null;

        const FORM_SCHEMAS = {
            toner: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'couleur', label: 'Couleur', type: 'text', required: true},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference', label: 'Référence (BL, facture…)', type: 'text'}
            ],
            papier: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'poids', label: 'Poids', type: 'text', required: true},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference', label: 'Référence (BL, facture…)', type: 'text'}
            ],
            lcd: [
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'reference', label: 'Référence', type: 'text', required: true},
                {name: 'etat', label: 'État (A/B/C)', type: 'text', required: true, maxLength: 1},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'taille', label: 'Taille (pouces)', type: 'number', required: true, min: 10},
                {name: 'resolution', label: 'Résolution', type: 'text', required: true},
                {name: 'connectique', label: 'Connectique', type: 'text', required: true},
                {name: 'prix', label: 'Prix (EUR)', type: 'number', step: '0.01'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference_move', label: 'Référence mouvement (BL, facture…)', type: 'text'}
            ],
            pc: [
                {name: 'etat', label: 'État (A/B/C)', type: 'text', required: true, maxLength: 1},
                {name: 'reference', label: 'Référence', type: 'text', required: true},
                {name: 'marque', label: 'Marque', type: 'text', required: true},
                {name: 'modele', label: 'Modèle', type: 'text', required: true},
                {name: 'cpu', label: 'CPU', type: 'text', required: true},
                {name: 'ram', label: 'RAM', type: 'text', required: true},
                {name: 'stockage', label: 'Stockage', type: 'text', required: true},
                {name: 'os', label: 'OS', type: 'text', required: true},
                {name: 'gpu', label: 'GPU', type: 'text'},
                {name: 'reseau', label: 'Réseau', type: 'text'},
                {name: 'ports', label: 'Ports', type: 'text'},
                {name: 'prix', label: 'Prix (EUR)', type: 'number', step: '0.01'},
                {name: 'qty_delta', label: 'Quantité', type: 'number', required: true, min: 1},
                {name: 'reference_move', label: 'Référence mouvement (BL, facture…)', type: 'text'}
            ]
        };

        function clearForm() {
            if (fieldsContainer) {
                fieldsContainer.innerHTML = '';
            }
            if (errorBox) {
                errorBox.style.display = 'none';
                errorBox.textContent = '';
            }
        }

        function buildForm(type) {
            clearForm();
            const schema = FORM_SCHEMAS[type];
            if (!schema || !fieldsContainer) {
                return;
            }
            schema.forEach(function(f) {
                const wrapper = document.createElement('div');
                wrapper.className = 'field-card';

                const lbl = document.createElement('label');
                lbl.className = 'lbl';
                lbl.textContent = f.label;
                lbl.htmlFor = 'add_' + f.name;

                const input = document.createElement('input');
                input.className = 'val';
                input.id = 'add_' + f.name;
                input.name = f.name;
                input.type = f.type || 'text';
                if (f.required) {
                    input.required = true;
                }
                if (f.min != null) {
                    input.min = f.min;
                }
                if (f.step != null) {
                    input.step = f.step;
                }
                if (f.maxLength != null) {
                    input.maxLength = f.maxLength;
                }

                wrapper.appendChild(lbl);
                wrapper.appendChild(input);
                fieldsContainer.appendChild(wrapper);
            });
        }

        function openModal(type) {
            if (!type) {
                console.error('Type manquant pour openModal');
                return;
            }
            currentType = type;
            const typeNames = {
                'toner': 'toner',
                'papier': 'papier',
                'lcd': 'LCD',
                'pc': 'PC'
            };
            if (titleEl) {
                titleEl.textContent = 'Ajouter ' + (typeNames[type] || type);
            }
            buildForm(type);
            
            // Focus sur le premier champ du formulaire après un court délai
            setTimeout(function() {
                const firstInput = fieldsContainer.querySelector('input, select, textarea');
                if (firstInput) {
                    firstInput.focus();
                }
            }, 100);
            
            document.body.classList.add('modal-open');
            if (overlay) {
                overlay.style.display = 'block';
                overlay.setAttribute('aria-hidden', 'false');
            }
            if (modal) {
                modal.style.display = 'block';
            }
        }

        function closeModal() {
            document.body.classList.remove('modal-open');
            if (overlay) {
                overlay.style.display = 'none';
                overlay.setAttribute('aria-hidden', 'true');
            }
            if (modal) {
                modal.style.display = 'none';
            }
            currentType = null;
            clearForm();
        }

        // Vérifier que tous les éléments nécessaires existent
        if (!overlay || !modal || !titleEl || !fieldsContainer || !form || !errorBox) {
            console.error('Éléments DOM manquants pour la modale d\'ajout');
            return;
        }

        if (btnClose) {
            btnClose.addEventListener('click', closeModal);
        }
        if (btnCancel) {
            btnCancel.addEventListener('click', function(e) {
                e.preventDefault();
                closeModal();
            });
        }
        if (overlay) {
            overlay.addEventListener('click', closeModal);
        }

        // Attacher les event listeners aux boutons d'ajout
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.btn-add[data-add-type]');
            if (btn) {
                e.preventDefault();
                e.stopPropagation();
                const t = btn.getAttribute('data-add-type');
                if (t) {
                    openModal(t);
                } else {
                    console.error('Attribut data-add-type manquant sur le bouton');
                }
            }
        });

        if (form) {
            form.addEventListener('submit', async function(e) {
                e.preventDefault();
                if (!currentType) {
                    return;
                }

                const formData = new FormData(form);
                const payload = {};
                formData.forEach(function(v, k) {
                    payload[k] = v;
                });

                // Récupérer le token CSRF depuis le meta tag
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

                try {
                    const res = await fetch('/API/stock_add.php', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            type: currentType,
                            data: payload,
                            csrf_token: csrfToken
                        })
                    });

                    const text = await res.text();
                    let json;
                    try {
                        json = JSON.parse(text);
                    } catch (e) {
                        console.error('Réponse non JSON de /API/stock_add.php :', text);
                        throw new Error('Réponse invalide du serveur (pas du JSON).');
                    }

                    if (!res.ok || !json.ok) {
                        console.error('Erreur API :', json);
                        if (errorBox) {
                            errorBox.textContent = json.error || "Erreur lors de l'enregistrement.";
                            errorBox.style.display = 'block';
                        }
                        return;
                    }

                    // Succès : afficher message et recharger
                    closeModal();
                    const url = new URL(window.location.href);
                    url.searchParams.set('added', currentType);
                    window.location.href = url.toString();
                } catch (err) {
                    console.error('Erreur fetch :', err);
                    if (errorBox) {
                        errorBox.textContent = 'Erreur réseau ou serveur.';
                        errorBox.style.display = 'block';
                    }
                }
            });
        }
    }
})();

    /* ===== Scanner Code-Barres ===== */
    (function() {
        'use strict';
        
        let html5QrcodeScanner = null;
        let isScanning = false;
        
        const toggleBtn = document.getElementById('toggleScanner');
        const scannerContainer = document.getElementById('scannerContainer');
        const startCameraBtn = document.getElementById('startCameraScan');
        const stopCameraBtn = document.getElementById('stopCameraScan');
        const cameraScanArea = document.getElementById('cameraScanArea');
        const scanResult = document.getElementById('scanResult');
        const scanResultContent = document.getElementById('scanResultContent');
        const scanError = document.getElementById('scanError');
        const scanErrorText = document.getElementById('scanErrorText');
        const searchInput = document.getElementById('q'); // Champ de recherche principal
        
        // Toggle scanner container
        if (toggleBtn && scannerContainer) {
            toggleBtn.addEventListener('click', function() {
                const isVisible = scannerContainer.style.display !== 'none';
                scannerContainer.style.display = isVisible ? 'none' : 'block';
                toggleBtn.textContent = isVisible ? 'Ouvrir Scanner' : 'Fermer Scanner';
                
                if (!isVisible && isScanning) {
                    stopScanning();
                }
            });
        }
        
        // Démarrer le scan caméra
        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', async function() {
                // Désactiver le bouton pendant le chargement
                const originalText = startCameraBtn.textContent;
                startCameraBtn.disabled = true;
                startCameraBtn.textContent = '⏳ Chargement de la bibliothèque...';
                hideError();
                
                try {
                    // Attendre que la bibliothèque soit chargée avec timeout
                    let libraryReady = false;
                    
                    if (window.html5QrcodeLoaded) {
                        try {
                            // Attendre la promesse avec timeout
                            await Promise.race([
                                window.html5QrcodeLoaded,
                                new Promise(function(_, reject) {
                                    setTimeout(function() {
                                        reject(new Error('Timeout: La bibliothèque prend trop de temps à charger'));
                                    }, 10000); // 10 secondes max
                                })
                            ]);
                            libraryReady = true;
                        } catch (promiseErr) {
                            console.warn('Erreur promesse html5QrcodeLoaded:', promiseErr);
                            // Continuer avec la vérification directe
                        }
                    }
                    
                    // Vérification directe avec plusieurs tentatives
                    if (!libraryReady) {
                        startCameraBtn.textContent = '⏳ Vérification de la bibliothèque...';
                        
                        for (let i = 0; i < 30; i++) {
                            if (typeof Html5Qrcode !== 'undefined') {
                                libraryReady = true;
                                console.log('Bibliothèque détectée après', i * 100, 'ms');
                                break;
                            }
                            await new Promise(function(resolve) {
                                setTimeout(resolve, 100);
                            });
                        }
                    }
                    
                    // Vérification finale
                    if (typeof Html5Qrcode === 'undefined') {
                        const errorDetails = [
                            'Bibliothèque html5-qrcode non disponible.',
                            '',
                            'Causes possibles:',
                            '• Problème de connexion internet',
                            '• Bloqueur de scripts/CDN',
                            '• CDN inaccessible',
                            '',
                            'Solutions:',
                            '1. Rechargez la page (F5 ou Ctrl+R)',
                            '2. Vérifiez votre connexion internet',
                            '3. Désactivez temporairement les bloqueurs de publicités',
                            '4. Vérifiez la console du navigateur (F12) pour plus de détails'
                        ].join('\n');
                        console.error(errorDetails);
                        throw new Error('Bibliothèque html5-qrcode non disponible. Veuillez recharger la page (F5) ou vérifier votre connexion internet.');
                    }
                    
                    startCameraBtn.textContent = '⏳ Démarrage de la caméra...';
                    
                    // Vérifier HTTPS (requis pour la caméra)
                    if (location.protocol !== 'https:' && location.hostname !== 'localhost' && location.hostname !== '127.0.0.1') {
                        throw new Error('La caméra nécessite une connexion HTTPS sécurisée. Veuillez utiliser https://');
                    }
                    
                    // Vérifier que l'API MediaDevices est disponible
                    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                        throw new Error('Votre navigateur ne supporte pas l\'accès à la caméra. Veuillez utiliser un navigateur moderne (Chrome, Firefox, Edge).');
                    }
                    
                    await startCameraScanning();
                    
                } catch (err) {
                    console.error('Erreur démarrage caméra:', err);
                    showError('Erreur: ' + (err.message || err));
                } finally {
                    // Réactiver le bouton
                    startCameraBtn.disabled = false;
                    startCameraBtn.textContent = originalText;
                }
            });
        }
        
        // Arrêter le scan caméra
        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', function() {
                stopScanning();
            });
        }
        
        // Fonction pour démarrer le scan caméra
        async function startCameraScanning() {
            if (isScanning) {
                return;
            }
            
            try {
                // Vérifier si html5-qrcode est disponible
                if (typeof Html5Qrcode === 'undefined') {
                    throw new Error('Bibliothèque html5-qrcode non chargée. Vérifiez votre connexion internet.');
                }
                
                const reader = document.getElementById('reader');
                if (!reader) {
                    throw new Error('Zone de scan introuvable');
                }
                
                // Afficher la zone de scan AVANT de démarrer la caméra
                cameraScanArea.style.display = 'block';
                startCameraBtn.style.display = 'none';
                stopCameraBtn.style.display = 'block';
                hideError();
                hideResult();
                
                // Afficher un message de chargement
                reader.innerHTML = '<div style="text-align: center; padding: 2rem; color: var(--text-secondary);"><div style="margin-bottom: 1rem; font-size: 1.2rem;">⏳</div><div style="margin-bottom: 0.5rem; font-weight: 600;">Démarrage de la caméra...</div><div style="font-size: 0.75rem; color: var(--text-muted);">Si la caméra ne s\'affiche pas, vérifiez les permissions de votre navigateur.</div></div>';
                
                html5QrcodeScanner = new Html5Qrcode('reader');
                
                // Essayer d'abord avec la caméra arrière, puis la caméra avant
                let cameraConfig = { facingMode: 'environment' };
                let started = false;
                
                try {
                    // Essayer la caméra arrière (environment)
                    await html5QrcodeScanner.start(
                        cameraConfig,
                        {
                            fps: 10,
                            qrbox: function(viewfinderWidth, viewfinderHeight) {
                                // Calculer une taille adaptative (60% de la largeur minimale)
                                let minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                                let qrboxSize = Math.floor(minEdge * 0.6);
                                return {
                                    width: qrboxSize,
                                    height: qrboxSize
                                };
                            },
                            aspectRatio: 1.0,
                            disableFlip: false,
                            videoConstraints: {
                                facingMode: 'environment',
                                width: { ideal: 1280 },
                                height: { ideal: 720 }
                            }
                        },
                        onScanSuccess,
                        onScanError
                    );
                    started = true;
                } catch (envError) {
                    console.log('Caméra arrière non disponible, essai caméra avant:', envError);
                    // Essayer la caméra avant (user)
                    try {
                        await html5QrcodeScanner.stop();
                        html5QrcodeScanner.clear();
                    } catch (e) {
                        // Ignorer
                    }
                    
                    cameraConfig = { facingMode: 'user' };
                    html5QrcodeScanner = new Html5Qrcode('reader');
                    
                    await html5QrcodeScanner.start(
                        cameraConfig,
                        {
                            fps: 10,
                            qrbox: function(viewfinderWidth, viewfinderHeight) {
                                let minEdge = Math.min(viewfinderWidth, viewfinderHeight);
                                let qrboxSize = Math.floor(minEdge * 0.6);
                                return {
                                    width: qrboxSize,
                                    height: qrboxSize
                                };
                            },
                            aspectRatio: 1.0,
                            disableFlip: false,
                            videoConstraints: {
                                facingMode: 'user',
                                width: { ideal: 1280 },
                                height: { ideal: 720 }
                            }
                        },
                        onScanSuccess,
                        onScanError
                    );
                    started = true;
                }
                
                if (started) {
                    isScanning = true;
                    console.log('Caméra démarrée avec succès');
                }
                
            } catch (err) {
                console.error('Erreur démarrage caméra:', err);
                
                // Réinitialiser l'interface
                startCameraBtn.style.display = 'block';
                stopCameraBtn.style.display = 'none';
                cameraScanArea.style.display = 'none';
                isScanning = false;
                
                // Afficher un message d'erreur détaillé
                let errorMsg = 'Impossible de démarrer la caméra. ';
                
                if (err.name === 'NotAllowedError' || err.message.includes('permission')) {
                    errorMsg += 'Veuillez autoriser l\'accès à la caméra dans les paramètres de votre navigateur.';
                } else if (err.name === 'NotFoundError' || err.message.includes('device')) {
                    errorMsg += 'Aucune caméra détectée sur cet appareil.';
                } else if (err.message.includes('HTTPS') || err.message.includes('secure')) {
                    errorMsg += 'La caméra nécessite une connexion HTTPS sécurisée.';
                } else {
                    errorMsg += err.message || 'Erreur inconnue.';
                }
                
                showError(errorMsg);
                
                // Nettoyer
                if (html5QrcodeScanner) {
                    try {
                        await html5QrcodeScanner.stop();
                        html5QrcodeScanner.clear();
                    } catch (e) {
                        // Ignorer
                    }
                    html5QrcodeScanner = null;
                }
            }
        }
        
        // Fonction pour arrêter le scan
        function stopScanning() {
            if (html5QrcodeScanner && isScanning) {
                html5QrcodeScanner.stop().then(() => {
                    html5QrcodeScanner.clear();
                    html5QrcodeScanner = null;
                    isScanning = false;
                    startCameraBtn.style.display = 'block';
                    stopCameraBtn.style.display = 'none';
                    cameraScanArea.style.display = 'none';
                    
                    // Nettoyer le contenu de la zone de scan
                    const reader = document.getElementById('reader');
                    if (reader) {
                        reader.innerHTML = '';
                    }
                }).catch((err) => {
                    console.error('Erreur arrêt caméra:', err);
                    // Forcer la réinitialisation même en cas d'erreur
                    html5QrcodeScanner = null;
                    isScanning = false;
                    startCameraBtn.style.display = 'block';
                    stopCameraBtn.style.display = 'none';
                    cameraScanArea.style.display = 'none';
                });
            } else {
                // Réinitialiser même si le scanner n'est pas actif
                isScanning = false;
                startCameraBtn.style.display = 'block';
                stopCameraBtn.style.display = 'none';
                cameraScanArea.style.display = 'none';
            }
        }
        
        // Callback succès scan
        function onScanSuccess(decodedText, decodedResult) {
            if (decodedText) {
                // Arrêter le scan immédiatement
                stopScanning();
                
                // Remplir automatiquement le champ de recherche
                fillSearchField(decodedText);
                
                // Afficher un message de succès
                showResult('✓ Code-barres scanné : ' + decodedText);
                
                // Optionnel : rechercher le produit et afficher les détails
                setTimeout(() => {
                    processBarcode(decodedText);
                }, 500);
            }
        }
        
        // Callback erreur scan (on ignore les erreurs continues)
        function onScanError(errorMessage) {
            // Ignorer les erreurs continues de scan
        }
        
        // Fonction pour remplir le champ de recherche
        function fillSearchField(barcode) {
            if (searchInput) {
                searchInput.value = barcode;
                searchInput.focus();
                
                // Déclencher l'événement input pour activer le filtre
                const inputEvent = new Event('input', { bubbles: true });
                searchInput.dispatchEvent(inputEvent);
            }
        }
        
        // Fonction pour traiter le code-barres scanné (recherche produit)
        async function processBarcode(barcode) {
            hideError();
            
            try {
                const response = await fetch(`/API/get_product_by_barcode.php?barcode=${encodeURIComponent(barcode)}`, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json'
                    }
                });
                
                const data = await response.json();
                
                if (!response.ok || !data.ok) {
                    // Si produit non trouvé, on garde juste le code-barres dans la recherche
                    console.log('Produit non trouvé pour le code-barres:', barcode);
                    return;
                }
                
                // Produit trouvé : on peut afficher un message ou ouvrir la modal de détails
                showResult('✓ Produit trouvé : ' + (data.product.nom || barcode) + ' (Stock: ' + (data.product.qty_stock || 0) + ')');
                
            } catch (err) {
                console.error('Erreur récupération produit:', err);
                // On ignore l'erreur, le code-barres est déjà dans la recherche
            }
        }
        
        // Fonctions helper pour afficher/masquer les messages
        function showResult(html) {
            if (scanResult && scanResultContent) {
                scanResultContent.innerHTML = html;
                scanResult.style.display = 'block';
            }
        }
        
        function hideResult() {
            if (scanResult) {
                scanResult.style.display = 'none';
            }
        }
        
        function showError(message) {
            if (scanError && scanErrorText) {
                scanErrorText.textContent = message;
                scanError.style.display = 'block';
            }
        }
        
        function hideError() {
            if (scanError) {
                scanError.style.display = 'none';
            }
        }
        
        
        // Nettoyer à la fermeture de la page
        window.addEventListener('beforeunload', function() {
            stopScanning();
        });
    })();
</script>
</body>
</html>
