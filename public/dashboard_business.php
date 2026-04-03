<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('dashboard_business', ['Admin', 'Dirigeant']);
require_once __DIR__ . '/../includes/helpers.php';
ensureCsrfToken();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Pilotage — CC Computer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png" />
  <link rel="stylesheet" href="/assets/css/dashboard.css" />
  <style>
    .biz-page { max-width: 1200px; margin: 0 auto; padding: 1rem 1.25rem 3rem; }
    .biz-hero { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
    .biz-hero h1 { font-size: 1.5rem; margin: 0; }
    .biz-hero p { margin: 0.25rem 0 0; color: var(--muted, #64748b); font-size: 0.95rem; }
    .biz-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
    .biz-card {
      background: var(--card-bg, #fff); border: 1px solid var(--border, #e2e8f0); border-radius: 12px;
      padding: 1rem 1.1rem; box-shadow: 0 1px 2px rgba(0,0,0,.04);
    }
    [data-theme="dark"] .biz-card { background: #1e293b; border-color: #334155; }
    .biz-card .label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: .04em; color: var(--muted, #64748b); margin-bottom: 0.35rem; }
    .biz-card .value { font-size: 1.45rem; font-weight: 700; line-height: 1.2; }
    .biz-card.warn .value { color: #b45309; }
    .biz-card.danger .value { color: #b91c1c; }
    .biz-section { margin-top: 2rem; }
    .biz-section h2 { font-size: 1.1rem; margin: 0 0 0.75rem; }
    .biz-table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
    .biz-table th, .biz-table td { text-align: left; padding: 0.5rem 0.6rem; border-bottom: 1px solid var(--border, #e2e8f0); }
    .biz-muted { color: var(--muted, #64748b); font-size: 0.85rem; }
    .biz-loading { padding: 2rem; text-align: center; color: var(--muted, #64748b); }
    .biz-error { background: #fef2f2; color: #991b1b; padding: 0.75rem 1rem; border-radius: 8px; margin-bottom: 1rem; }
    [data-theme="dark"] .biz-error { background: #450a0a; color: #fecaca; }
  </style>
</head>
<body data-csrf-token="<?= h($_SESSION['csrf_token'] ?? '') ?>">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>
  <main class="biz-page">
    <div class="biz-hero">
      <div>
        <h1>Pilotage & trésorerie</h1>
        <p>Vue synthétique pour suivre le cash, les impayés et l’activité opérationnelle.</p>
      </div>
      <span class="biz-muted" id="biz-updated"></span>
    </div>
    <div id="biz-error" class="biz-error" style="display:none;"></div>
    <div id="biz-root" class="biz-loading">Chargement des indicateurs…</div>
  </main>
  <script <?= csp_nonce() ?>>
(function(){
  const csrf = document.body.getAttribute('data-csrf-token') || '';
  const root = document.getElementById('biz-root');
  const errEl = document.getElementById('biz-error');
  const updatedEl = document.getElementById('biz-updated');

  function eur(n) {
    return new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' }).format(Number(n) || 0);
  }

  function showError(msg) {
    errEl.textContent = msg;
    errEl.style.display = 'block';
  }

  fetch('/API/business_dashboard_stats.php', { credentials: 'same-origin' })
    .then(r => r.json())
    .then(data => {
      if (!data.ok) {
        root.innerHTML = '';
        showError(data.error || 'Impossible de charger les données');
        return;
      }
      const k = data.kpis;
      updatedEl.textContent = 'Mis à jour : ' + new Date().toLocaleString('fr-FR');

      root.innerHTML = `
        <div class="biz-grid">
          <div class="biz-card"><div class="label">CA semaine glissante</div><div class="value">${eur(k.ca_semaine)}</div></div>
          <div class="biz-card"><div class="label">CA mois calendaire</div><div class="value">${eur(k.ca_mois_calendaire)}</div></div>
          <div class="biz-card warn"><div class="label">Impayé total</div><div class="value">${eur(k.factures_impayees_montant)}</div><div class="biz-muted">${k.factures_impayees_count} facture(s)</div></div>
          <div class="biz-card danger"><div class="label">En retard (après échéance)</div><div class="value">${eur(k.factures_retard_montant)}</div><div class="biz-muted">${k.factures_retard_count} facture(s)</div></div>
          <div class="biz-card"><div class="label">Prévision échéances 7 j.</div><div class="value">${eur(k.prevision_encaissement_7j)}</div><div class="biz-muted">Restant dû sur factures à échéance proche</div></div>
          <div class="biz-card"><div class="label">SAV ouverts / en cours</div><div class="value">${k.sav_ouverts}</div></div>
          <div class="biz-card warn"><div class="label">Alertes stock (seuils)</div><div class="value">${k.stock_alertes}</div><div class="biz-muted">Articles papier ≤5 ou toner ≤3</div></div>
          <div class="biz-card"><div class="label">Résumé</div><div class="biz-muted" style="margin-top:.5rem;line-height:1.5">Priorité : traiter les retards et valider les encaissements attendus sur la semaine.</div></div>
        </div>
        <div class="biz-section">
          <h2>Top clients (90 j., encaissements)</h2>
          <table class="biz-table"><thead><tr><th>Client</th><th>Montant</th></tr></thead><tbody>
            ${(k.top_clients_90j || []).map(r => `<tr><td>${escapeHtml(r.raison_sociale || '')}</td><td>${eur(r.total)}</td></tr>`).join('') || '<tr><td colspan="2" class="biz-muted">Aucune donnée</td></tr>'}
          </tbody></table>
        </div>
        <div class="biz-section">
          <h2>Derniers encaissements</h2>
          <table class="biz-table"><thead><tr><th>Date</th><th>Client</th><th>Montant</th></tr></thead><tbody>
            ${(k.encaissements_recents || []).map(r => `<tr><td>${escapeHtml(r.date_paiement || '')}</td><td>${escapeHtml(r.raison_sociale || '—')}</td><td>${eur(r.montant)}</td></tr>`).join('') || '<tr><td colspan="3" class="biz-muted">Aucun</td></tr>'}
          </tbody></table>
        </div>
        <div class="biz-section">
          <h2>Activité récente</h2>
          <table class="biz-table"><thead><tr><th>Date</th><th>Action</th><th>Détail</th></tr></thead><tbody>
            ${(k.activite_recente || []).map(r => `<tr><td>${escapeHtml(r.date_action || '')}</td><td>${escapeHtml(r.action || '')}</td><td>${escapeHtml((r.details || '').slice(0, 120))}</td></tr>`).join('') || '<tr><td colspan="3" class="biz-muted">Aucune</td></tr>'}
          </tbody></table>
        </div>
      `;
    })
    .catch(() => {
      root.innerHTML = '';
      showError('Erreur réseau');
    });

  function escapeHtml(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }
})();
  </script>
</body>
</html>
