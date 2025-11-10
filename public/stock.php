<?php
// /public/stock.php
require_once __DIR__ . '/../includes/auth.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function stateBadge(?string $etat): string {
  $e = strtoupper(trim((string)$etat));
  if (!in_array($e, ['A','B','C'], true)) return '<span class="state state-na">—</span>';
  return '<span class="state state-'.$e.'">'.$e.'</span>';
}

/* =============================
   Données factices (sans BDD)
   ============================= */

// Photocopieurs — (beaucoup d'infos, popup activé)
$copiers = [
  [
    'id'=>'cop-001','etat'=>'A','ref'=>'COP-001','marque'=>'Kyocera','modele'=>'TASKalfa 2553ci',
    'sn'=>'KYO2553-001','mac'=>'10:AA:22:BB:33:CC','compteur_bw'=>45213,'compteur_color'=>18322,
    'statut'=>'Stock','qty'=>2,'emplacement'=>'Entrepôt A1','commentaire'=>'RAS'
  ],
  [
    'id'=>'cop-005','etat'=>'B','ref'=>'COP-005','marque'=>'Ricoh','modele'=>'MP C307',
    'sn'=>'RICOH307-005','mac'=>'00:25:96:FF:EE:11','compteur_bw'=>9812,'compteur_color'=>5230,
    'statut'=>'Réservé','qty'=>1,'emplacement'=>'Entrepôt B3','commentaire'=>'A nettoyer'
  ],
  [
    'id'=>'cop-012','etat'=>'C','ref'=>'COP-012','marque'=>'Canon','modele'=>'iR-ADV C3520',
    'sn'=>'CAN3520-012','mac'=>'1C:4D:70:AB:44:21','compteur_bw'=>71322,'compteur_color'=>44110,
    'statut'=>'Stock','qty'=>1,'emplacement'=>'Entrepôt C2','commentaire'=>'Traces d’usage'
  ],
];

// LCD — (beaucoup d'infos, popup activé)
$lcd = [
  [
    'id'=>'lcd-24a-001','etat'=>'A','ref'=>'LCD-24A-001','marque'=>'Dell','modele'=>'U2415',
    'taille'=>24,'resolution'=>'1920x1200','connectique'=>'HDMI/DP','qty'=>12,'prix'=>129.90,
    'garantie'=>'3 mois','commentaire'=>'Dalle parfaite'
  ],
  [
    'id'=>'lcd-27b-004','etat'=>'B','ref'=>'LCD-27B-004','marque'=>'LG','modele'=>'27UL500',
    'taille'=>27,'resolution'=>'3840x2160','connectique'=>'HDMI','qty'=>4,'prix'=>219.90,
    'garantie'=>'1 mois','commentaire'=>'Micro rayure pied'
  ],
  [
    'id'=>'lcd-22c-020','etat'=>'C','ref'=>'LCD-22C-020','marque'=>'HP','modele'=>'Z22n G2',
    'taille'=>22,'resolution'=>'1920x1080','connectique'=>'DP','qty'=>6,'prix'=>79.90,
    'garantie'=>'—','commentaire'=>'Coins du cadre usés'
  ],
];

// PC reconditionnés — (beaucoup d'infos, popup activé)
$pc = [
  [
    'id'=>'pc-a-001','etat'=>'A','ref'=>'PC-A-001','marque'=>'Lenovo','modele'=>'ThinkCentre M720',
    'cpu'=>'i5-9500','ram'=>'16 Go','stockage'=>'512 Go SSD','os'=>'Windows 11 Pro',
    'gpu'=>'Intel UHD 630','reseau'=>'Gigabit','ports'=>'USB 3.0 x6','qty'=>5,'prix'=>349.00,
    'garantie'=>'6 mois','commentaire'=>'Châssis très propre'
  ],
  [
    'id'=>'pc-b-010','etat'=>'B','ref'=>'PC-B-010','marque'=>'Dell','modele'=>'OptiPlex 7060',
    'cpu'=>'i5-8500','ram'=>'8 Go','stockage'=>'256 Go SSD','os'=>'Windows 10 Pro',
    'gpu'=>'Intel UHD 630','reseau'=>'Gigabit','ports'=>'USB 3.0 x6','qty'=>10,'prix'=>279.00,
    'garantie'=>'3 mois','commentaire'=>'Micro rayures façade'
  ],
  [
    'id'=>'pc-c-015','etat'=>'C','ref'=>'PC-C-015','marque'=>'Lenovo','modele'=>'ThinkPad T460',
    'cpu'=>'i5-6300U','ram'=>'8 Go','stockage'=>'240 Go SSD','os'=>'Windows 10 Pro',
    'gpu'=>'Intel HD 520','reseau'=>'Wi-Fi/BT','ports'=>'USB 3.0 x3','qty'=>7,'prix'=>189.00,
    'garantie'=>'1 mois','commentaire'=>'Batterie moyenne'
  ],
];

// Toners — (3 infos seulement, pas de popup)
$toners = [
  ['id'=>'tn-k-2553','modele'=>'TK-8345K','couleur'=>'Noir','qty'=>14],
  ['id'=>'tn-c-2553','modele'=>'TK-8345C','couleur'=>'Cyan','qty'=>6],
  ['id'=>'tn-m-307','modele'=>'MPC307-M','couleur'=>'Magenta','qty'=>3],
  ['id'=>'tn-y-307','modele'=>'MPC307-Y','couleur'=>'Jaune','qty'=>0],
];

// Papier — (3 infos seulement, pas de popup)
$papiers = [
  ['id'=>'pap-a4-80','modele'=>'A4','poids'=>'80g','qty'=>120],
  ['id'=>'pap-a3-90','modele'=>'A3','poids'=>'90g','qty'=>30],
  ['id'=>'pap-a4-recyc','modele'=>'A4 Recyclé','poids'=>'80g','qty'=>15],
];

// Pour JS (datasets popup)
$datasets = [
  'copiers' => $copiers,
  'lcd'     => $lcd,
  'pc'      => $pc,
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
    <p class="page-subtitle">Vue condensée — cliquez un **Photocopieur / PC / LCD** pour voir tous les détails.</p>
  </div>

  <div class="filters-row">
    <input type="text" id="q" placeholder="Filtrer partout (réf., modèle, SN, MAC, CPU…)" aria-label="Filtrer" />
  </div>

  <!-- Grille 2 colonnes : gauche (Toner, Papier) / droite (Photocopieurs, LCD, PC) -->
  <div class="stock-grid">
    <!-- Gauche -->
    <section class="card-section left tall">
      <div class="section-head"><h3 class="section-title">Toners</h3></div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact">
          <thead>
            <tr>
              <th>Couleur</th><th>Modèle</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($toners as $t): ?>
            <tr data-search="<?= h(strtolower($t['couleur'].' '.$t['modele'])) ?>">
              <td data-th="Couleur"><?= h($t['couleur']) ?></td>
              <td data-th="Modèle"><?= h($t['modele']) ?></td>
              <td data-th="Qté" class="td-metric <?= (int)$t['qty']===0?'is-zero':'' ?>"><?= (int)$t['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($toners)): ?>
            <tr><td colspan="3">— Aucun toner —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section left short">
      <div class="section-head"><h3 class="section-title">Papier</h3></div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact">
          <thead>
            <tr>
              <th>Qté</th><th>Modèle</th><th>Poids</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($papiers as $p): ?>
            <tr data-search="<?= h(strtolower($p['modele'].' '.$p['poids'])) ?>">
              <td data-th="Qté" class="td-metric"><?= (int)$p['qty'] ?></td>
              <td data-th="Modèle"><?= h($p['modele']) ?></td>
              <td data-th="Poids"><?= h($p['poids']) ?></td>
            </tr>
          <?php endforeach; if (empty($papiers)): ?>
            <tr><td colspan="3">— Aucun papier —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Droite -->
    <section class="card-section right top">
      <div class="section-head"><h3 class="section-title">Photocopieurs</h3></div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact click-rows">
          <thead>
            <tr>
              <th>État</th><th>Modèle</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($copiers as $r): ?>
            <tr
              data-type="copiers" data-id="<?= h($r['id']) ?>"
              data-search="<?= h(strtolower($r['modele'].' '.$r['ref'].' '.$r['sn'].' '.$r['mac'].' '.$r['marque'])) ?>"
            >
              <td data-th="État"><?= stateBadge($r['etat']) ?></td>
              <td data-th="Modèle"><strong><?= h($r['modele']) ?></strong></td>
              <td data-th="Qté" class="td-metric"><?= (int)$r['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($copiers)): ?>
            <tr><td colspan="3">— Aucun photocopieur —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section right middle">
      <div class="section-head"><h3 class="section-title">LCD</h3></div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact click-rows">
          <thead>
            <tr>
              <th>État</th><th>Modèle</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lcd as $row): ?>
            <tr
              data-type="lcd" data-id="<?= h($row['id']) ?>"
              data-search="<?= h(strtolower($row['modele'].' '.$row['ref'].' '.$row['marque'].' '.$row['resolution'])) ?>"
            >
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
              <td data-th="Modèle"><strong><?= h($row['modele']) ?></strong></td>
              <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($lcd)): ?>
            <tr><td colspan="3">— Aucun LCD —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section right bottom">
      <div class="section-head"><h3 class="section-title">PC reconditionnés</h3></div>
      <div class="table-wrapper">
        <table class="tbl-stock tbl-compact click-rows">
          <thead>
            <tr>
              <th>État</th><th>Modèle</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pc as $row): ?>
            <tr
              data-type="pc" data-id="<?= h($row['id']) ?>"
              data-search="<?= h(strtolower($row['modele'].' '.$row['ref'].' '.$row['marque'].' '.$row['cpu'].' '.$row['os'])) ?>"
            >
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
              <td data-th="Modèle"><strong><?= h($row['modele']) ?></strong></td>
              <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($pc)): ?>
            <tr><td colspan="3">— Aucun PC —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div><!-- /.stock-grid -->
</div><!-- /.page-container -->

<!-- =============================
     Modal détails (Photocopieurs / LCD / PC)
     ============================= -->
<div id="detailOverlay" class="modal-overlay" aria-hidden="true"></div>
<div id="detailModal" class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" style="display:none;">
  <div class="modal-header">
    <h3 id="modalTitle">Détails</h3>
    <button type="button" id="modalClose" class="icon-btn icon-btn--close" aria-label="Fermer">×</button>
  </div>
  <div class="modal-body">
    <div class="detail-grid" id="detailGrid">
      <!-- rempli dynamiquement -->
    </div>
  </div>
</div>

<script>
// === Filtre global ===
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

// === Datasets pour popup ===
const DATASETS = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

// Helpers rendu champs
function addField(grid, label, value){
  const card = document.createElement('div');
  card.className = 'field-card';
  card.innerHTML = `<div class="lbl">${label}</div><div class="val">${value ?? '—'}</div>`;
  grid.appendChild(card);
}
function badgeEtat(e){
  e = String(e||'').toUpperCase();
  if (!['A','B','C'].includes(e)) return '<span class="state state-na">—</span>';
  return `<span class="state state-${e}">${e}</span>`;
}

// === Modal ===
(function(){
  const overlay = document.getElementById('detailOverlay');
  const modal   = document.getElementById('detailModal');
  const close   = document.getElementById('modalClose');
  const grid    = document.getElementById('detailGrid');
  const titleEl = document.getElementById('modalTitle');

  function open(){ document.body.classList.add('modal-open'); overlay.setAttribute('aria-hidden','false'); overlay.style.display='block'; modal.style.display='block'; }
  function closeFn(){ document.body.classList.remove('modal-open'); overlay.setAttribute('aria-hidden','true'); overlay.style.display='none'; modal.style.display='none'; }
  close.addEventListener('click', closeFn);
  overlay.addEventListener('click', closeFn);

  function renderDetails(type, row){
    grid.innerHTML = '';
    // Titre
    titleEl.textContent = `${row.modele ?? row.ref ?? 'Détails'} — ${type.toUpperCase()}`;

    // Champs par type
    if (type === 'copiers') {
      addField(grid, 'État', badgeEtat(row.etat));
      addField(grid, 'Référence', row.ref);
      addField(grid, 'Marque', row.marque);
      addField(grid, 'Modèle', row.modele);
      addField(grid, 'N° Série', row.sn);
      addField(grid, 'Adresse MAC', row.mac);
      addField(grid, 'Compteur N&B', new Intl.NumberFormat('fr-FR').format(row.compteur_bw||0));
      addField(grid, 'Compteur Couleur', new Intl.NumberFormat('fr-FR').format(row.compteur_color||0));
      addField(grid, 'Statut', row.statut);
      addField(grid, 'Quantité', row.qty);
      addField(grid, 'Emplacement', row.emplacement);
      addField(grid, 'Commentaire', row.commentaire);
    } else if (type === 'lcd') {
      addField(grid, 'État', badgeEtat(row.etat));
      addField(grid, 'Référence', row.ref);
      addField(grid, 'Marque', row.marque);
      addField(grid, 'Modèle', row.modele);
      addField(grid, 'Taille', (row.taille?row.taille+'"':'—'));
      addField(grid, 'Résolution', row.resolution);
      addField(grid, 'Connectique', row.connectique);
      addField(grid, 'Prix', row.prix!=null ? new Intl.NumberFormat('fr-FR',{style:'currency',currency:'EUR'}).format(row.prix) : '—');
      addField(grid, 'Quantité', row.qty);
      addField(grid, 'Garantie', row.garantie);
      addField(grid, 'Commentaire', row.commentaire);
    } else if (type === 'pc') {
      addField(grid, 'État', badgeEtat(row.etat));
      addField(grid, 'Référence', row.ref);
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
      addField(grid, 'Garantie', row.garantie);
      addField(grid, 'Commentaire', row.commentaire);
    }
  }

  // Lignes cliquables
  document.querySelectorAll('.click-rows tbody tr[data-type][data-id]').forEach(tr=>{
    tr.style.cursor='pointer';
    tr.addEventListener('click', ()=>{
      const type = tr.getAttribute('data-type');
      const id   = tr.getAttribute('data-id');
      const rows = (DATASETS[type]||[]);
      const row  = rows.find(r=>String(r.id)===String(id));
      if (!row) return;
      renderDetails(type, row);
      open();
    });
  });
})();
</script>
</body>
</html>
