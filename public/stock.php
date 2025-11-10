<?php
// /public/stock.php
require_once __DIR__ . '/../includes/auth.php';
// Pas de DB ici : architecture & données factices uniquement

/** Helpers **/
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/** Données factices (à remplacer plus tard par la BDD) **/
// LCD regroupés par état A/B/C
$lcd = [
  'A' => [
    ['ref'=>'LCD-24A-001','marque'=>'Dell','modele'=>'U2415','taille'=>24,'resolution'=>'1920x1200','qty'=>12,'prix'=>129.90],
    ['ref'=>'LCD-27A-004','marque'=>'LG','modele'=>'27UL500','taille'=>27,'resolution'=>'3840x2160','qty'=>4,'prix'=>219.90],
  ],
  'B' => [
    ['ref'=>'LCD-22B-020','marque'=>'HP','modele'=>'Z22n G2','taille'=>22,'resolution'=>'1920x1080','qty'=>9,'prix'=>79.90],
  ],
  'C' => [
    ['ref'=>'LCD-19C-002','marque'=>'AOC','modele'=>'E970','taille'=>19,'resolution'=>'1280x1024','qty'=>6,'prix'=>39.90],
  ],
];

// PC reconditionnés, regroupés par état A/B/C
$pc = [
  'A' => [
    ['ref'=>'PC-A-001','marque'=>'Lenovo','modele'=>'ThinkCentre M720','cpu'=>'i5-9500','ram'=>'16 Go','stockage'=>'512 Go SSD','os'=>'Windows 11 Pro','qty'=>5,'prix'=>349.00],
    ['ref'=>'PC-A-003','marque'=>'HP','modele'=>'EliteDesk 800 G4','cpu'=>'i7-8700','ram'=>'16 Go','stockage'=>'512 Go SSD','os'=>'Windows 11 Pro','qty'=>3,'prix'=>399.00],
  ],
  'B' => [
    ['ref'=>'PC-B-010','marque'=>'Dell','modele'=>'OptiPlex 7060','cpu'=>'i5-8500','ram'=>'8 Go','stockage'=>'256 Go SSD','os'=>'Windows 10 Pro','qty'=>10,'prix'=>279.00],
  ],
  'C' => [
    ['ref'=>'PC-C-015','marque'=>'Lenovo','modele'=>'ThinkPad T460','cpu'=>'i5-6300U','ram'=>'8 Go','stockage'=>'240 Go SSD','os'=>'Windows 10 Pro','qty'=>7,'prix'=>189.00],
  ],
];

// Photocopieurs
$copiers = [
  ['ref'=>'COPSN-001','marque'=>'Kyocera','modele'=>'TASKalfa 2553ci','sn'=>'KYO2553-001','mac'=>'10:AA:22:BB:33:CC','compteur_bw'=>45213,'compteur_color'=>18322,'statut'=>'Stock','qty'=>2],
  ['ref'=>'COPSN-005','marque'=>'Ricoh','modele'=>'MP C307','sn'=>'RICOH307-005','mac'=>'00:25:96:FF:EE:11','compteur_bw'=>9812,'compteur_color'=>5230,'statut'=>'Réservé','qty'=>1],
];

// Toners
$toners = [
  ['ref'=>'TN-K-2553','marque'=>'Kyocera','modele'=>'TK-8345K','couleur'=>'Noir','compat'=>'TA 2553ci / 3253ci','qty'=>14],
  ['ref'=>'TN-C-2553','marque'=>'Kyocera','modele'=>'TK-8345C','couleur'=>'Cyan','compat'=>'TA 2553ci / 3253ci','qty'=>6],
  ['ref'=>'TN-M-307','marque'=>'Ricoh','modele'=>'MPC307-M','couleur'=>'Magenta','compat'=>'MP C307','qty'=>3],
  ['ref'=>'TN-Y-307','marque'=>'Ricoh','modele'=>'MPC307-Y','couleur'=>'Jaune','compat'=>'MP C307','qty'=>0],
];

// Papier
$papiers = [
  ['ref'=>'PAP-A4-80','type'=>'A4 80g','format'=>'210x297','couleur'=>'Blanc','qty'=>120],
  ['ref'=>'PAP-A3-90','type'=>'A3 90g','format'=>'297x420','couleur'=>'Blanc','qty'=>30],
  ['ref'=>'PAP-A4-RECYC','type'=>'A4 80g Recyclé','format'=>'210x297','couleur'=>'Blanc','qty'=>15],
];

/** Totaux rapides **/
function sumQty(array $rows): int { $t=0; foreach($rows as $r){ $t+=(int)($r['qty']??0);} return $t; }
$lcdTotals = ['A'=>sumQty($lcd['A']), 'B'=>sumQty($lcd['B']), 'C'=>sumQty($lcd['C'])];
$pcTotals  = ['A'=>sumQty($pc['A']),  'B'=>sumQty($pc['B']),  'C'=>sumQty($pc['C'])];
$copiersTotal = sumQty($copiers);
$tonersTotal  = sumQty($toners);
$papiersTotal = sumQty($papiers);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Stock - CCComputer</title>

  <!-- mêmes CSS de base que clients.php -->
  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/stock.css" />
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container"><!-- même conteneur que clients.php -->
  <div class="page-header">
    <h2 class="page-title">Stock</h2>
    <p class="page-subtitle">
      LCD A: <?= (int)$lcdTotals['A'] ?> · B: <?= (int)$lcdTotals['B'] ?> · C: <?= (int)$lcdTotals['C'] ?> —
      PC A: <?= (int)$pcTotals['A'] ?> · B: <?= (int)$pcTotals['B'] ?> · C: <?= (int)$pcTotals['C'] ?> —
      Photocopieurs: <?= (int)$copiersTotal ?> — Toners: <?= (int)$tonersTotal ?> — Papier: <?= (int)$papiersTotal ?>
    </p>
  </div>

  <!-- Barre de filtres + navigation (style calqué sur clients.php) -->
  <div class="filters-row">
    <input type="text" id="q" placeholder="Filtrer (réf., marque, modèle…)" aria-label="Filtrer" />
    <div class="tabs">
      <button class="tab-btn" data-tab="lcd" aria-selected="true">LCD</button>
      <button class="tab-btn" data-tab="pc" aria-selected="false">PC</button>
      <button class="tab-btn" data-tab="copiers" aria-selected="false">Photocopieurs</button>
      <button class="tab-btn" data-tab="toners" aria-selected="false">Toners</button>
      <button class="tab-btn" data-tab="paper" aria-selected="false">Papier</button>
    </div>
  </div>

  <!-- ===== LCD (A/B/C) ===== -->
  <section id="tab-lcd" class="tab-panel" role="region" aria-labelledby="LCD">
    <div class="subtabs" role="tablist" aria-label="LCD par état">
      <button class="subtab-btn" data-sub="A" aria-selected="true">A</button>
      <button class="subtab-btn" data-sub="B" aria-selected="false">B</button>
      <button class="subtab-btn" data-sub="C" aria-selected="false">C</button>
    </div>

    <?php foreach (['A','B','C'] as $etat): ?>
      <div class="subpanel" id="lcd-<?= $etat ?>" <?= $etat==='A'?'':'style="display:none;"' ?>>
        <div class="table-wrapper">
          <table class="tbl-stock">
            <thead>
              <tr>
                <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>Taille</th><th>Résolution</th><th>Qté</th><th>Prix</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach (($lcd[$etat] ?? []) as $row): ?>
              <tr data-search="<?= h(strtolower($etat.' '.$row['ref'].' '.$row['marque'].' '.$row['modele'].' '.$row['resolution'])) ?>">
                <td data-th="État"><span class="chip">A/B/C</span> <?= h($etat) ?></td>
                <td data-th="Réf."><?= h($row['ref']) ?></td>
                <td data-th="Marque"><?= h($row['marque']) ?></td>
                <td data-th="Modèle"><?= h($row['modele']) ?></td>
                <td data-th="Taille"><?= (int)$row['taille'] ?>"</td>
                <td data-th="Résolution"><?= h($row['resolution']) ?></td>
                <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
                <td data-th="Prix" class="td-metric"><?= number_format((float)$row['prix'],2,',',' ') ?> €</td>
              </tr>
            <?php endforeach; if (empty($lcd[$etat])): ?>
              <tr><td colspan="8">— Aucun LCD en état <?= h($etat) ?> —</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- ===== PC (A/B/C) ===== -->
  <section id="tab-pc" class="tab-panel" role="region" aria-labelledby="PC" style="display:none;">
    <div class="subtabs" role="tablist" aria-label="PC par état">
      <button class="subtab-btn" data-sub="A" aria-selected="true">A</button>
      <button class="subtab-btn" data-sub="B" aria-selected="false">B</button>
      <button class="subtab-btn" data-sub="C" aria-selected="false">C</button>
    </div>

    <?php foreach (['A','B','C'] as $etat): ?>
      <div class="subpanel" id="pc-<?= $etat ?>" <?= $etat==='A'?'':'style="display:none;"' ?>>
        <div class="table-wrapper">
          <table class="tbl-stock">
            <thead>
              <tr>
                <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>CPU</th><th>RAM</th><th>Stockage</th><th>OS</th><th>Qté</th><th>Prix</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach (($pc[$etat] ?? []) as $row): ?>
              <tr data-search="<?= h(strtolower($etat.' '.$row['ref'].' '.$row['marque'].' '.$row['modele'].' '.$row['cpu'].' '.$row['ram'].' '.$row['stockage'].' '.$row['os'])) ?>">
                <td data-th="État"><span class="chip">A/B/C</span> <?= h($etat) ?></td>
                <td data-th="Réf."><?= h($row['ref']) ?></td>
                <td data-th="Marque"><?= h($row['marque']) ?></td>
                <td data-th="Modèle"><?= h($row['modele']) ?></td>
                <td data-th="CPU"><?= h($row['cpu']) ?></td>
                <td data-th="RAM"><?= h($row['ram']) ?></td>
                <td data-th="Stockage"><?= h($row['stockage']) ?></td>
                <td data-th="OS"><?= h($row['os']) ?></td>
                <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
                <td data-th="Prix" class="td-metric"><?= number_format((float)$row['prix'],2,',',' ') ?> €</td>
              </tr>
            <?php endforeach; if (empty($pc[$etat])): ?>
              <tr><td colspan="10">— Aucun PC en état <?= h($etat) ?> —</td></tr>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    <?php endforeach; ?>
  </section>

  <!-- ===== Photocopieurs ===== -->
  <section id="tab-copiers" class="tab-panel" role="region" aria-labelledby="Photocopieurs" style="display:none;">
    <div class="table-wrapper">
      <table class="tbl-stock">
        <thead>
          <tr>
            <th>Réf.</th><th>Marque</th><th>Modèle</th><th>SN</th><th>MAC</th>
            <th>Total BW</th><th>Total Couleur</th><th>Statut</th><th>Qté</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($copiers as $r): ?>
          <tr data-search="<?= h(strtolower($r['ref'].' '.$r['marque'].' '.$r['modele'].' '.$r['sn'].' '.$r['mac'].' '.$r['statut'])) ?>">
            <td data-th="Réf."><?= h($r['ref']) ?></td>
            <td data-th="Marque"><?= h($r['marque']) ?></td>
            <td data-th="Modèle"><strong><?= h($r['modele']) ?></strong></td>
            <td data-th="SN"><?= h($r['sn']) ?></td>
            <td data-th="MAC"><?= h($r['mac']) ?></td>
            <td data-th="Total BW" class="td-metric"><?= number_format((int)$r['compteur_bw'],0,',',' ') ?></td>
            <td data-th="Total Couleur" class="td-metric"><?= number_format((int)$r['compteur_color'],0,',',' ') ?></td>
            <td data-th="Statut"><span class="chip"><?= h($r['statut']) ?></span></td>
            <td data-th="Qté" class="td-metric"><?= (int)$r['qty'] ?></td>
          </tr>
        <?php endforeach; if (empty($copiers)): ?>
          <tr><td colspan="9">— Aucun photocopieur —</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ===== Toners ===== -->
  <section id="tab-toners" class="tab-panel" role="region" aria-labelledby="Toners" style="display:none;">
    <div class="table-wrapper">
      <table class="tbl-stock">
        <thead>
          <tr>
            <th>Réf.</th><th>Marque</th><th>Modèle</th><th>Couleur</th><th>Compatibilité</th><th>Qté</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($toners as $t): ?>
          <tr data-search="<?= h(strtolower($t['ref'].' '.$t['marque'].' '.$t['modele'].' '.$t['couleur'].' '.$t['compat'])) ?>">
            <td data-th="Réf."><?= h($t['ref']) ?></td>
            <td data-th="Marque"><?= h($t['marque']) ?></td>
            <td data-th="Modèle"><?= h($t['modele']) ?></td>
            <td data-th="Couleur"><?= h($t['couleur']) ?></td>
            <td data-th="Compatibilité"><?= h($t['compat']) ?></td>
            <td data-th="Qté" class="td-metric"><?= (int)$t['qty'] ?></td>
          </tr>
        <?php endforeach; if (empty($toners)): ?>
          <tr><td colspan="6">— Aucun toner —</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ===== Papier ===== -->
  <section id="tab-paper" class="tab-panel" role="region" aria-labelledby="Papier" style="display:none;">
    <div class="table-wrapper">
      <table class="tbl-stock">
        <thead>
          <tr>
            <th>Réf.</th><th>Type</th><th>Format</th><th>Couleur</th><th>Qté (ramettes)</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($papiers as $p): ?>
          <tr data-search="<?= h(strtolower($p['ref'].' '.$p['type'].' '.$p['format'].' '.$p['couleur'])) ?>">
            <td data-th="Réf."><?= h($p['ref']) ?></td>
            <td data-th="Type"><?= h($p['type']) ?></td>
            <td data-th="Format"><?= h($p['format']) ?></td>
            <td data-th="Couleur"><?= h($p['couleur']) ?></td>
            <td data-th="Qté" class="td-metric"><?= (int)$p['qty'] ?></td>
          </tr>
        <?php endforeach; if (empty($papiers)): ?>
          <tr><td colspan="5">— Aucun papier —</td></tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div><!-- /.page-container -->

<!-- JS minimal (même logique que clients.php : filtres & lignes responsives) -->
<script>
// Navigation onglets principaux
(function(){
  const panels = {
    lcd: document.getElementById('tab-lcd'),
    pc: document.getElementById('tab-pc'),
    copiers: document.getElementById('tab-copiers'),
    toners: document.getElementById('tab-toners'),
    paper: document.getElementById('tab-paper'),
  };
  const tabBtns = document.querySelectorAll('.tab-btn');
  function showTab(name){
    Object.keys(panels).forEach(k => panels[k].style.display = (k===name)?'block':'none');
    tabBtns.forEach(b => b.setAttribute('aria-selected', String(b.dataset.tab===name)));
    document.getElementById('q')?.focus();
  }
  tabBtns.forEach(b => b.addEventListener('click', ()=>showTab(b.dataset.tab)));
})();

// Sous-onglets A/B/C pour LCD & PC
(function(){
  function bindSubtabs(prefix){
    const wrap = document.querySelector(`#tab-${prefix} .subtabs`);
    if (!wrap) return;
    const buttons = wrap.querySelectorAll('.subtab-btn');
    function showSub(val){
      buttons.forEach(b => b.setAttribute('aria-selected', String(b.dataset.sub===val)));
      document.querySelectorAll(`#tab-${prefix} .subpanel`).forEach(p => {
        p.style.display = p.id === `${prefix}-${val}` ? 'block' : 'none';
      });
    }
    buttons.forEach(btn => btn.addEventListener('click', ()=>showSub(btn.dataset.sub)));
  }
  bindSubtabs('lcd');
  bindSubtabs('pc');
})();

// Filtre rapide (client-side)
(function(){
  const q = document.getElementById('q');
  if (!q) return;
  function apply(){
    const v = (q.value||'').trim().toLowerCase();
    document.querySelectorAll('.tbl-stock tbody tr').forEach(tr=>{
      const t = (tr.getAttribute('data-search')||'').toLowerCase();
      tr.style.display = !v || t.includes(v) ? '' : 'none';
    });
  }
  q.addEventListener('input', apply);
})();
</script>
</body>
</html>
