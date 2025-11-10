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

/* =========================================================
   PHOTOCOPIEURS — UNIQUEMENT NON ATTRIBUÉS (depuis BDD)
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
   PAPIER — depuis la BDD (vue v_paper_stock)
   ========================================================= */
$papers = [];
try {
  $stmt = $pdo->query("SELECT paper_id, marque, modele, poids, qty_stock FROM v_paper_stock ORDER BY marque, modele, poids");
  $papers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('stock papier SQL: '.$e->getMessage());
  $papers = [];
}

/* =========================================================
   AUTRES CATEGORIES (mock)
   ========================================================= */
$lcd = [
  ['id'=>'lcd-24a-001','marque'=>'Dell','reference'=>'LCD-24A-001','etat'=>'A','modele'=>'U2415','taille'=>24,'resolution'=>'1920x1200','connectique'=>'HDMI/DP','prix'=>129.90,'qty'=>12],
  ['id'=>'lcd-27b-004','marque'=>'LG','reference'=>'LCD-27B-004','etat'=>'B','modele'=>'27UL500','taille'=>27,'resolution'=>'3840x2160','connectique'=>'HDMI','prix'=>219.90,'qty'=>4],
  ['id'=>'lcd-22c-020','marque'=>'HP','reference'=>'LCD-22C-020','etat'=>'C','modele'=>'Z22n G2','taille'=>22,'resolution'=>'1920x1080','connectique'=>'DP','prix'=>79.90,'qty'=>6],
];

$pc = [
  ['id'=>'pc-a-001','etat'=>'A','reference'=>'PC-A-001','marque'=>'Lenovo','modele'=>'ThinkCentre M720','cpu'=>'i5-9500','ram'=>'16 Go','stockage'=>'512 Go SSD','os'=>'Windows 11 Pro','gpu'=>'Intel UHD 630','reseau'=>'Gigabit','ports'=>'USB 3.0 x6','prix'=>349.00,'qty'=>5],
  ['id'=>'pc-b-010','etat'=>'B','reference'=>'PC-B-010','marque'=>'Dell','modele'=>'OptiPlex 7060','cpu'=>'i5-8500','ram'=>'8 Go','stockage'=>'256 Go SSD','os'=>'Windows 10 Pro','gpu'=>'Intel UHD 630','reseau'=>'Gigabit','ports'=>'USB 3.0 x6','prix'=>279.00,'qty'=>10],
  ['id'=>'pc-c-015','etat'=>'C','reference'=>'PC-C-015','marque'=>'Lenovo','modele'=>'ThinkPad T460','cpu'=>'i5-6300U','ram'=>'8 Go','stockage'=>'240 Go SSD','os'=>'Windows 10 Pro','gpu'=>'Intel HD 520','reseau'=>'Wi-Fi/BT','ports'=>'USB 3.0 x3','prix'=>189.00,'qty'=>7],
];

$toners = [
  ['id'=>'tn-k-2553','marque'=>'Kyocera','modele'=>'TK-8345K','couleur'=>'Noir','qty'=>14],
  ['id'=>'tn-c-2553','marque'=>'Kyocera','modele'=>'TK-8345C','couleur'=>'Cyan','qty'=>6],
  ['id'=>'tn-m-307','marque'=>'Ricoh','modele'=>'MPC307-M','couleur'=>'Magenta','qty'=>3],
  ['id'=>'tn-y-307','marque'=>'Ricoh','modele'=>'MPC307-Y','couleur'=>'Jaune','qty'=>0],
];

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
  <title>Stock - CCComputer</title>

  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/stock.css" />
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
  <div class="page-header">
    <h2 class="page-title">Stock</h2>
    <p class="page-subtitle">Disposition <strong>dynamique</strong> — la section la plus remplie s’affiche en premier.</p>
  </div>

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

    <!-- Photocopieurs (non attribués, BDD) -->
    <section class="card-section" data-section="photocopieurs">
      <div class="section-head">
        <div class="head-left">
          <img src="<?= h($sectionImages['photocopieurs']) ?>" class="section-icon" alt="Photocopieurs" loading="lazy" onerror="this.style.display='none'">
          <h3 class="section-title">Photocopieurs</h3>
        </div>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact click-rows">
          <colgroup>
            <col class="col-modele"><col class="col-sn"><col class="col-statut">
          </colgroup>
          <thead><tr><th>Modèle</th><th>N° Série</th><th>Statut</th></tr></thead>
          <tbody>
          <?php foreach ($copiers as $r): ?>
            <tr
              data-type="copiers" data-id="<?= h($r['id']) ?>"
              data-search="<?= h(strtolower(($r['modele']??'').' '.($r['sn']??'').' '.($r['marque']??'').' '.($r['mac']??'').' '.($r['statut']??'').' '.($r['emplacement']??''))) ?>">
              <td data-th="Modèle"  title="<?= h($r['modele']) ?>"><strong><?= h($r['modele']) ?></strong></td>
              <td data-th="N° Série" title="<?= h($r['sn']) ?>"><?= h($r['sn']) ?></td>
              <td data-th="Statut"><span class="chip"><?= h($r['statut']) ?></span></td>
            </tr>
          <?php endforeach; if (empty($copiers)): ?>
            <tr><td colspan="3">— Aucun photocopieur non attribué —</td></tr>
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

<script>
/* ===== Filtre + réordonnancement ===== */
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
  function applyFilter(){
    const v = norm(q.value||'');
    document.querySelectorAll('.tbl-stock tbody tr').forEach(tr=>{
      const t = norm(tr.getAttribute('data-search')||'');
      tr.style.display = !v || t.includes(v) ? '' : 'none';
    });
    reorderSections();
  }
  q && q.addEventListener('input', applyFilter);
  reorderSections();
  if ('ResizeObserver' in window){
    const ro = new ResizeObserver(()=> reorderSections());
    mason.querySelectorAll('.card-section').forEach(sec => ro.observe(sec));
  }
})();

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
</script>
</body>
</html>
