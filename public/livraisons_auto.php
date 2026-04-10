<?php
// [Livraison Auto]
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/auth_role.php';
authorize_page('livraison', []);
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();
$role = (string)currentUserRole();
$isAdminOrDirigeant = in_array($role, ['Admin', 'Dirigeant'], true);
$csrf = ensureCsrfToken();
$today = date('Y-m-d');

$paperProducts = [];
try {
    $stmtP = $pdo->query("SELECT id, marque, designation AS modele FROM stock WHERE actif = 1 AND categorie='papier' ORDER BY designation");
    $paperProducts = $stmtP->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $paperProducts = [];
}

$clients = [];
try {
    $stmt = $pdo->query("
      SELECT c.id, c.raison_sociale, c.numero_client,
             lac.id AS config_id, COALESCE(lac.actif,0) AS actif,
             COALESCE(lac.papier_actif,0) AS papier_actif,
             lac.papier_product_id,
             COALESCE(lac.papier_seuil,5) AS papier_seuil,
             COALESCE(lac.papier_qte_livraison,10) AS papier_qte_livraison,
             COALESCE(lac.papier_qte_auto,1) AS papier_qte_auto,
             COALESCE(lac.toner_actif,0) AS toner_actif,
             COALESCE(lac.toner_seuil_pct,10) AS toner_seuil_pct,
             lac.derniere_verification,
             lac.derniere_livraison_creee
      FROM clients c
      LEFT JOIN livraison_auto_config lac ON lac.id_client = c.id
      WHERE COALESCE(c.statut, 'actif') = 'actif'
      ORDER BY c.raison_sociale ASC
    ");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    if (stripos($e->getMessage(), "Unknown column 'c.statut'") !== false) {
        $stmt = $pdo->query("
          SELECT c.id, c.raison_sociale, c.numero_client,
                 lac.id AS config_id, COALESCE(lac.actif,0) AS actif,
                 COALESCE(lac.papier_actif,0) AS papier_actif,
                 lac.papier_product_id,
                 COALESCE(lac.papier_seuil,5) AS papier_seuil,
                 COALESCE(lac.papier_qte_livraison,10) AS papier_qte_livraison,
                 COALESCE(lac.papier_qte_auto,1) AS papier_qte_auto,
                 COALESCE(lac.toner_actif,0) AS toner_actif,
                 COALESCE(lac.toner_seuil_pct,10) AS toner_seuil_pct,
                 lac.derniere_verification,
                 lac.derniere_livraison_creee
          FROM clients c
          LEFT JOIN livraison_auto_config lac ON lac.id_client = c.id
          ORDER BY c.raison_sociale ASC
        ");
        $clients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

$stats = ['auto_active' => 0, 'papier_alertes' => 0, 'toner_alertes' => 0, 'last_check' => '—'];
$stmtTonerA = $pdo->prepare("
  SELECT id, designation, quantite, quantite_min, couleur_toner
  FROM stock
  WHERE actif = 1
    AND categorie IN ('toner_noir','toner_cyan','toner_magenta','toner_jaune')
    AND quantite <= quantite_min
");
$stmtTonerA->execute();
$tonersEnAlerteGlobal = $stmtTonerA->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stmtPapierA = $pdo->prepare("
  SELECT id, designation, quantite, quantite_min, unite, contenance
  FROM stock
  WHERE actif = 1 AND categorie = 'papier' AND quantite <= quantite_min
");
$stmtPapierA->execute();
$papierEnAlerteGlobal = $stmtPapierA->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($clients as $idx => $c) {
    $idClient = (int)$c['id'];
    $stockStmt = $pdo->prepare("SELECT COALESCE(SUM(quantite),0) FROM stock WHERE actif = 1 AND categorie='papier'");
    $stockStmt->execute();
    $stockPapier = (int)$stockStmt->fetchColumn();

    $avgStmt = $pdo->prepare("
      SELECT
        ROUND(COALESCE((MAX(cr.TotalBW)-MIN(cr.TotalBW))/NULLIF(DATEDIFF(MAX(cr.Timestamp),MIN(cr.Timestamp)),0),0),1) AS avg_bw_per_day,
        ROUND(COALESCE((MAX(cr.TotalColor)-MIN(cr.TotalColor))/NULLIF(DATEDIFF(MAX(cr.Timestamp),MIN(cr.Timestamp)),0),0),1) AS avg_color_per_day
      FROM photocopieurs_clients pc
      JOIN (
        SELECT mac_norm, TotalBW, TotalColor, Timestamp FROM compteur_relevee WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        UNION ALL
        SELECT mac_norm, TotalBW, TotalColor, Timestamp FROM compteur_relevee_ancien WHERE Timestamp >= DATE_SUB(NOW(), INTERVAL 30 DAY)
      ) cr ON cr.mac_norm = pc.mac_norm
      WHERE pc.id_client = :id_client
      GROUP BY pc.id_client
    ");
    $avgStmt->execute([':id_client' => $idClient]);
    $avg = $avgStmt->fetch(PDO::FETCH_ASSOC) ?: ['avg_bw_per_day' => 0, 'avg_color_per_day' => 0];
    $avgBw = (float)($avg['avg_bw_per_day'] ?? 0);
    $avgColor = (float)($avg['avg_color_per_day'] ?? 0);
    $joursRestants = $avgBw > 0 ? round(($stockPapier * 500) / $avgBw, 1) : null;
    $qteReco = $avgBw > 0 ? max(1, (int)ceil($avgBw * 7 / 500)) : null;

    $toners = [
      'black' => null,
      'cyan' => null,
      'magenta' => null,
      'yellow' => null,
    ];
    foreach ($tonersEnAlerteGlobal as $ta) {
      if (($ta['categorie'] ?? '') === 'toner_noir') $toners['black'] = (int)$ta['quantite'];
      if (($ta['categorie'] ?? '') === 'toner_cyan') $toners['cyan'] = (int)$ta['quantite'];
      if (($ta['categorie'] ?? '') === 'toner_magenta') $toners['magenta'] = (int)$ta['quantite'];
      if (($ta['categorie'] ?? '') === 'toner_jaune') $toners['yellow'] = (int)$ta['quantite'];
    }

    $papierSeuil = (int)$c['papier_seuil'];
    $tonerSeuil = (int)$c['toner_seuil_pct'];
    $papierAlerte = ((int)$c['papier_actif'] === 1) && ($stockPapier <= $papierSeuil);
    $tonerAlerte = ((int)$c['toner_actif'] === 1) && !empty($tonersEnAlerteGlobal);

    if ((int)$c['actif'] === 1) $stats['auto_active']++;
    if ($papierAlerte) $stats['papier_alertes']++;
    if ($tonerAlerte) $stats['toner_alertes']++;
    if (!empty($c['derniere_verification'])) $stats['last_check'] = formatDateTime((string)$c['derniere_verification']);

    $clients[$idx]['stock_papier'] = $stockPapier;
    $clients[$idx]['avg_bw_per_day'] = $avgBw;
    $clients[$idx]['avg_color_per_day'] = $avgColor;
    $clients[$idx]['jours_stock_restant'] = $joursRestants;
    $clients[$idx]['qte_recommandee'] = $qteReco;
    $clients[$idx]['papier_alerte'] = $papierAlerte;
    $clients[$idx]['toner_alerte'] = $tonerAlerte;
    $clients[$idx]['toners_json'] = json_encode($toners);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Livraisons automatiques - CCComputer</title>
  <link rel="icon" type="image/png" href="/assets/logos/logo.png">
  <link rel="stylesheet" href="/assets/css/main.css" />
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<div class="page-container">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:14px;">
    <h2>Livraisons automatiques</h2>
    <div style="display:flex;gap:8px;">
      <button type="button" class="btn btn-primary" id="btnOpenAutoConfig">+ Configurer un client</button>
      <button type="button" class="btn btn-outline" id="btnRunNow">▶ Vérifier maintenant</button>
    </div>
  </div>

  <section class="clients-meta">
    <div class="meta-card"><span class="meta-label">Clients auto actifs</span><strong class="meta-value"><?= h((string)$stats['auto_active']) ?></strong></div>
    <div class="meta-card"><span class="meta-label">Alertes papier</span><strong class="meta-value"><?= h((string)$stats['papier_alertes']) ?></strong></div>
    <div class="meta-card"><span class="meta-label">Alertes toner</span><strong class="meta-value"><?= h((string)$stats['toner_alertes']) ?></strong></div>
    <div class="meta-card"><span class="meta-label">Dernière vérification</span><strong class="meta-value"><?= h((string)$stats['last_check']) ?></strong></div>
  </section>

  <div class="table-wrapper">
    <table class="tbl-livraisons" id="tbl_auto">
      <thead>
        <tr>
          <th>Client</th><th>Auto</th><th>Stock papier</th><th>Jours restants</th><th>Conso/jour</th><th>Qté recommandée</th><th>Toners</th><th>Statut</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($clients as $c): ?>
          <?php
            $stock = (int)$c['stock_papier'];
            $seuil = (int)$c['papier_seuil'];
            $ratio = $seuil > 0 ? min(100, (int)round(($stock / max(1, $seuil * 2)) * 100)) : 0;
            $bar = $stock <= $seuil ? '#ef4444' : ($stock <= ($seuil * 2) ? '#f59e0b' : '#22c55e');
            $jours = $c['jours_stock_restant'];
            $joursColor = $jours === null ? '#9ca3af' : ($jours < 3 ? '#ef4444' : ($jours < 7 ? '#f59e0b' : '#22c55e'));
            $qteReco = $c['qte_recommandee'];
            $toners = json_decode((string)$c['toners_json'], true) ?: [];
            $hasAlerte = !empty($c['papier_alerte']) || !empty($c['toner_alerte']);
          ?>
          <tr
            data-client-id="<?= (int)$c['id'] ?>"
            data-client-name="<?= h((string)$c['raison_sociale']) ?>"
            data-config='<?= h(json_encode($c, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'
          >
            <td><?= h((string)$c['raison_sociale']) ?></td>
            <td><input type="checkbox" class="toggle-auto" <?= ((int)$c['actif'] === 1) ? 'checked' : '' ?>></td>
            <td>
              <div><?= h((string)$stock) ?> ramettes</div>
              <div style="height:7px;background:#e5e7eb;border-radius:6px;overflow:hidden;"><span style="display:block;height:100%;width:<?= $ratio ?>%;background:<?= $bar ?>"></span></div>
            </td>
            <td style="color:<?= $joursColor ?>;"><?= $jours === null ? '— Pas de données' : h((string)$jours) . ' j' ?></td>
            <td>
              <div><?= h((string)$c['avg_bw_per_day']) ?> p/j</div>
              <div style="color:#2563eb;"><?= h((string)$c['avg_color_per_day']) ?> c/j</div>
            </td>
            <td><?= $qteReco === null ? '—' : h((string)$qteReco) . ' ramettes' ?></td>
            <td>
              <?php foreach (['black' => 'N', 'cyan' => 'C', 'magenta' => 'M', 'yellow' => 'J'] as $k => $lbl): ?>
                <?php $v = isset($toners[$k]) ? (int)$toners[$k] : null; if ($v === null) continue; ?>
                <span style="display:inline-block;padding:2px 6px;border-radius:4px;margin:1px;color:#fff;background:<?= ($v <= (int)$c['toner_seuil_pct'] && $v > 0) ? '#ef4444' : '#374151' ?>;"><?= $lbl ?> <?= $v ?>%</span>
              <?php endforeach; ?>
            </td>
            <td>
              <?php if ((int)$c['actif'] === 0): ?>
                ⚪ Désactivé
              <?php elseif ($hasAlerte): ?>
                🔴 Livraison requise
              <?php else: ?>
                🟡 Sous surveillance
              <?php endif; ?>
            </td>
            <td>
              <button type="button" class="btn btn-outline btn-config-client">⚙ Configurer</button>
              <?php if ($hasAlerte): ?>
                <button type="button" class="btn btn-outline btn-run-now-client">📦 Créer livraison</button>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="autoConfigOverlay" class="popup-overlay" style="display:none;" aria-hidden="true"></div>
<div id="autoConfigModal" class="support-popup" style="display:none;max-width:700px;" role="dialog" aria-modal="true">
  <h3 id="autoConfigTitle" style="margin-top:0;">Configuration auto</h3>
  <form id="autoConfigForm" class="standard-form">
    <input type="hidden" name="id_client" id="cfg_id_client">
    <div class="subsection-title">Général</div>
    <label><input type="checkbox" name="actif" id="cfg_actif" value="1"> Activer la surveillance automatique</label>
    <div class="subsection-title">Papier</div>
    <label><input type="checkbox" name="papier_actif" id="cfg_papier_actif" value="1"> Surveiller le papier</label>
    <label>Produit papier</label>
    <select name="papier_product_id" id="cfg_papier_product_id">
      <option value="">— Sélectionner —</option>
      <?php foreach ($paperProducts as $pp): ?>
        <option value="<?= (int)$pp['id'] ?>"><?= h(trim(($pp['marque'] ?? '') . ' ' . ($pp['modele'] ?? ''))) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Seuil d'alerte (ramettes)</label><input type="number" name="papier_seuil" id="cfg_papier_seuil" min="1" value="5">
    <label>Quantité de livraison</label>
    <label><input type="radio" name="papier_qte_auto" value="1" checked> Automatique (basée sur la consommation : <span id="cfg_reco_txt">—</span>)</label>
    <label><input type="radio" name="papier_qte_auto" value="0"> Manuel</label>
    <input type="number" name="papier_qte_livraison" id="cfg_papier_qte_livraison" min="1" value="10">
    <div id="cfg_conso_info" style="font-size:0.82rem;color:#6b7280;"></div>
    <div class="subsection-title">Toners</div>
    <label><input type="checkbox" name="toner_actif" id="cfg_toner_actif" value="1"> Surveiller les toners</label>
    <label>Seuil d'alerte toner (%)</label><input type="number" name="toner_seuil_pct" id="cfg_toner_seuil_pct" min="5" max="30" value="10">
    <div style="font-size:0.82rem;color:#6b7280;">Une livraison sera créée automatiquement quand un toner passe sous X%.</div>
    <div class="modal-actions"><button type="button" class="btn btn-secondary" id="btnCloseAutoConfig">Annuler</button><button type="submit" class="btn btn-primary">Enregistrer la configuration</button></div>
  </form>
</div>

<script <?= csp_nonce() ?>>
(function(){
  const csrf = <?= json_encode($csrf) ?>;
  const runBtn = document.getElementById('btnRunNow');
  const openBtn = document.getElementById('btnOpenAutoConfig');
  const ov = document.getElementById('autoConfigOverlay');
  const md = document.getElementById('autoConfigModal');
  const closeBtn = document.getElementById('btnCloseAutoConfig');
  const title = document.getElementById('autoConfigTitle');
  const form = document.getElementById('autoConfigForm');
  const recoTxt = document.getElementById('cfg_reco_txt');
  const consoInfo = document.getElementById('cfg_conso_info');

  function openModal(){ document.body.classList.add('modal-open'); ov.style.display='block'; ov.setAttribute('aria-hidden','false'); md.style.display='block'; }
  function closeModal(){ document.body.classList.remove('modal-open'); ov.style.display='none'; ov.setAttribute('aria-hidden','true'); md.style.display='none'; }
  if (openBtn) openBtn.addEventListener('click', () => { title.textContent = 'Configuration auto'; form.reset(); openModal(); });
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  if (ov) ov.addEventListener('click', closeModal);

  document.querySelectorAll('.toggle-auto').forEach(inp => {
    inp.addEventListener('change', function() {
      const tr = this.closest('tr'); if (!tr) return;
      const idClient = tr.dataset.clientId || '';
      fetch('/API/livraisons/auto_toggle.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify({id_client:idClient, actif:this.checked ? 1 : 0, csrf_token: csrf})
      }).then(r => r.json()).then(d => { if (!d || !d.success) alert('Erreur toggle'); });
    });
  });

  function fillFromConfig(cfg) {
    const setVal = (id,v) => { const el = document.getElementById(id); if (el) el.value = v ?? ''; };
    const setChk = (id,v) => { const el = document.getElementById(id); if (el) el.checked = Number(v) === 1; };
    setVal('cfg_id_client', cfg.id || '');
    setChk('cfg_actif', cfg.actif);
    setChk('cfg_papier_actif', cfg.papier_actif);
    setVal('cfg_papier_product_id', cfg.papier_product_id || '');
    setVal('cfg_papier_seuil', cfg.papier_seuil || 5);
    setVal('cfg_papier_qte_livraison', cfg.papier_qte_livraison || 10);
    setChk('cfg_toner_actif', cfg.toner_actif);
    setVal('cfg_toner_seuil_pct', cfg.toner_seuil_pct || 10);
    const autoRadios = form.querySelectorAll('input[name="papier_qte_auto"]');
    autoRadios.forEach(r => { r.checked = String(cfg.papier_qte_auto || 1) === r.value; });
    const avg = parseFloat(cfg.avg_bw_per_day || 0);
    const reco = avg > 0 ? Math.max(1, Math.ceil((avg * 7) / 500)) : null;
    recoTxt.textContent = reco ? (reco + ' ramettes') : '— Pas de données';
    consoInfo.textContent = avg > 0 ? ('Ce client consomme ~' + avg + ' pages/jour = ~' + reco + ' ramettes/semaine') : '— Pas de données';
  }

  document.querySelectorAll('.btn-config-client').forEach(btn => {
    btn.addEventListener('click', function() {
      const tr = this.closest('tr'); if (!tr) return;
      const cfg = JSON.parse(tr.dataset.config || '{}');
      title.textContent = 'Configuration auto — ' + (tr.dataset.clientName || '');
      fillFromConfig(cfg);
      openModal();
    });
  });

  if (form) {
    form.addEventListener('submit', function(e) {
      e.preventDefault();
      const fd = new FormData(form);
      const payload = {};
      fd.forEach((v,k) => { payload[k] = v; });
      ['actif','papier_actif','toner_actif'].forEach(k => { payload[k] = form.querySelector('[name="'+k+'"]').checked ? 1 : 0; });
      const autoRadio = form.querySelector('input[name="papier_qte_auto"]:checked');
      payload.papier_qte_auto = autoRadio ? (autoRadio.value === '1' ? 1 : 0) : 1;
      payload.csrf_token = csrf;
      fetch('/API/livraisons/auto_config_save.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify(payload)
      }).then(r => r.json()).then(d => {
        if (d && d.success) location.reload();
        else alert((d && d.error) ? d.error : 'Erreur sauvegarde');
      });
    });
  }

  if (runBtn) {
    runBtn.addEventListener('click', function() {
      fetch('/API/livraisons/auto_run.php', {
        method:'POST',
        credentials:'same-origin',
        headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},
        body: JSON.stringify({csrf_token: csrf})
      }).then(r => r.json()).then(d => {
        if (d && d.success) {
          alert(d.message || 'Vérification terminée');
          location.reload();
          return;
        }
        alert((d && d.error) ? d.error : 'Erreur de vérification');
      });
    });
  }

  document.querySelectorAll('.btn-run-now-client').forEach(btn => {
    btn.addEventListener('click', function() {
      if (runBtn) runBtn.click();
    });
  });
})();
</script>
</body>
</html>
