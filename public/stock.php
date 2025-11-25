<?php
/**
 * Page de gestion du stock
 * Affiche les différents types de produits en stock (papier, toners, LCD, PC)
 * 
 * @package CCComputer
 * @version 2.0
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
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
    <div class="page-header">
        <h2 class="page-title">Stock</h2>
        <p class="page-subtitle">Disposition <strong>dynamique</strong> — la section la plus remplie s'affiche en premier.</p>
    </div>

    <?php if ($flash && isset($flash['type'])): ?>
        <div class="flash <?= h($flash['type']) ?>" role="alert">
            <?= h($flash['msg'] ?? '') ?>
        </div>
    <?php endif; ?>

    <section class="stock-meta">
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

    <div class="filters-row">
        <div class="search-wrapper">
            <input type="text" id="q" placeholder="Filtrer partout (réf., modèle, SN, MAC, CPU…)" aria-label="Filtrer" />
            <button type="button" class="search-clear-btn" id="clearSearch" aria-label="Effacer la recherche" title="Effacer">×</button>
        </div>
        <span class="search-results-count" id="searchResultsCount" style="display: none;"></span>
    </div>

    <!-- Masonry 2 colonnes -->
    <div id="stockMasonry" class="stock-masonry">
        <!-- Toners -->
        <section class="card-section" data-section="toners">
            <div class="section-head">
                <div class="head-left">
                    <img src="<?= h($sectionImages['toners']) ?>" class="section-icon" alt="Toners" loading="lazy" onerror="this.style.display='none'">
                    <h3 class="section-title">Toners</h3>
                </div>
                <div class="head-right">
                    <button type="button" class="btn btn-primary btn-sm btn-add" data-add-type="toner">
                        + Ajouter toner
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock tbl-compact click-rows" data-section="toners">
                    <colgroup>
                        <col class="col-couleur"><col class="col-modele"><col class="col-qty">
                    </colgroup>
                    <thead><tr><th>Couleur</th><th>Modèle</th><th>Qté</th></tr></thead>
                    <tbody>
                    <?php foreach ($toners as $t): ?>
                        <tr 
                            data-type="toners" 
                            data-id="<?= h((string)$t['id']) ?>"
                            data-search="<?= h(strtolower($t['marque'] . ' ' . $t['modele'] . ' ' . $t['couleur'])) ?>">
                            <td data-th="Couleur" title="<?= h($t['couleur']) ?>"><?= h($t['couleur']) ?></td>
                            <td data-th="Modèle" title="<?= h($t['modele']) ?>"><?= h($t['modele']) ?></td>
                            <td data-th="Qté" class="td-metric <?= (int)$t['qty'] === 0 ? 'is-zero' : '' ?>"><?= (int)$t['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($toners)): ?>
                        <tr><td colspan="3">— Aucun toner —</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- Papier (depuis BDD) -->
        <section class="card-section" data-section="papier">
            <div class="section-head">
                <div class="head-left">
                    <img src="<?= h($sectionImages['papier']) ?>" class="section-icon" alt="Papier" loading="lazy" onerror="this.style.display='none'">
                    <h3 class="section-title">Papier</h3>
                </div>
                <div class="head-right">
                    <button type="button" class="btn btn-primary btn-sm btn-add" data-add-type="papier">
                        + Ajouter papier
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock tbl-compact click-rows" data-section="papier">
                    <colgroup>
                        <col class="col-qty"><col class="col-modele"><col class="col-poids">
                    </colgroup>
                    <thead><tr><th>Qté</th><th>Modèle</th><th>Poids</th></tr></thead>
                    <tbody>
                    <?php foreach ($papers as $p): ?>
                        <?php if (!empty($p['paper_id'])): ?>
                        <tr 
                            data-type="papier" 
                            data-id="<?= h((string)$p['paper_id']) ?>"
                            data-search="<?= h(strtolower(($p['marque'] ?? '') . ' ' . ($p['modele'] ?? '') . ' ' . ($p['poids'] ?? ''))) ?>">
                            <td data-th="Qté" class="td-metric"><?= (int)($p['qty_stock'] ?? 0) ?></td>
                            <td data-th="Modèle" title="<?= h($p['modele'] ?? '—') ?>"><?= h($p['modele'] ?? '—') ?></td>
                            <td data-th="Poids" title="<?= h($p['poids'] ?? '—') ?>"><?= h($p['poids'] ?? '—') ?></td>
                        </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (empty($papers)): ?>
                        <tr><td colspan="3">— Aucun papier —</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- LCD -->
        <section class="card-section" data-section="lcd">
            <div class="section-head">
                <div class="head-left">
                    <img src="<?= h($sectionImages['lcd']) ?>" class="section-icon" alt="Écrans LCD" loading="lazy" onerror="this.style.display='none'">
                    <h3 class="section-title">LCD</h3>
                </div>
                <div class="head-right">
                    <button type="button" class="btn btn-primary btn-sm btn-add" data-add-type="lcd">
                        + Ajouter LCD
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock tbl-compact click-rows" data-section="lcd">
                    <colgroup>
                        <col class="col-etat"><col class="col-modele"><col class="col-qty">
                    </colgroup>
                    <thead><tr><th>État</th><th>Modèle</th><th>Qté</th></tr></thead>
                    <tbody>
                    <?php foreach ($lcd as $row): ?>
                        <tr
                            data-type="lcd" 
                            data-id="<?= h((string)$row['id']) ?>"
                            data-search="<?= h(strtolower($row['modele'] . ' ' . $row['reference'] . ' ' . $row['marque'] . ' ' . $row['resolution'] . ' ' . $row['connectique'])) ?>">
                            <td data-th="État"><?= stateBadge($row['etat']) ?></td>
                            <td data-th="Modèle" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                            <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($lcd)): ?>
                        <tr><td colspan="3">— Aucun LCD —</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <!-- PC -->
        <section class="card-section" data-section="pc">
            <div class="section-head">
                <div class="head-left">
                    <img src="<?= h($sectionImages['pc']) ?>" class="section-icon" alt="PC reconditionnés" loading="lazy" onerror="this.style.display='none'">
                    <h3 class="section-title">PC reconditionnés</h3>
                </div>
                <div class="head-right">
                    <button type="button" class="btn btn-primary btn-sm btn-add" data-add-type="pc">
                        + Ajouter PC
                    </button>
                </div>
            </div>
            <div class="table-wrapper">
                <table class="tbl-stock tbl-compact click-rows" data-section="pc">
                    <colgroup>
                        <col class="col-etat"><col class="col-modele"><col class="col-qty">
                    </colgroup>
                    <thead><tr><th>État</th><th>Modèle</th><th>Qté</th></tr></thead>
                    <tbody>
                    <?php foreach ($pc as $row): ?>
                        <tr
                            data-type="pc" 
                            data-id="<?= h((string)$row['id']) ?>"
                            data-search="<?= h(strtolower($row['modele'] . ' ' . $row['reference'] . ' ' . $row['marque'] . ' ' . $row['cpu'] . ' ' . $row['os'] . ' ' . $row['ram'] . ' ' . $row['stockage'])) ?>">
                            <td data-th="État"><?= stateBadge($row['etat']) ?></td>
                            <td data-th="Modèle" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
                            <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($pc)): ?>
                        <tr><td colspan="3">— Aucun PC —</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div><!-- /#stockMasonry -->
</div><!-- /.page-container -->

<!-- ===== Modale détails (Photocopieurs / LCD / PC) ===== -->
<div id="detailOverlay" class="modal-overlay" aria-hidden="true"></div>
<div id="detailModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" style="display:none;">
    <div class="modal-header">
        <h3 id="modalTitle">Détails</h3>
        <button type="button" id="modalClose" class="icon-btn icon-btn--close" aria-label="Fermer">×</button>
    </div>
    <div class="modal-body">
        <div class="detail-grid" id="detailGrid"></div>
    </div>
</div>

<!-- ===== Modale ajout produit ===== -->
<div id="addOverlay" class="modal-overlay" aria-hidden="true"></div>
<div id="addModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="addModalTitle" style="display:none;">
    <div class="modal-header">
        <h3 id="addModalTitle">Ajouter</h3>
        <button type="button" id="addModalClose" class="icon-btn icon-btn--close" aria-label="Fermer">×</button>
    </div>
    <div class="modal-body">
        <form id="addForm">
            <div id="addFields" class="detail-grid"></div>
            <div class="modal-actions" style="margin-top:1rem; display:flex; gap:.5rem; justify-content:flex-end;">
                <button type="button" id="addCancel" class="btn btn-secondary">Annuler</button>
                <button type="submit" class="btn btn-primary">Enregistrer</button>
            </div>
            <div id="addError" class="form-error" style="color:#c00; margin-top:.5rem; display:none;"></div>
        </form>
    </div>
</div>

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
</script>
</body>
</html>
