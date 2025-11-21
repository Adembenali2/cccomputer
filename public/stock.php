<?php
// /public/stock.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

try { $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (\Throwable $e) {}

/* Helpers */
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function stateBadge(?string $etat): string {
  $e = strtoupper(trim((string)$etat));
  if (!in_array($e, ['A','B','C'], true)) return '<span class="state state-na">—</span>';
  return '<span class="state state-'.$e.'">'.$e.'</span>';
}

// Helper pour requêtes sécurisées
function safeFetchAll(PDO $pdo, string $sql, array $params = [], string $context = 'query'): array {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
    } catch (PDOException $e) {
        error_log("Erreur SQL ({$context}) : " . $e->getMessage());
        return [];
    }
}

// Gestion des messages flash
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// Message de succès depuis paramètre GET
if (isset($_GET['added']) && in_array($_GET['added'], ['papier', 'toner', 'lcd', 'pc'], true)) {
    $typeName = ['papier' => 'papier', 'toner' => 'toner', 'lcd' => 'LCD', 'pc' => 'PC'][$_GET['added']];
    $flash = ['type' => 'success', 'msg' => ucfirst($typeName) . ' ajouté avec succès dans le stock.'];
}

/* =========================================================
   PHOTOCOPIEURS — non attribués (chargés mais plus affichés)
   ========================================================= */
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
      v.`Timestamp`     AS last_ts,
      v.TotalBW,
      v.TotalColor,
      v.Status          AS raw_status
    FROM v_compteur_last v
    LEFT JOIN photocopieurs_clients pc
      ON pc.mac_norm = v.mac_norm
    WHERE v.rn = 1
      AND pc.id_client IS NULL
    ORDER BY
      v.Model IS NULL, v.Model,
      v.SerialNumber IS NULL, v.SerialNumber,
      v.MacAddress
  ";
  $stmt = $pdo->query($sql);
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

  foreach ($rows as $r) {
    $model   = trim($r['Model'] ?? '');
    $parts   = preg_split('/\s+/', $model);
    $marque  = ($parts && $parts[0] !== '') ? $parts[0] : '—';

    $raw     = strtoupper(trim((string)($r['raw_status'] ?? '')));
    $okVals  = ['OK','ONLINE','NORMAL','READY','PRINT','IDLE','STANDBY','SLEEP','AVAILABLE'];
    $isDown  = ($raw !== '' && !in_array($raw, $okVals, true));

    $statut  = $isDown ? 'en panne' : 'stock';
    $empl    = 'dépôt';

    $lastTs = null;
    if (!empty($r['last_ts'])) {
      try { $lastTs = (new DateTime($r['last_ts']))->format('Y-m-d H:i:s'); } catch (\Throwable $e) { $lastTs = (string)$r['last_ts']; }
    }

    $copiers[] = [
      'id'              => $r['mac_norm'],
      'mac'             => $r['MacAddress'] ?: '',
      'marque'          => $marque,
      'modele'          => $model ?: ($r['Nom'] ?: '—'),
      'sn'              => $r['SerialNumber'] ?: '—',
      'compteur_bw'     => is_numeric($r['TotalBW'])    ? (int)$r['TotalBW']    : null,
      'compteur_color'  => is_numeric($r['TotalColor']) ? (int)$r['TotalColor'] : null,
      'statut'          => $statut,
      'emplacement'     => $empl,
      'last_ts'         => $lastTs,
    ];
  }
} catch (PDOException $e) {
  error_log('stock.php (photocopieurs non attribués) SQL error: '.$e->getMessage());
  $copiers = [];
}

/* =========================================================
   PAPIER — depuis v_paper_stock
   ========================================================= */
$papers = safeFetchAll(
    $pdo,
    "SELECT paper_id, marque, modele, poids, qty_stock FROM v_paper_stock ORDER BY marque, modele, poids",
    [],
    'stock_papier'
);

/* =========================================================
   TONERS — depuis v_toner_stock
   ========================================================= */
$tonersRaw = safeFetchAll(
    $pdo,
    "SELECT toner_id, marque, modele, couleur, qty_stock FROM v_toner_stock ORDER BY marque, modele, couleur",
    [],
    'stock_toner'
);
$toners = [];
foreach ($tonersRaw as $r) {
    $toners[] = [
        'id'      => (int)$r['toner_id'],
        'marque'  => $r['marque'],
        'modele'  => $r['modele'],
        'couleur' => $r['couleur'],
        'qty'     => (int)$r['qty_stock'],
    ];
}

/* =========================================================
   LCD — depuis v_lcd_stock
   ========================================================= */
$lcdRaw = safeFetchAll(
    $pdo,
    "SELECT lcd_id, marque, reference, etat, modele, taille, resolution, connectique, prix, qty_stock FROM v_lcd_stock ORDER BY marque, modele, taille",
    [],
    'stock_lcd'
);
$lcd = [];
foreach ($lcdRaw as $r) {
    $lcd[] = [
        'id'         => (int)$r['lcd_id'],
        'marque'     => $r['marque'],
        'reference'  => $r['reference'],
        'etat'       => $r['etat'],
        'modele'     => $r['modele'],
        'taille'     => (int)$r['taille'],
        'resolution' => $r['resolution'],
        'connectique'=> $r['connectique'],
        'prix'       => $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty'        => (int)$r['qty_stock'],
    ];
}

/* =========================================================
   PC — depuis v_pc_stock
   ========================================================= */
$pcRaw = safeFetchAll(
    $pdo,
    "SELECT pc_id, etat, reference, marque, modele, cpu, ram, stockage, os, gpu, reseau, ports, prix, qty_stock FROM v_pc_stock ORDER BY marque, modele, reference",
    [],
    'stock_pc'
);
$pc = [];
foreach ($pcRaw as $r) {
    $pc[] = [
        'id'        => (int)$r['pc_id'],
        'etat'      => $r['etat'],
        'reference' => $r['reference'],
        'marque'    => $r['marque'],
        'modele'    => $r['modele'],
        'cpu'       => $r['cpu'],
        'ram'       => $r['ram'],
        'stockage'  => $r['stockage'],
        'os'        => $r['os'],
        'gpu'       => $r['gpu'],
        'reseau'    => $r['reseau'],
        'ports'     => $r['ports'],
        'prix'      => $r['prix'] !== null ? (float)$r['prix'] : null,
        'qty'       => (int)$r['qty_stock'],
    ];
}

// Calcul des statistiques
$totalPapier = array_sum(array_map(fn($p) => (int)($p['qty_stock'] ?? 0), $papers));
$totalToners = array_sum(array_map(fn($t) => (int)($t['qty'] ?? 0), $toners));
$totalLCD = array_sum(array_map(fn($l) => (int)($l['qty'] ?? 0), $lcd));
$totalPC = array_sum(array_map(fn($p) => (int)($p['qty'] ?? 0), $pc));

$stockFaible = [
    'papier' => array_filter($papers, fn($p) => (int)($p['qty_stock'] ?? 0) <= 5),
    'toners' => array_filter($toners, fn($t) => (int)($t['qty'] ?? 0) <= 3),
    'lcd'    => array_filter($lcd, fn($l) => (int)($l['qty'] ?? 0) <= 2),
    'pc'     => array_filter($pc, fn($p) => (int)($p['qty'] ?? 0) <= 2),
];
$nbStockFaible = count($stockFaible['papier']) + count($stockFaible['toners']) + count($stockFaible['lcd']) + count($stockFaible['pc']);

$datasets = ['copiers'=>$copiers, 'lcd'=>$lcd, 'pc'=>$pc];

$sectionImages = [
  'photocopieurs' => '/assets/img/stock/photocopieurs.jpg',
  'lcd'           => '/assets/img/stock/lcd.jpg',
  'pc'            => '/assets/img/stock/pc.jpg',
  'toners'        => '/assets/img/stock/toners.jpg',
  'papier'        => '/assets/img/stock/papier.jpg',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
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
    <div class="flash <?= h($flash['type']) ?>" role="alert" style="margin-bottom: 1rem; padding: .75rem 1rem; border-radius: 8px; background: <?= $flash['type']==='success'?'#dcfce7':'#fee2e2' ?>; color: <?= $flash['type']==='success'?'#16a34a':'#dc2626' ?>; border: 1px solid <?= $flash['type']==='success'?'#86efac':'#fecaca' ?>;">
      <?= h($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <section class="stock-meta" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: .75rem; margin-bottom: 1rem;">
    <div class="meta-card" style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; text-align: center;">
      <span style="display: block; font-size: .875rem; color: #6b7280; margin-bottom: .25rem;">Total Papier</span>
      <strong style="display: block; font-size: 1.5rem; font-weight: 700; color: #111827;"><?= h((string)$totalPapier) ?></strong>
    </div>
    <div class="meta-card" style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; text-align: center;">
      <span style="display: block; font-size: .875rem; color: #6b7280; margin-bottom: .25rem;">Total Toners</span>
      <strong style="display: block; font-size: 1.5rem; font-weight: 700; color: #111827;"><?= h((string)$totalToners) ?></strong>
    </div>
    <div class="meta-card" style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; text-align: center;">
      <span style="display: block; font-size: .875rem; color: #6b7280; margin-bottom: .25rem;">Total LCD</span>
      <strong style="display: block; font-size: 1.5rem; font-weight: 700; color: #111827;"><?= h((string)$totalLCD) ?></strong>
    </div>
    <div class="meta-card" style="background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: .75rem; text-align: center;">
      <span style="display: block; font-size: .875rem; color: #6b7280; margin-bottom: .25rem;">Total PC</span>
      <strong style="display: block; font-size: 1.5rem; font-weight: 700; color: #111827;"><?= h((string)$totalPC) ?></strong>
    </div>
    <?php if ($nbStockFaible > 0): ?>
      <div class="meta-card" style="background: #fef3c7; border: 1px solid #fbbf24; border-radius: 8px; padding: .75rem; text-align: center;">
        <span style="display: block; font-size: .875rem; color: #92400e; margin-bottom: .25rem;">⚠ Stock faible</span>
        <strong style="display: block; font-size: 1.5rem; font-weight: 700; color: #78350f;"><?= h((string)$nbStockFaible) ?></strong>
      </div>
    <?php endif; ?>
  </section>

  <div class="filters-row">
    <input type="text" id="q" placeholder="Filtrer partout (réf., modèle, SN, MAC, CPU…)" aria-label="Filtrer" />
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
        <table class="tbl-stock tbl-compact">
          <colgroup>
            <col class="col-couleur"><col class="col-modele"><col class="col-qty">
          </colgroup>
          <thead><tr><th>Couleur</th><th>Modèle</th><th>Qté</th></tr></thead>
          <tbody>
          <?php foreach ($toners as $t): ?>
            <tr data-search="<?= h(strtolower($t['marque'].' '.$t['modele'].' '.$t['couleur'])) ?>">
              <td data-th="Couleur" title="<?= h($t['couleur']) ?>"><?= h($t['couleur']) ?></td>
              <td data-th="Modèle"  title="<?= h($t['modele']) ?>"><?= h($t['modele']) ?></td>
              <td data-th="Qté" class="td-metric <?= (int)$t['qty']===0?'is-zero':'' ?>"><?= (int)$t['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($toners)): ?>
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
        <table class="tbl-stock tbl-compact">
          <colgroup>
            <col class="col-qty"><col class="col-modele"><col class="col-poids">
          </colgroup>
          <thead><tr><th>Qté</th><th>Modèle</th><th>Poids</th></tr></thead>
          <tbody>
          <?php foreach ($papers as $p): ?>
            <tr data-search="<?= h(strtolower(($p['marque']??'').' '.($p['modele']??'').' '.($p['poids']??''))) ?>">
              <td data-th="Qté"     class="td-metric"><?= (int)($p['qty_stock'] ?? 0) ?></td>
              <td data-th="Modèle"  title="<?= h($p['modele'] ?? '—') ?>"><?= h($p['modele'] ?? '—') ?></td>
              <td data-th="Poids"   title="<?= h($p['poids'] ?? '—') ?>"><?= h($p['poids'] ?? '—') ?></td>
            </tr>
          <?php endforeach; if (empty($papers)): ?>
            <tr><td colspan="3">— Aucun papier —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Photocopieurs : section supprimée (plus d'affichage) -->

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
        <table class="tbl-stock tbl-compact click-rows">
          <colgroup>
            <col class="col-etat"><col class="col-modele"><col class="col-qty">
          </colgroup>
          <thead><tr><th>État</th><th>Modèle</th><th>Qté</th></tr></thead>
          <tbody>
          <?php foreach ($lcd as $row): ?>
            <tr
              data-type="lcd" data-id="<?= h($row['id']) ?>"
              data-search="<?= h(strtolower($row['modele'].' '.$row['reference'].' '.$row['marque'].' '.$row['resolution'].' '.$row['connectique'])) ?>">
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
              <td data-th="Modèle" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
              <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($lcd)): ?>
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
        <table class="tbl-stock tbl-compact click-rows">
          <colgroup>
            <col class="col-etat"><col class="col-modele"><col class="col-qty">
          </colgroup>
          <thead><tr><th>État</th><th>Modèle</th><th>Qté</th></tr></thead>
          <tbody>
          <?php foreach ($pc as $row): ?>
            <tr
              data-type="pc" data-id="<?= h($row['id']) ?>"
              data-search="<?= h(strtolower($row['modele'].' '.$row['reference'].' '.$row['marque'].' '.$row['cpu'].' '.$row['os'].' '.$row['ram'].' '.$row['stockage'])) ?>">
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
              <td data-th="Modèle" title="<?= h($row['modele']) ?>"><strong><?= h($row['modele']) ?></strong></td>
              <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($pc)): ?>
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
(function(){
  function initStockScripts() {
    initFilter();
    initDetailModal();
    initAddModal();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initStockScripts);
  } else {
    initStockScripts();
  }

/* ===== Filtre + réordonnancement ===== */
function initFilter(){
(function(){
  const q = document.getElementById('q');
  const mason = document.getElementById('stockMasonry');

  function visibleRowCount(section){
    const rows = section.querySelectorAll('tbody tr');
    let n = 0; rows.forEach(r => { if (r.style.display !== 'none') n++; });
    return n;
  }
  function reorderSections(){
    const sections = Array.from(mason.querySelectorAll('.card-section'));
    const scored = sections.map((s, i)=>({el:s, score: visibleRowCount(s), idx:i}));
    scored.sort((a,b)=> b.score - a.score || a.idx - b.idx);
    scored.forEach(x => mason.appendChild(x.el));
  }
  function norm(s){
    return (s||'').toString().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  }
  let filterTimeout = null;
  function applyFilter(){
    const v = norm(q.value||'');
    document.querySelectorAll('.tbl-stock tbody tr').forEach(tr=>{
      const t = norm(tr.getAttribute('data-search')||'');
      tr.style.display = !v || t.includes(v) ? '' : 'none';
    });
    reorderSections();
  }
  // Debounce pour améliorer les performances
  q && q.addEventListener('input', function(){
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(applyFilter, 200);
  });
  reorderSections();
  if ('ResizeObserver' in window){
    const ro = new ResizeObserver(()=> reorderSections());
    mason.querySelectorAll('.card-section').forEach(sec => ro.observe(sec));
  }
})();
}

/* ===== Datasets popup ===== */
const DATASETS = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

/* Helpers sûrs (XSS) */
function escapeText(s){ return (s == null) ? '—' : String(s); }
function addField(grid, label, value, {html=false}={}){
  const card = document.createElement('div');
  card.className = 'field-card';
  const lbl = document.createElement('div'); lbl.className = 'lbl'; lbl.textContent = label;
  const val = document.createElement('div'); val.className = 'val';
  if (html) val.innerHTML = value ?? '—'; else val.textContent = escapeText(value);
  card.appendChild(lbl); card.appendChild(val); grid.appendChild(card);
}
function badgeEtat(e){
  e = String(e||'').toUpperCase();
  if (!['A','B','C'].includes(e)) return '<span class="state state-na">—</span>';
  return `<span class="state state-${e}">${e}</span>`;
}

/* ===== Modal détails ===== */
function initDetailModal(){
(function(){
  const overlay = document.getElementById('detailOverlay');
  const modal   = document.getElementById('detailModal');
  const close   = document.getElementById('modalClose');
  const grid    = document.getElementById('detailGrid');
  const titleEl = document.getElementById('modalTitle');

  let lastFocused = null;
  function focusFirst(){
    const f = modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
    if (f.length) f[0].focus();
  }
  function trapFocus(e){
    if (e.key !== 'Tab') return;
    const f = [...modal.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])')];
    if (!f.length) return;
    const first = f[0], last = f[f.length-1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  function onKeydown(e){ if (e.key === 'Escape') closeFn(); if (e.key === 'Tab') trapFocus(e); }
  function open(){
    lastFocused = document.activeElement;
    document.body.classList.add('modal-open');
    overlay.setAttribute('aria-hidden','false');
    overlay.style.display='block'; modal.style.display='block';
    document.addEventListener('keydown', onKeydown); focusFirst();
  }
  function closeFn(){
    document.body.classList.remove('modal-open');
    overlay.setAttribute('aria-hidden','true');
    overlay.style.display='none'; modal.style.display='none';
    document.removeEventListener('keydown', onKeydown);
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }
  close.addEventListener('click', closeFn);
  overlay.addEventListener('click', closeFn);

  function renderDetails(type, row){
    grid.innerHTML = '';
    titleEl.textContent = `${row.modele ?? row.reference ?? 'Détails'} — ${type.toUpperCase()}`;
    if (type === 'copiers') {
      addField(grid, 'Marque', row.marque);
      addField(grid, 'Modèle', row.modele);
      addField(grid, 'N° Série', row.sn);
      addField(grid, 'Adresse MAC', row.mac);
      addField(grid, 'Compteur N&B', new Intl.NumberFormat('fr-FR').format(row.compteur_bw||0));
      addField(grid, 'Compteur Couleur', new Intl.NumberFormat('fr-FR').format(row.compteur_color||0));
      addField(grid, 'Statut', row.statut);
      addField(grid, 'Emplacement', row.emplacement);
      if (row.last_ts) addField(grid, 'Dernière relève', row.last_ts);
    } else if (type === 'lcd') {
      addField(grid, 'État', badgeEtat(row.etat), {html:true});
      addField(grid, 'Référence', row.reference);
      addField(grid, 'Marque', row.marque);
      addField(grid, 'Modèle', row.modele);
      addField(grid, 'Taille', (row.taille?row.taille+'"':'—'));
      addField(grid, 'Résolution', row.resolution);
      addField(grid, 'Connectique', row.connectique);
      addField(grid, 'Prix', row.prix!=null ? new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(row.prix) : '—');
      addField(grid, 'Quantité', row.qty);
    } else if (type === 'pc') {
      addField(grid, 'État', badgeEtat(row.etat), {html:true});
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
      addField(grid, 'Prix', row.prix!=null ? new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(row.prix) : '—');
      addField(grid, 'Quantité', row.qty);
    }
  }

  document.querySelectorAll('.click-rows tbody tr[data-type][data-id]').forEach(tr=>{
    tr.style.cursor = 'pointer';
    tr.tabIndex = 0;
    tr.addEventListener('click', ()=>{
      const type = tr.getAttribute('data-type');
      const id   = tr.getAttribute('data-id');
      const rows = (DATASETS[type]||[]);
      const row  = rows.find(r=>String(r.id)===String(id));
      if (!row) return;
      renderDetails(type, row); open();
    });
    tr.addEventListener('keydown', (e)=>{
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tr.click(); }
    });
  });
})();
}

/* ===== Modale ajout produit (papier / toner / lcd / pc) ===== */
function initAddModal(){
(function(){
  const overlay = document.getElementById('addOverlay');
  const modal   = document.getElementById('addModal');
  const titleEl = document.getElementById('addModalTitle');
  const btnClose= document.getElementById('addModalClose');
  const btnCancel = document.getElementById('addCancel');
  const form    = document.getElementById('addForm');
  const fieldsContainer = document.getElementById('addFields');
  const errorBox = document.getElementById('addError');

  let currentType = null;

  const FORM_SCHEMAS = {
    toner: [
      {name:'marque',    label:'Marque',      type:'text',   required:true},
      {name:'modele',    label:'Modèle',      type:'text',   required:true},
      {name:'couleur',   label:'Couleur',     type:'text',   required:true},
      {name:'qty_delta', label:'Quantité',    type:'number', required:true, min:1},
      {name:'reference', label:'Référence (BL, facture…)', type:'text'}
    ],
    papier: [
      {name:'marque',    label:'Marque',      type:'text',   required:true},
      {name:'modele',    label:'Modèle',      type:'text',   required:true},
      {name:'poids',     label:'Poids',       type:'text',   required:true},
      {name:'qty_delta', label:'Quantité',    type:'number', required:true, min:1},
      {name:'reference', label:'Référence (BL, facture…)', type:'text'}
    ],
    lcd: [
      {name:'marque',     label:'Marque',        type:'text',   required:true},
      {name:'reference',  label:'Référence',     type:'text',   required:true},
      {name:'etat',       label:'État (A/B/C)',  type:'text',   required:true, maxLength:1},
      {name:'modele',     label:'Modèle',        type:'text',   required:true},
      {name:'taille',     label:'Taille (pouces)', type:'number', required:true, min:10},
      {name:'resolution', label:'Résolution',    type:'text',   required:true},
      {name:'connectique',label:'Connectique',   type:'text',   required:true},
      {name:'prix',       label:'Prix (EUR)',    type:'number', step:'0.01'},
      {name:'qty_delta',  label:'Quantité',      type:'number', required:true, min:1},
      {name:'reference_move', label:'Référence mouvement (BL, facture…)', type:'text'}
    ],
    pc: [
      {name:'etat',        label:'État (A/B/C)', type:'text',   required:true, maxLength:1},
      {name:'reference',   label:'Référence',    type:'text',   required:true},
      {name:'marque',      label:'Marque',       type:'text',   required:true},
      {name:'modele',      label:'Modèle',       type:'text',   required:true},
      {name:'cpu',         label:'CPU',          type:'text',   required:true},
      {name:'ram',         label:'RAM',          type:'text',   required:true},
      {name:'stockage',    label:'Stockage',     type:'text',   required:true},
      {name:'os',          label:'OS',           type:'text',   required:true},
      {name:'gpu',         label:'GPU',          type:'text'},
      {name:'reseau',      label:'Réseau',       type:'text'},
      {name:'ports',       label:'Ports',        type:'text'},
      {name:'prix',        label:'Prix (EUR)',   type:'number', step:'0.01'},
      {name:'qty_delta',   label:'Quantité',     type:'number', required:true, min:1},
      {name:'reference_move', label:'Référence mouvement (BL, facture…)', type:'text'}
    ]
  };

  function clearForm(){
    fieldsContainer.innerHTML = '';
    errorBox.style.display = 'none';
    errorBox.textContent = '';
  }

  function buildForm(type){
    clearForm();
    const schema = FORM_SCHEMAS[type];
    if (!schema) return;
    schema.forEach(f => {
      const wrapper = document.createElement('div');
      wrapper.className = 'field-card';

      const lbl = document.createElement('label');
      lbl.className = 'lbl';
      lbl.textContent = f.label;
      lbl.htmlFor = 'add_'+f.name;

      const input = document.createElement('input');
      input.className = 'val';
      input.id = 'add_'+f.name;
      input.name = f.name;
      input.type = f.type || 'text';
      if (f.required) input.required = true;
      if (f.min != null) input.min = f.min;
      if (f.step != null) input.step = f.step;
      if (f.maxLength != null) input.maxLength = f.maxLength;

      wrapper.appendChild(lbl);
      wrapper.appendChild(input);
      fieldsContainer.appendChild(wrapper);
    });
  }

  function openModal(type){
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
    titleEl.textContent = 'Ajouter ' + (typeNames[type] || type);
    buildForm(type);
    
    // Focus sur le premier champ du formulaire après un court délai
    setTimeout(() => {
      const firstInput = fieldsContainer.querySelector('input, select, textarea');
      if (firstInput) {
        firstInput.focus();
      }
    }, 100);
    
    document.body.classList.add('modal-open');
    overlay.style.display = 'block';
    overlay.setAttribute('aria-hidden','false');
    modal.style.display = 'block';
  }

  function closeModal(){
    document.body.classList.remove('modal-open');
    overlay.style.display = 'none';
    overlay.setAttribute('aria-hidden','true');
    modal.style.display = 'none';
    currentType = null;
    clearForm();
  }

  // Vérifier que tous les éléments nécessaires existent
  if (!overlay || !modal || !titleEl || !fieldsContainer || !form || !errorBox) {
    console.error('Éléments DOM manquants pour la modale d\'ajout:', {
      overlay: !!overlay,
      modal: !!modal,
      titleEl: !!titleEl,
      fieldsContainer: !!fieldsContainer,
      form: !!form,
      errorBox: !!errorBox
    });
    return;
  }

  btnClose && btnClose.addEventListener('click', closeModal);
  btnCancel && btnCancel.addEventListener('click', function(e){ e.preventDefault(); closeModal(); });
  overlay && overlay.addEventListener('click', closeModal);

  // Attacher les event listeners aux boutons d'ajout
  // Utiliser delegation d'evenement pour capturer les clics même si les boutons sont ajoutés dynamiquement
  document.addEventListener('click', function(e) {
    const btn = e.target.closest('.btn-add[data-add-type]');
    if (btn) {
      e.preventDefault();
      e.stopPropagation();
      const t = btn.getAttribute('data-add-type');
      if (t) {
        console.log('Ouverture modale pour type:', t);
        openModal(t);
      } else {
        console.error('Attribut data-add-type manquant sur le bouton');
      }
    }
  });

  // Également attacher directement pour une meilleure compatibilité
  const addButtons = document.querySelectorAll('.btn-add[data-add-type]');
  console.log('Boutons d\'ajout trouvés:', addButtons.length);
  addButtons.forEach(btn => {
    btn.addEventListener('click', function(e) {
      e.preventDefault();
      e.stopPropagation();
      const t = btn.getAttribute('data-add-type');
      if (t) {
        console.log('Ouverture modale pour type (direct):', t);
        openModal(t);
      } else {
        console.error('Attribut data-add-type manquant sur le bouton');
      }
    });
  });

  form && form.addEventListener('submit', async function(e){
    e.preventDefault();
    if (!currentType) return;

    const formData = new FormData(form);
    const payload = {};
    formData.forEach((v,k) => { payload[k] = v; });

    // Récupérer le token CSRF depuis le meta tag
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

    try {
      const res = await fetch('../API/stock_add.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
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
        console.error('Réponse non JSON de ../API/stock_add.php :', text);
        throw new Error('Réponse invalide du serveur (pas du JSON).');
      }

      if (!res.ok || !json.ok) {
        console.error('Erreur API :', json);
        errorBox.textContent = json.error || 'Erreur lors de l'enregistrement.';
        errorBox.style.display = 'block';
        return;
      }

      // Succès : afficher message et recharger
      closeModal();
      // Ajouter paramètre flash pour afficher message de succès
      const url = new URL(window.location.href);
      url.searchParams.set('added', currentType);
      window.location.href = url.toString();
    } catch (err) {
      console.error('Erreur fetch :', err);
      errorBox.textContent = 'Erreur réseau ou serveur.';
      errorBox.style.display = 'block';
    }
  });
})();
}
})(); // Fin de initStockScripts
</script>
</body>
</html>
