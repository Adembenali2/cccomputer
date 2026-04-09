<?php
declare(strict_types=1);

// [Fonctionnalité Stock] Interface de gestion stock (nouveau module)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
require_once __DIR__ . '/../includes/helpers.php';

authorize_page('stock', []);
ensureCsrfToken();
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gestion du stock - CCComputer</title>
    <link rel="stylesheet" href="/assets/css/dashboard.css">
    <style>
      .wrap{padding:16px}.panel{background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:12px;margin-bottom:12px}
      .row{display:grid;grid-template-columns:repeat(5,minmax(120px,1fr));gap:8px}.tbl{width:100%;border-collapse:collapse}
      .tbl th,.tbl td{padding:8px;border-bottom:1px solid #e5e7eb}.muted{color:#6b7280}.low{color:#b45309;font-weight:700}
    </style>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <h1>Gestion du stock</h1>

  <section class="panel">
    <h3>Filtres</h3>
    <div class="row">
      <input id="q" placeholder="Réf / désignation / marque">
      <select id="categorie">
        <option value="">Toutes catégories</option>
        <option value="toner_noir">Toner noir</option>
        <option value="toner_cyan">Toner cyan</option>
        <option value="toner_magenta">Toner magenta</option>
        <option value="toner_jaune">Toner jaune</option>
        <option value="papier">Papier</option>
        <option value="piece_detachee">Pièce détachée</option>
        <option value="consommable">Consommable</option>
        <option value="autre">Autre</option>
      </select>
      <select id="actif">
        <option value="">Actif + inactif</option>
        <option value="1">Actifs</option>
        <option value="0">Inactifs</option>
      </select>
      <button id="btnRefresh" type="button">Actualiser</button>
    </div>
  </section>

  <section class="panel">
    <h3>Créer un article</h3>
    <div class="row">
      <input id="fReference" placeholder="Référence">
      <input id="fDesignation" placeholder="Désignation">
      <select id="fCategorie">
        <option value="papier">Papier</option>
        <option value="toner_noir">Toner noir</option>
        <option value="toner_cyan">Toner cyan</option>
        <option value="toner_magenta">Toner magenta</option>
        <option value="toner_jaune">Toner jaune</option>
        <option value="piece_detachee">Pièce détachée</option>
        <option value="consommable">Consommable</option>
        <option value="autre">Autre</option>
      </select>
      <input id="fQuantite" type="number" min="0" value="0" placeholder="Quantité">
      <input id="fQuantiteMin" type="number" min="0" value="5" placeholder="Seuil min">
    </div>
    <div class="row" style="margin-top:8px;">
      <input id="fMarque" placeholder="Marque">
      <input id="fModele" placeholder="Modèle compatible">
      <input id="fPrix" type="number" step="0.01" min="0" value="0" placeholder="Prix unitaire HT">
      <input id="fEmplacement" placeholder="Emplacement">
      <button id="btnCreate" type="button">Ajouter</button>
    </div>
  </section>

  <section class="panel">
    <h3>Articles en stock</h3>
    <div style="overflow:auto">
      <table class="tbl">
        <thead><tr><th>ID</th><th>Référence</th><th>Désignation</th><th>Catégorie</th><th>Qté</th><th>Min</th><th>Prix HT</th><th>Actif</th><th>Mouvement</th></tr></thead>
        <tbody id="tbody"><tr><td colspan="9" class="muted">Chargement...</td></tr></tbody>
      </table>
    </div>
  </section>
</main>

<script <?= csp_nonce() ?>>
const csrf = document.body.dataset.csrfToken || '';
const tbody = document.getElementById('tbody');
const q = document.getElementById('q');
const categorie = document.getElementById('categorie');
const actif = document.getElementById('actif');

function esc(s){return String(s??'').replace(/[&<>"']/g,m=>({"&":"&amp;","<":"&lt;",">":"&gt;","\"":"&quot;","'":"&#39;"}[m]));}

async function loadStock(){
  const qs = new URLSearchParams();
  if(q.value.trim()) qs.set('q', q.value.trim());
  if(categorie.value) qs.set('categorie', categorie.value);
  if(actif.value !== '') qs.set('actif', actif.value);
  const res = await fetch('/API/stock_items.php?'+qs.toString(), {credentials:'include'});
  const data = await res.json();
  const items = (data && data.ok && Array.isArray(data.items)) ? data.items : [];
  if(items.length===0){tbody.innerHTML='<tr><td colspan="9" class="muted">Aucun article</td></tr>';return;}
  tbody.innerHTML = items.map(it => {
    const low = Number(it.quantite) <= Number(it.quantite_min);
    return `<tr>
      <td>${it.id}</td>
      <td>${esc(it.reference)}</td>
      <td>${esc(it.designation)}</td>
      <td>${esc(it.categorie)}</td>
      <td class="${low?'low':''}">${Number(it.quantite)}</td>
      <td>${Number(it.quantite_min)}</td>
      <td>${Number(it.prix_unitaire_ht||0).toFixed(2)} €</td>
      <td>${Number(it.actif)===1?'Oui':'Non'}</td>
      <td><button type="button" onclick="promptMvt(${Number(it.id)})">Mouvement</button></td>
    </tr>`;
  }).join('');
}

async function createItem(){
  const payload = {
    reference: document.getElementById('fReference').value.trim(),
    designation: document.getElementById('fDesignation').value.trim(),
    categorie: document.getElementById('fCategorie').value,
    quantite: parseInt(document.getElementById('fQuantite').value || '0', 10),
    quantite_min: parseInt(document.getElementById('fQuantiteMin').value || '5', 10),
    marque: document.getElementById('fMarque').value.trim(),
    modele_compatible: document.getElementById('fModele').value.trim(),
    prix_unitaire_ht: parseFloat(document.getElementById('fPrix').value || '0'),
    emplacement: document.getElementById('fEmplacement').value.trim(),
    actif: 1
  };
  const res = await fetch('/API/stock_items.php', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
    body: JSON.stringify(payload)
  });
  const d = await res.json();
  if(!d.ok){alert('Erreur: '+(d.error||'inconnue')); return;}
  await loadStock();
}

async function promptMvt(stockId){
  const type = prompt('Type mouvement (entree/sortie/ajustement/livraison)', 'entree');
  if(!type) return;
  const quantite = parseInt(prompt('Quantité', '1') || '0', 10);
  if(!quantite || quantite<=0) return;
  const motif = prompt('Motif (optionnel)', '') || '';
  const reference_doc = prompt('Référence doc (optionnel)', '') || '';
  const res = await fetch('/API/stock_mouvements.php', {
    method:'POST', credentials:'include',
    headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
    body: JSON.stringify({stock_id: stockId, type_mouvement:type, quantite, motif, reference_doc})
  });
  const d = await res.json();
  if(!d.ok){alert('Erreur: '+(d.error||'inconnue')); return;}
  await loadStock();
}
window.promptMvt = promptMvt;

document.getElementById('btnRefresh').addEventListener('click', loadStock);
document.getElementById('btnCreate').addEventListener('click', createItem);
[q,categorie,actif].forEach(el=>el.addEventListener('change', loadStock));
loadStock();
</script>
</body>
</html>

