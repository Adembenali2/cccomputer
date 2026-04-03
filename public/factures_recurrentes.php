<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('factures_recurrentes', ['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/helpers.php';
ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Factures récurrentes — CC Computer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png" />
  <link rel="stylesheet" href="/assets/css/dashboard.css" />
  <style>
    .rec-page { max-width: 1000px; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
    .rec-page h1 { font-size: 1.4rem; margin: 0 0 0.5rem; }
    .rec-toolbar { display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: center; margin: 1rem 0; }
    .rec-toolbar button { padding: 0.45rem 0.9rem; border-radius: 8px; border: 1px solid #cbd5e1; background: #0f172a; color: #fff; cursor: pointer; font-size: 0.9rem; }
    [data-theme="dark"] .rec-toolbar button { background: #38bdf8; color: #0f172a; border: none; }
    .rec-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
    .rec-table th, .rec-table td { text-align: left; padding: 0.45rem 0.5rem; border-bottom: 1px solid #e2e8f0; vertical-align: top; }
    [data-theme="dark"] .rec-table th, [data-theme="dark"] .rec-table td { border-color: #334155; }
    .rec-badge { display: inline-block; padding: 0.15rem 0.45rem; border-radius: 6px; font-size: 0.75rem; }
    .rec-badge.on { background: #dcfce7; color: #166534; }
    .rec-badge.off { background: #fee2e2; color: #991b1b; }
    .rec-form { display: grid; gap: 0.6rem; max-width: 480px; margin-top: 1rem; }
    .rec-form label { display: flex; flex-direction: column; gap: 0.2rem; font-size: 0.85rem; }
    .rec-form input, .rec-form select { padding: 0.4rem 0.5rem; border-radius: 6px; border: 1px solid #cbd5e1; }
    .rec-modal-bg { display: none; position: fixed; inset: 0; background: rgba(15,23,42,.45); z-index: 1000; align-items: center; justify-content: center; padding: 1rem; }
    .rec-modal-bg.open { display: flex; }
    .rec-modal { background: var(--card-bg, #fff); border-radius: 12px; padding: 1.25rem; max-width: 520px; width: 100%; max-height: 90vh; overflow: auto; border: 1px solid #e2e8f0; }
    [data-theme="dark"] .rec-modal { background: #1e293b; border-color: #334155; }
    .rec-msg { padding: 0.6rem 0.75rem; border-radius: 8px; margin: 0.5rem 0; font-size: 0.9rem; }
    .rec-msg.err { background: #fef2f2; color: #991b1b; }
    .rec-msg.ok { background: #ecfdf5; color: #065f46; }
  </style>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>
  <main class="rec-page">
    <h1>Factures récurrentes</h1>
    <p class="muted">Programmation mensuelle, trimestrielle ou annuelle — génération automatique par le cron <code>cron/run_recurring_invoices.php</code>.</p>
    <div id="rec-flash"></div>
    <div class="rec-toolbar">
      <button type="button" id="rec-add">Nouvelle programmation</button>
    </div>
    <table class="rec-table">
      <thead>
        <tr>
          <th>Client</th>
          <th>Libellé</th>
          <th>Montant HT</th>
          <th>Fréquence</th>
          <th>Prochaine</th>
          <th>Statut</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="rec-body"><tr><td colspan="7">Chargement…</td></tr></tbody>
    </table>
  </main>

  <div class="rec-modal-bg" id="rec-modal-bg" aria-hidden="true">
    <div class="rec-modal" role="dialog">
      <h2 style="margin-top:0;font-size:1.1rem" id="rec-modal-title">Programmation</h2>
      <form id="rec-form" class="rec-form">
        <input type="hidden" name="id" id="rec-id" value="" />
        <label>ID client *<input type="number" name="id_client" id="rec-client" required min="1" /></label>
        <label>Libellé interne *<input type="text" name="libelle" id="rec-libelle" required /></label>
        <label>Description ligne facture *<input type="text" name="description_ligne" id="rec-desc" required /></label>
        <label>Montant HT *<input type="number" step="0.01" name="montant_ht" id="rec-ht" required /></label>
        <label>TVA %<input type="number" step="0.01" name="tva_pct" id="rec-tva" value="20" /></label>
        <label>Type facture
          <select name="type_facture" id="rec-type"><option>Service</option><option>Consommation</option><option>Achat</option></select>
        </label>
        <label>Type ligne
          <select name="ligne_type" id="rec-ltype"><option>Service</option><option>Produit</option><option>N&B</option><option>Couleur</option></select>
        </label>
        <label>Fréquence
          <select name="frequence" id="rec-freq"><option value="mensuel">Mensuel</option><option value="trimestriel">Trimestriel</option><option value="annuel">Annuel</option></select>
        </label>
        <label>Jour du mois (1–28)<input type="number" name="jour_mois" id="rec-jour" value="1" min="1" max="28" /></label>
        <label>Prochaine échéance *<input type="date" name="prochaine_echeance" id="rec-next" required /></label>
        <label><input type="checkbox" name="actif" id="rec-actif" checked /> Actif</label>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button type="submit">Enregistrer</button>
          <button type="button" id="rec-cancel">Annuler</button>
        </div>
      </form>
    </div>
  </div>

  <script>
(function(){
  const csrf = document.body.getAttribute('data-csrf-token') || '';
  const body = document.getElementById('rec-body');
  const flash = document.getElementById('rec-flash');
  const modalBg = document.getElementById('rec-modal-bg');
  const form = document.getElementById('rec-form');

  function toast(msg, ok) {
    flash.innerHTML = '<div class="rec-msg ' + (ok ? 'ok' : 'err') + '">' + msg + '</div>';
    setTimeout(() => { flash.innerHTML = ''; }, 5000);
  }

  function load() {
    fetch('/API/factures_recurrentes_list.php', { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { body.innerHTML = '<tr><td colspan="7">' + (d.error || 'Erreur') + '</td></tr>'; return; }
        if (!d.items.length) { body.innerHTML = '<tr><td colspan="7">Aucune programmation</td></tr>'; return; }
        body.innerHTML = d.items.map(it => `
          <tr>
            <td>#${it.id_client} ${escapeHtml(it.raison_sociale || '')}</td>
            <td>${escapeHtml(it.libelle)}</td>
            <td>${Number(it.montant_ht).toFixed(2)} €</td>
            <td>${escapeHtml(it.frequence)}</td>
            <td>${escapeHtml(it.prochaine_echeance)}</td>
            <td><span class="rec-badge ${it.actif == 1 ? 'on' : 'off'}">${it.actif == 1 ? 'Actif' : 'Inactif'}</span></td>
            <td><button type="button" data-edit="${it.id}">Modifier</button></td>
          </tr>
        `).join('');
        body.querySelectorAll('[data-edit]').forEach(btn => {
          btn.addEventListener('click', () => openEdit(d.items.find(x => String(x.id) === btn.getAttribute('data-edit'))));
        });
      })
      .catch(() => { body.innerHTML = '<tr><td colspan="7">Erreur réseau</td></tr>'; });
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function openNew() {
    document.getElementById('rec-modal-title').textContent = 'Nouvelle programmation';
    form.reset();
    document.getElementById('rec-id').value = '';
    document.getElementById('rec-actif').checked = true;
    modalBg.classList.add('open');
  }

  function openEdit(it) {
    document.getElementById('rec-modal-title').textContent = 'Modifier #' + it.id;
    document.getElementById('rec-id').value = it.id;
    document.getElementById('rec-client').value = it.id_client;
    document.getElementById('rec-libelle').value = it.libelle;
    document.getElementById('rec-desc').value = it.description_ligne;
    document.getElementById('rec-ht').value = it.montant_ht;
    document.getElementById('rec-tva').value = it.tva_pct;
    document.getElementById('rec-type').value = it.type_facture;
    document.getElementById('rec-ltype').value = it.ligne_type;
    document.getElementById('rec-freq').value = it.frequence;
    document.getElementById('rec-jour').value = it.jour_mois;
    document.getElementById('rec-next').value = it.prochaine_echeance;
    document.getElementById('rec-actif').checked = it.actif == 1;
    modalBg.classList.add('open');
  }

  document.getElementById('rec-add').addEventListener('click', openNew);
  document.getElementById('rec-cancel').addEventListener('click', () => modalBg.classList.remove('open'));
  modalBg.addEventListener('click', (e) => { if (e.target === modalBg) modalBg.classList.remove('open'); });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const payload = {
      id: document.getElementById('rec-id').value ? parseInt(document.getElementById('rec-id').value, 10) : undefined,
      id_client: parseInt(document.getElementById('rec-client').value, 10),
      libelle: document.getElementById('rec-libelle').value,
      description_ligne: document.getElementById('rec-desc').value,
      montant_ht: parseFloat(document.getElementById('rec-ht').value),
      tva_pct: parseFloat(document.getElementById('rec-tva').value),
      type_facture: document.getElementById('rec-type').value,
      ligne_type: document.getElementById('rec-ltype').value,
      frequence: document.getElementById('rec-freq').value,
      jour_mois: parseInt(document.getElementById('rec-jour').value, 10),
      prochaine_echeance: document.getElementById('rec-next').value,
      actif: document.getElementById('rec-actif').checked
    };
    fetch('/API/factures_recurrentes_save.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify(payload)
    })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { toast(d.error || 'Erreur', false); return; }
        toast('Enregistré', true);
        modalBg.classList.remove('open');
        load();
      })
      .catch(() => toast('Erreur réseau', false));
  });

  load();
})();
  </script>
</body>
</html>
