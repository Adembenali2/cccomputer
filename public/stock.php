<?php
// /public/stock.php
require_once __DIR__ . '/../includes/auth.php';

// Helpers
function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function stateBadge(?string $etat): string {
  $e = strtoupper(trim((string)$etat));
  if (!in_array($e, ['A','B','C'], true)) return '<span class="state state-na">—</span>';
  return '<span class="state state-'.$e.'">'.$e.'</span>';
}

// Données factices (remplace plus tard par la BDD)
// Photocopieurs (avec état)
$copiers = [
  ['ref'=>'COP-001','marque'=>'Kyocera','modele'=>'TASKalfa 2553ci','sn'=>'KYO2553-001','mac'=>'10:AA:22:BB:33:CC','etat'=>'A','compteur_bw'=>45213,'compteur_color'=>18322,'statut'=>'Stock','qty'=>2],
  ['ref'=>'COP-005','marque'=>'Ricoh','modele'=>'MP C307','sn'=>'RICOH307-005','mac'=>'00:25:96:FF:EE:11','etat'=>'B','compteur_bw'=>9812,'compteur_color'=>5230,'statut'=>'Réservé','qty'=>1],
  ['ref'=>'COP-012','marque'=>'Canon','modele'=>'iR-ADV C3520','sn'=>'CAN3520-012','mac'=>'1C:4D:70:AB:44:21','etat'=>'C','compteur_bw'=>71322,'compteur_color'=>44110,'statut'=>'Stock','qty'=>1],
];

// LCD (avec état)
$lcd = [
  ['ref'=>'LCD-24A-001','marque'=>'Dell','modele'=>'U2415','taille'=>24,'resolution'=>'1920x1200','etat'=>'A','qty'=>12,'prix'=>129.90],
  ['ref'=>'LCD-27B-004','marque'=>'LG','modele'=>'27UL500','taille'=>27,'resolution'=>'3840x2160','etat'=>'B','qty'=>4,'prix'=>219.90],
  ['ref'=>'LCD-22C-020','marque'=>'HP','modele'=>'Z22n G2','taille'=>22,'resolution'=>'1920x1080','etat'=>'C','qty'=>6,'prix'=>79.90],
];

// PC reconditionnés (avec état)
$pc = [
  ['ref'=>'PC-A-001','marque'=>'Lenovo','modele'=>'ThinkCentre M720','cpu'=>'i5-9500','ram'=>'16 Go','stockage'=>'512 Go SSD','os'=>'Windows 11 Pro','etat'=>'A','qty'=>5,'prix'=>349.00],
  ['ref'=>'PC-B-010','marque'=>'Dell','modele'=>'OptiPlex 7060','cpu'=>'i5-8500','ram'=>'8 Go','stockage'=>'256 Go SSD','os'=>'Windows 10 Pro','etat'=>'B','qty'=>10,'prix'=>279.00],
  ['ref'=>'PC-C-015','marque'=>'Lenovo','modele'=>'ThinkPad T460','cpu'=>'i5-6300U','ram'=>'8 Go','stockage'=>'240 Go SSD','os'=>'Windows 10 Pro','etat'=>'C','qty'=>7,'prix'=>189.00],
];

// Toners (état non applicable → —)
$toners = [
  ['ref'=>'TN-K-2553','marque'=>'Kyocera','modele'=>'TK-8345K','couleur'=>'Noir','compat'=>'TA 2553ci / 3253ci','etat'=>null,'qty'=>14],
  ['ref'=>'TN-C-2553','marque'=>'Kyocera','modele'=>'TK-8345C','couleur'=>'Cyan','compat'=>'TA 2553ci / 3253ci','etat'=>null,'qty'=>6],
  ['ref'=>'TN-M-307','marque'=>'Ricoh','modele'=>'MPC307-M','couleur'=>'Magenta','compat'=>'MP C307','etat'=>null,'qty'=>3],
  ['ref'=>'TN-Y-307','marque'=>'Ricoh','modele'=>'MPC307-Y','couleur'=>'Jaune','compat'=>'MP C307','etat'=>null,'qty'=>0],
];

// Papier (état non applicable → —)
$papiers = [
  ['ref'=>'PAP-A4-80','type'=>'A4 80g','format'=>'210x297','couleur'=>'Blanc','etat'=>null,'qty'=>120],
  ['ref'=>'PAP-A3-90','type'=>'A3 90g','format'=>'297x420','couleur'=>'Blanc','etat'=>null,'qty'=>30],
  ['ref'=>'PAP-A4-RECYC','type'=>'A4 80g Recyclé','format'=>'210x297','couleur'=>'Blanc','etat'=>null,'qty'=>15],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Stock - CCComputer</title>

  <!-- mêmes bases que clients.php -->
  <link rel="stylesheet" href="/assets/css/main.css" />
  <link rel="stylesheet" href="/assets/css/stock.css" />
</head>
<body class="page-stock">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<div class="page-container">
  <div class="page-header">
    <h2 class="page-title">Stock</h2>
    <p class="page-subtitle">Vue synthétique : Photocopieurs, LCD, PC (à droite) — Toners & Papier (à gauche)</p>
  </div>

  <!-- Filtre global sur tous les tableaux -->
  <div class="filters-row">
    <input type="text" id="q" placeholder="Filtrer partout (réf., marque, modèle, SN, MAC…)" aria-label="Filtrer" />
  </div>

  <!-- Grille fixe : 2 colonnes. Gauche (2 cartes), Droite (3 cartes) -->
  <div class="stock-grid">
    <!-- Colonne gauche -->
    <section class="card-section left tall">
      <div class="section-head">
        <h3 class="section-title">Toners</h3>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock">
          <thead>
            <tr>
              <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>Couleur</th><th>Compatibilité</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($toners as $t): ?>
            <tr data-search="<?= h(strtolower(($t['ref'].' '.$t['marque'].' '.$t['modele'].' '.$t['couleur'].' '.$t['compat']))) ?>">
              <td data-th="État"><?= stateBadge($t['etat']) ?></td>
              <td data-th="Réf."><?= h($t['ref']) ?></td>
              <td data-th="Marque"><?= h($t['marque']) ?></td>
              <td data-th="Modèle"><?= h($t['modele']) ?></td>
              <td data-th="Couleur"><?= h($t['couleur']) ?></td>
              <td data-th="Compatibilité"><?= h($t['compat']) ?></td>
              <td data-th="Qté" class="td-metric <?= (int)$t['qty']===0?'is-zero':'' ?>"><?= (int)$t['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($toners)): ?>
            <tr><td colspan="7">— Aucun toner —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section left short">
      <div class="section-head">
        <h3 class="section-title">Papier</h3>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock">
          <thead>
            <tr>
              <th>État</th><th>Réf.</th><th>Type</th><th>Format</th><th>Couleur</th><th>Qté (ramettes)</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($papiers as $p): ?>
            <tr data-search="<?= h(strtolower($p['ref'].' '.$p['type'].' '.$p['format'].' '.$p['couleur'])) ?>">
              <td data-th="État"><?= stateBadge($p['etat']) ?></td>
              <td data-th="Réf."><?= h($p['ref']) ?></td>
              <td data-th="Type"><?= h($p['type']) ?></td>
              <td data-th="Format"><?= h($p['format']) ?></td>
              <td data-th="Couleur"><?= h($p['couleur']) ?></td>
              <td data-th="Qté" class="td-metric"><?= (int)$p['qty'] ?></td>
            </tr>
          <?php endforeach; if (empty($papiers)): ?>
            <tr><td colspan="6">— Aucun papier —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Colonne droite -->
    <section class="card-section right top">
      <div class="section-head">
        <h3 class="section-title">Photocopieurs</h3>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock">
          <thead>
            <tr>
              <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>SN</th><th>MAC</th>
              <th>Total BW</th><th>Total Couleur</th><th>Statut</th><th>Qté</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($copiers as $r): ?>
            <tr data-search="<?= h(strtolower($r['ref'].' '.$r['marque'].' '.$r['modele'].' '.$r['sn'].' '.$r['mac'].' '.$r['statut'].' '.$r['etat'])) ?>">
              <td data-th="État"><?= stateBadge($r['etat']) ?></td>
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
            <tr><td colspan="10">— Aucun photocopieur —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section right middle">
      <div class="section-head">
        <h3 class="section-title">LCD</h3>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock">
          <thead>
            <tr>
              <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>Taille</th><th>Résolution</th><th>Qté</th><th>Prix</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($lcd as $row): ?>
            <tr data-search="<?= h(strtolower($row['etat'].' '.$row['ref'].' '.$row['marque'].' '.$row['modele'].' '.$row['resolution'])) ?>">
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
              <td data-th="Réf."><?= h($row['ref']) ?></td>
              <td data-th="Marque"><?= h($row['marque']) ?></td>
              <td data-th="Modèle"><?= h($row['modele']) ?></td>
              <td data-th="Taille"><?= (int)$row['taille'] ?>"</td>
              <td data-th="Résolution"><?= h($row['resolution']) ?></td>
              <td data-th="Qté" class="td-metric"><?= (int)$row['qty'] ?></td>
              <td data-th="Prix" class="td-metric"><?= number_format((float)$row['prix'],2,',',' ') ?> €</td>
            </tr>
          <?php endforeach; if (empty($lcd)): ?>
            <tr><td colspan="8">— Aucun LCD —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card-section right bottom">
      <div class="section-head">
        <h3 class="section-title">PC reconditionnés</h3>
      </div>
      <div class="table-wrapper">
        <table class="tbl-stock">
          <thead>
            <tr>
              <th>État</th><th>Réf.</th><th>Marque</th><th>Modèle</th><th>CPU</th><th>RAM</th><th>Stockage</th><th>OS</th><th>Qté</th><th>Prix</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($pc as $row): ?>
            <tr data-search="<?= h(strtolower($row['etat'].' '.$row['ref'].' '.$row['marque'].' '.$row['modele'].' '.$row['cpu'].' '.$row['ram'].' '.$row['stockage'].' '.$row['os'])) ?>">
              <td data-th="État"><?= stateBadge($row['etat']) ?></td>
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
          <?php endforeach; if (empty($pc)): ?>
            <tr><td colspan="10">— Aucun PC —</td></tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </div><!-- /.stock-grid -->
</div><!-- /.page-container -->

<!-- Filtre global -->
<script>
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
