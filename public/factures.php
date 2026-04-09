<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('paiements', []);
ensureCsrfToken();

$pdo = getPdo();

// [Fonctionnalité A]
$rows = $pdo->query("
SELECT f.id, f.numero, f.date_facture, f.type, f.montant_ht, f.tva, f.montant_ttc, f.statut, f.email_envoye, f.pdf_path,
       f.created_at, f.updated_at, COALESCE(f.nb_relances,0) AS nb_relances,
       c.raison_sociale, c.numero_client,
       u.nom AS created_by_nom, u.prenom AS created_by_prenom
FROM factures f
LEFT JOIN clients c ON c.id = f.id_client
LEFT JOIN utilisateurs u ON u.id = f.created_by
ORDER BY f.date_facture DESC, f.id DESC
LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stats = $pdo->query("
SELECT
  COUNT(*) total_factures,
  COALESCE(SUM(montant_ttc),0) total_ttc,
  SUM(CASE WHEN statut IN ('envoyee','en_retard') THEN 1 ELSE 0 END) impayees,
  COALESCE(SUM(CASE WHEN statut IN ('envoyee','en_retard') THEN montant_ttc ELSE 0 END),0) montant_impaye
FROM factures
")->fetch(PDO::FETCH_ASSOC) ?: [];

// [Fonctionnalité F]
$kpi = $pdo->query("
SELECT
  SUM(CASE WHEN statut != 'annulee' THEN montant_ttc ELSE 0 END) as ca_total,
  SUM(CASE WHEN statut = 'payee' THEN montant_ttc ELSE 0 END) as ca_encaisse,
  SUM(CASE WHEN statut IN ('envoyee','en_retard') THEN montant_ttc ELSE 0 END) as ca_impaye,
  COUNT(CASE WHEN statut IN ('envoyee','en_retard') THEN 1 END) as nb_impayes,
  COUNT(CASE WHEN statut = 'payee' THEN 1 END) as nb_payees,
  COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) as nb_retard
FROM factures
WHERE MONTH(date_facture) = MONTH(NOW()) AND YEAR(date_facture) = YEAR(NOW())
")->fetch(PDO::FETCH_ASSOC) ?: [];

$evol = $pdo->query("
SELECT DATE_FORMAT(date_facture, '%Y-%m') as mois,
       SUM(CASE WHEN statut != 'annulee' THEN montant_ttc ELSE 0 END) as ca,
       SUM(CASE WHEN statut = 'payee' THEN montant_ttc ELSE 0 END) as encaisse
FROM factures
WHERE date_facture >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(date_facture, '%Y-%m')
ORDER BY mois ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$topClients = $pdo->query("
SELECT c.id, c.raison_sociale, c.numero_client,
       SUM(f.montant_ttc) as ca_total,
       SUM(CASE WHEN f.statut='payee' THEN f.montant_ttc ELSE 0 END) as ca_paye,
       COUNT(*) as nb_factures
FROM factures f
JOIN clients c ON c.id = f.id_client
WHERE f.statut != 'annulee' AND f.date_facture >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
GROUP BY f.id_client
ORDER BY ca_total DESC
LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$retards = $pdo->query("
SELECT f.id, f.numero, f.date_facture, f.montant_ttc, COALESCE(f.nb_relances,0) as nb_relances, c.raison_sociale,
       DATEDIFF(NOW(), f.date_facture) as jours_retard
FROM factures f
JOIN clients c ON c.id = f.id_client
WHERE f.statut IN ('envoyee','en_retard')
ORDER BY jours_retard DESC
LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Compatibilité schéma ancien/nouveau pour factures_recurrentes
$rec = [];
$recQueries = [
    // Schéma récent
    "
    SELECT c.id id_client, c.raison_sociale,
           fr.type AS type_rec,
           fr.jour_generation AS jour_gen,
           fr.mois_dernier_envoi AS dernier_envoi,
           fr.actif AS actif_rec
    FROM clients c
    LEFT JOIN factures_recurrentes fr ON fr.id_client = c.id
    ORDER BY c.raison_sociale ASC
    LIMIT 300
    ",
    // Schéma ancien
    "
    SELECT c.id id_client, c.raison_sociale,
           fr.type_facture AS type_rec,
           fr.jour_mois AS jour_gen,
           DATE_FORMAT(fr.prochaine_echeance, '%Y-%m') AS dernier_envoi,
           fr.actif AS actif_rec
    FROM clients c
    LEFT JOIN factures_recurrentes fr ON fr.id_client = c.id
    ORDER BY c.raison_sociale ASC
    LIMIT 300
    ",
    // Fallback minimal (pas de colonnes de config)
    "
    SELECT c.id id_client, c.raison_sociale,
           'Consommation' AS type_rec,
           1 AS jour_gen,
           NULL AS dernier_envoi,
           0 AS actif_rec
    FROM clients c
    ORDER BY c.raison_sociale ASC
    LIMIT 300
    ",
];
foreach ($recQueries as $sqlRec) {
    try {
        $rec = $pdo->query($sqlRec)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        break;
    } catch (Throwable $e) {
        // Passe à la variante suivante si le schéma diffère.
    }
}

function eur($v): string { return number_format((float)$v, 2, ',', ' ') . ' €'; }
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Factures - CCComputer</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    .wrap{padding:16px}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}.card{background:#fff;padding:12px;border-radius:8px}
    .toolbar{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.tbl{width:100%;border-collapse:collapse}.tbl th,.tbl td{padding:8px;border-bottom:1px solid #e5e7eb}
    .badge{padding:2px 8px;border-radius:12px;font-size:12px}.b-brouillon{background:#e5e7eb}.b-envoyee{background:#dbeafe}.b-payee{background:#dcfce7}.b-retard{background:#fee2e2}.b-annulee{text-decoration:line-through}
    .kpi{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.panel{background:#fff;border-radius:8px;padding:12px;margin-top:10px}
    .hidden{display:none}.actions button,.actions a{margin-right:4px}
  </style>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <h1>Factures</h1>

  <div class="cards">
    <div class="card"><strong>Total factures</strong><div><?= (int)$stats['total_factures'] ?></div></div>
    <div class="card"><strong>Montant total TTC</strong><div><?= eur($stats['total_ttc'] ?? 0) ?></div></div>
    <div class="card"><strong>Impayées</strong><div><?= (int)$stats['impayees'] ?></div></div>
    <div class="card"><strong>Montant impayé</strong><div><?= eur($stats['montant_impaye'] ?? 0) ?></div></div>
  </div>

  <div class="toolbar">
    <input id="fSearch" placeholder="Client ou numéro facture">
    <select id="fStatut"><option value="">Tous statuts</option><option>brouillon</option><option>envoyee</option><option>payee</option><option>en_retard</option><option>annulee</option></select>
    <select id="fType"><option value="">Tous types</option><option>Consommation</option><option>Achat</option><option>Service</option></select>
    <select id="fPeriod"><option value="all">Tout</option><option value="month">Ce mois</option><option value="3m">3 mois</option><option value="6m">6 mois</option><option value="year">Cette année</option></select>
    <a href="/API/factures_export_csv.php">Exporter CSV</a>
    <a href="/public/factures_generer.php">+ Nouvelle facture</a>
    <button id="btnRec">🔄 Récurrence</button>
    <button id="btnDash">📊 Tableau de bord ▾</button>
  </div>

  <section id="dash" class="panel hidden">
    <div class="kpi">
      <div class="card"><strong>CA facturé</strong><div><?= eur($kpi['ca_total'] ?? 0) ?></div></div>
      <div class="card"><strong>CA encaissé</strong><div><?= eur($kpi['ca_encaisse'] ?? 0) ?></div></div>
      <div class="card"><strong>CA impayé</strong><div style="color:#dc2626"><?= eur($kpi['ca_impaye'] ?? 0) ?></div></div>
      <div class="card"><strong>Taux paiement</strong><div><?= (($kpi['ca_total'] ?? 0) > 0) ? number_format(((float)$kpi['ca_encaisse'] / (float)$kpi['ca_total']) * 100, 1, ',', '') : '0' ?>%</div></div>
      <div class="card"><strong>Factures en retard</strong><div><?= (int)($kpi['nb_retard'] ?? 0) ?></div></div>
    </div>
    <div class="panel"><strong>Évolution 6 mois</strong><table class="tbl"><tr><th>Mois</th><th>CA facturé</th><th>CA encaissé</th><th>Impayé</th><th>Taux</th></tr><?php foreach($evol as $e): $imp=(float)$e['ca']-(float)$e['encaisse']; ?><tr><td><?= h($e['mois']) ?></td><td><?= eur($e['ca']) ?></td><td><?= eur($e['encaisse']) ?></td><td><?= eur($imp) ?></td><td><?= ((float)$e['ca']>0)?number_format(((float)$e['encaisse']/(float)$e['ca'])*100,1,',',''):0 ?>%</td></tr><?php endforeach; ?></table></div>
    <div class="panel"><strong>Top 5 clients</strong><table class="tbl"><tr><th>Client</th><th>CA 12 mois</th><th>CA encaissé</th><th>Nb factures</th><th>Lien</th></tr><?php foreach($topClients as $t): ?><tr><td><?= h($t['raison_sociale']) ?></td><td><?= eur($t['ca_total']) ?></td><td><?= eur($t['ca_paye']) ?></td><td><?= (int)$t['nb_factures'] ?></td><td><a href="/public/client_fiche.php?id=<?= (int)$t['id'] ?>">Fiche</a></td></tr><?php endforeach; ?></table></div>
    <div class="panel"><strong>Factures en retard</strong><table class="tbl"><tr><th>N°</th><th>Client</th><th>Date</th><th>TTC</th><th>Relances</th><th>Jours retard</th></tr><?php foreach($retards as $r): ?><tr><td><?= h($r['numero']) ?></td><td><?= h($r['raison_sociale']) ?></td><td><?= h($r['date_facture']) ?></td><td><?= eur($r['montant_ttc']) ?></td><td>R<?= (int)$r['nb_relances'] ?></td><td style="color:<?= ((int)$r['jours_retard']>=30)?'#7f1d1d':'#c2410c' ?>"><?= (int)$r['jours_retard'] ?> j</td></tr><?php endforeach; ?></table></div>
  </section>

  <table class="tbl" id="factTbl">
    <thead><tr><th>N° Facture</th><th>Client</th><th>Date</th><th>Type</th><th>Montant HT</th><th>TVA</th><th>Montant TTC</th><th>Statut</th><th>Email</th><th>Actions</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $st=(string)$r['statut']; $badge='b-'.$st; ?>
      <tr data-statut="<?= h($st) ?>" data-type="<?= h((string)$r['type']) ?>" data-date="<?= h((string)$r['date_facture']) ?>" data-search="<?= h(strtolower(($r['numero'] ?? '').' '.($r['raison_sociale'] ?? ''))) ?>">
        <td><?= h((string)$r['numero']) ?> <?php if((int)$r['nb_relances']>0): ?><span class="badge" style="background:<?= ((int)$r['nb_relances']>=3)?'#fee2e2':'#fef3c7' ?>">R<?= (int)$r['nb_relances'] ?></span><?php endif; ?></td>
        <td><?= h((string)$r['raison_sociale']) ?></td><td><?= h((string)$r['date_facture']) ?></td><td><?= h((string)$r['type']) ?></td>
        <td><?= eur($r['montant_ht']) ?></td><td><?= eur($r['tva']) ?></td><td><?= eur($r['montant_ttc']) ?></td>
        <td><span class="badge <?= h($badge) ?>"><?= h($st) ?></span></td>
        <td><?= ((int)$r['email_envoye'] === 1) ? '✅' : '—' ?></td>
        <td class="actions">
          <a href="/public/view_facture.php?id=<?= (int)$r['id'] ?>">👁</a>
          <?php if(!in_array($st,['payee','annulee'],true)): ?><button data-relance="<?= (int)$r['id'] ?>">📨</button><button data-mail="<?= (int)$r['id'] ?>">✉</button><?php endif; ?>
          <a href="/public/paiements.php?facture_id=<?= (int)$r['id'] ?>">💰</a>
          <?php if($st==='brouillon'): ?><button data-mod="<?= (int)$r['id'] ?>" data-date="<?= h((string)$r['date_facture']) ?>">✏</button><?php endif; ?>
          <?php if(!in_array($st,['payee','annulee'],true)): ?><button data-ann="<?= (int)$r['id'] ?>">❌</button><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <dialog id="dAnn"><form method="dialog"><h3>Annuler facture</h3><input type="hidden" id="annId"><textarea id="annMotif" required placeholder="Motif d'annulation"></textarea><button type="button" id="annOk">Confirmer</button><button>Fermer</button></form></dialog>
  <dialog id="dMod"><form method="dialog"><h3>Modifier facture</h3><input type="hidden" id="modId"><input type="date" id="modDate" required><button type="button" id="modOk">Enregistrer</button><button>Fermer</button></form></dialog>
  <dialog id="dRec"><h3>Configurations récurrentes</h3><table class="tbl"><tr><th>Client</th><th>Type</th><th>Jour</th><th>Dernier envoi</th><th>Actif</th><th>Configurer</th></tr><?php foreach($rec as $rc): ?><tr><td><?= h($rc['raison_sociale']) ?></td><td><?= h((string)($rc['type_rec'] ?? 'Consommation')) ?></td><td><?= (int)($rc['jour_gen'] ?? 1) ?></td><td><?= h((string)($rc['dernier_envoi'] ?? '—')) ?></td><td><?= ((int)($rc['actif_rec'] ?? 0)===1)?'Oui':'Non' ?></td><td><button class="cfgRec" data-client="<?= (int)$rc['id_client'] ?>">Configurer</button></td></tr><?php endforeach; ?></table><button onclick="document.getElementById('dRec').close()">Fermer</button></dialog>
</main>
<script <?= csp_nonce() ?>>
// [Fonctionnalité A]
const q = document.getElementById('fSearch'), fs = document.getElementById('fStatut'), ft = document.getElementById('fType'), fp = document.getElementById('fPeriod');
function applyFilters(){const now=new Date();document.querySelectorAll('#factTbl tbody tr').forEach(tr=>{const s=tr.dataset.search||'', st=tr.dataset.statut||'', ty=tr.dataset.type||'', d=new Date(tr.dataset.date||'1970-01-01'); let ok=true; if(q.value&& !s.includes(q.value.toLowerCase())) ok=false; if(fs.value&&fs.value!==st) ok=false; if(ft.value&&ft.value!==ty) ok=false; if(fp.value==='month'&&(d.getMonth()!==now.getMonth()||d.getFullYear()!==now.getFullYear())) ok=false; if(fp.value==='3m'&&((now-d)/(1000*3600*24))>92) ok=false; if(fp.value==='6m'&&((now-d)/(1000*3600*24))>184) ok=false; if(fp.value==='year'&&d.getFullYear()!==now.getFullYear()) ok=false; tr.style.display=ok?'':'none';});}
[q,fs,ft,fp].forEach(el=>el&&el.addEventListener('input',applyFilters)); applyFilters();
document.getElementById('btnDash').onclick=()=>document.getElementById('dash').classList.toggle('hidden');
document.getElementById('btnRec').onclick=()=>document.getElementById('dRec').showModal();

const csrf = document.body.dataset.csrfToken || '';
document.querySelectorAll('[data-ann]').forEach(b=>b.onclick=()=>{document.getElementById('annId').value=b.dataset.ann;document.getElementById('dAnn').showModal();});
document.getElementById('annOk').onclick=async()=>{const id=document.getElementById('annId').value,motif=document.getElementById('annMotif').value.trim();if(!motif)return;await fetch('/API/factures_annuler.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id,motif})});location.reload();};
document.querySelectorAll('[data-mod]').forEach(b=>b.onclick=()=>{document.getElementById('modId').value=b.dataset.mod;document.getElementById('modDate').value=b.dataset.date;document.getElementById('dMod').showModal();});
document.getElementById('modOk').onclick=async()=>{const id=document.getElementById('modId').value,date_facture=document.getElementById('modDate').value;await fetch('/API/factures_modifier.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id,date_facture})});location.reload();};
document.querySelectorAll('[data-relance]').forEach(b=>b.onclick=async()=>{await fetch('/API/factures_relance_manuelle.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id_facture:parseInt(b.dataset.relance,10),numero_relance:1})});location.reload();});
document.querySelectorAll('[data-mail]').forEach(b=>b.onclick=async()=>{await fetch('/API/factures_envoyer_email.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({facture_id:parseInt(b.dataset.mail,10)})});location.reload();});
document.querySelectorAll('.cfgRec').forEach(btn=>btn.onclick=async()=>{const type=prompt('Type (Consommation/Achat/Service)','Consommation')||'Consommation';const jour=parseInt(prompt('Jour (1-28)','1')||'1',10);const montant=prompt('Montant fixe HT (Achat/Service)','');await fetch('/API/factures_recurrente_config.php',{method:'POST',credentials:'include',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify({id_client:parseInt(btn.dataset.client,10),actif:1,type,jour_generation:jour,montant_fixe:montant})});location.reload();});
</script>
</body></html>
