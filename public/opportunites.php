<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('opportunites', ['Admin', 'Dirigeant', 'Chargé relation clients']);
require_once __DIR__ . '/../includes/helpers.php';
ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Opportunités — CC Computer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png" />
  <link rel="stylesheet" href="/assets/css/dashboard.css" />
  <style>
    .opp-page { max-width: 960px; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
    .opp-page h1 { font-size: 1.4rem; margin: 0 0 0.5rem; }
    .opp-filters { margin: 1rem 0; display: flex; gap: 0.5rem; flex-wrap: wrap; }
    .opp-filters button { padding: 0.35rem 0.7rem; border-radius: 8px; border: 1px solid #cbd5e1; background: transparent; cursor: pointer; }
    .opp-filters button.active { background: #0f172a; color: #fff; border-color: #0f172a; }
    [data-theme="dark"] .opp-filters button.active { background: #38bdf8; color: #0f172a; border: none; }
    .opp-card { border: 1px solid #e2e8f0; border-radius: 12px; padding: 1rem; margin-bottom: 0.75rem; background: var(--card-bg, #fff); }
    [data-theme="dark"] .opp-card { border-color: #334155; background: #1e293b; }
    .opp-card h3 { margin: 0 0 0.35rem; font-size: 1rem; }
    .opp-meta { font-size: 0.8rem; color: #64748b; margin-bottom: 0.5rem; }
    .opp-detail { font-size: 0.9rem; line-height: 1.45; margin-bottom: 0.75rem; }
    .opp-actions { display: flex; flex-wrap: wrap; gap: 0.4rem; }
    .opp-actions button { font-size: 0.8rem; padding: 0.25rem 0.55rem; border-radius: 6px; border: 1px solid #cbd5e1; cursor: pointer; background: #f8fafc; }
    [data-theme="dark"] .opp-actions button { background: #334155; border-color: #475569; color: #e2e8f0; }
    .opp-st { display: inline-block; padding: 0.12rem 0.4rem; border-radius: 4px; font-size: 0.72rem; text-transform: uppercase; }
    .opp-st-nouveau { background: #dbeafe; color: #1e40af; }
    .opp-st-vu { background: #fef3c7; color: #92400e; }
    .opp-st-converti { background: #d1fae5; color: #065f46; }
    .opp-st-ignore { background: #f3f4f6; color: #4b5563; }
  </style>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>
  <main class="opp-page">
    <h1>Opportunités commerciales</h1>
    <p class="muted">Suggestions générées automatiquement (cron) à partir de l’activité SAV, facturation et encaissements.</p>
    <div class="opp-filters" id="opp-filters">
      <button type="button" data-f="" class="active">Toutes</button>
      <button type="button" data-f="nouveau">Nouveau</button>
      <button type="button" data-f="vu">Vu</button>
      <button type="button" data-f="converti">Converti</button>
      <button type="button" data-f="ignore">Ignoré</button>
    </div>
    <div id="opp-root">Chargement…</div>
  </main>
  <script <?= csp_nonce() ?>>
(function(){
  const csrf = document.body.getAttribute('data-csrf-token') || '';
  const root = document.getElementById('opp-root');
  let filter = '';

  function load() {
    const q = filter ? ('?statut=' + encodeURIComponent(filter)) : '';
    fetch('/API/opportunites_list.php' + q, { credentials: 'same-origin' })
      .then(r => r.json())
      .then(d => {
        if (!d.ok) { root.textContent = d.error || 'Erreur'; return; }
        if (!d.items.length) { root.innerHTML = '<p class="muted">Aucune opportunité.</p>'; return; }
        root.innerHTML = d.items.map(render).join('');
        root.querySelectorAll('[data-stat]').forEach(btn => {
          btn.addEventListener('click', () => setStat(btn.getAttribute('data-id'), btn.getAttribute('data-stat')));
        });
      })
      .catch(() => { root.textContent = 'Erreur réseau'; });
  }

  function render(it) {
    const st = it.statut || 'nouveau';
    return `<article class="opp-card">
      <span class="opp-st opp-st-${st}">${escapeHtml(st)}</span>
      <h3>${escapeHtml(it.titre)}</h3>
      <div class="opp-meta"><a href="/public/client_fiche.php?id=${it.id_client}">Client #${it.id_client} — ${escapeHtml(it.raison_sociale || '')}</a> · ${escapeHtml(it.rule_code)}</div>
      <div class="opp-detail">${escapeHtml(it.detail || '')}</div>
      <div class="opp-actions">
        <button type="button" data-id="${it.id}" data-stat="vu">Marquer vu</button>
        <button type="button" data-id="${it.id}" data-stat="converti">Converti</button>
        <button type="button" data-id="${it.id}" data-stat="ignore">Ignorer</button>
      </div>
    </article>`;
  }

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function setStat(id, statut) {
    fetch('/API/opportunites_statut.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
      body: JSON.stringify({ id: parseInt(id, 10), statut })
    })
      .then(r => r.json())
      .then(d => { if (d.ok) load(); else alert(d.error || 'Erreur'); })
      .catch(() => alert('Erreur réseau'));
  }

  document.getElementById('opp-filters').addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-f]');
    if (!btn) return;
    filter = btn.getAttribute('data-f') || '';
    document.querySelectorAll('#opp-filters button').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    load();
  });

  load();
})();
  </script>
</body>
</html>
