<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

function colExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$userNom = (string)($_SESSION['nom'] ?? $_SESSION['user_nom'] ?? 'Utilisateur');
$userRole = (string)($_SESSION['role'] ?? $_SESSION['emploi'] ?? '');

$clientNameCol = colExists($pdo, 'clients', 'nom') ? 'nom' : (colExists($pdo, 'clients', 'raison_sociale') ? 'raison_sociale' : 'id');
$clientEmailCol = colExists($pdo, 'clients', 'email') ? 'email' : (colExists($pdo, 'clients', 'mail') ? 'mail' : null);
$clientPhoneCol = colExists($pdo, 'clients', 'telephone') ? 'telephone' : (colExists($pdo, 'clients', 'tel') ? 'tel' : null);
$clientCityCol = colExists($pdo, 'clients', 'ville') ? 'ville' : null;
$clientStatusCol = colExists($pdo, 'clients', 'statut') ? 'statut' : (colExists($pdo, 'clients', 'status') ? 'status' : null);
$clientCreatedCol = colExists($pdo, 'clients', 'created_at') ? 'created_at' : (colExists($pdo, 'clients', 'date_creation') ? 'date_creation' : null);
$savClientCol = colExists($pdo, 'sav', 'client_id') ? 'client_id' : 'id_client';
$livClientCol = colExists($pdo, 'livraisons', 'client_id') ? 'client_id' : 'id_client';
$savCreatedCol = colExists($pdo, 'sav', 'created_at') ? 'created_at' : (colExists($pdo, 'sav', 'date_creation') ? 'date_creation' : 'NOW()');
$livCreatedCol = colExists($pdo, 'livraisons', 'created_at') ? 'created_at' : (colExists($pdo, 'livraisons', 'date_creation') ? 'date_creation' : 'NOW()');
$savDateInterventionCol = colExists($pdo, 'sav', 'date_intervention') ? 'date_intervention' : (colExists($pdo, 'sav', 'date_prevue') ? 'date_prevue' : null);
$factClientCol = colExists($pdo, 'factures', 'client_id') ? 'client_id' : 'id_client';
$factCreatedCol = colExists($pdo, 'factures', 'created_at') ? 'created_at' : (colExists($pdo, 'factures', 'date_creation') ? 'date_creation' : 'NOW()');
$factNumberCol = colExists($pdo, 'factures', 'numero_facture') ? 'numero_facture' : (colExists($pdo, 'factures', 'reference') ? 'reference' : 'id');

// CLIENTS
$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE statut='actif'")->fetchColumn();
if ($clientCreatedCol !== null) {
    $nouveauxMoisStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE statut='actif' AND DATE_FORMAT({$clientCreatedCol},'%Y-%m')=?");
    $nouveauxMoisStmt->execute([date('Y-m')]);
    $nouveauxMois = (int)$nouveauxMoisStmt->fetchColumn();
} else {
    $nouveauxMois = 0;
}

// SAV
$savOuverts = (int)$pdo->query("SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours')")->fetchColumn();
if ($savDateInterventionCol !== null) {
    $savEnRetard = (int)$pdo->query("SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours') AND {$savDateInterventionCol} < CURDATE()")->fetchColumn();
} else {
    $savEnRetard = 0;
}

// LIVRAISONS
$livraisonsEnCours = (int)$pdo->query("SELECT COUNT(*) FROM livraisons WHERE statut IN ('planifiee','en_cours')")->fetchColumn();
$livraisonsJourStmt = $pdo->query("SELECT COUNT(*) FROM livraisons WHERE DATE({$livCreatedCol})=CURDATE()");
$livraisonsJour = (int)$livraisonsJourStmt->fetchColumn();

// STOCK
$stockAlerte = (int)$pdo->query("SELECT COUNT(*) FROM stock WHERE actif=1 AND quantite>0 AND quantite<=quantite_min")->fetchColumn();
$stockRupture = (int)$pdo->query("SELECT COUNT(*) FROM stock WHERE actif=1 AND quantite=0")->fetchColumn();

// PAIEMENTS
$impayeStmt = $pdo->prepare("SELECT COALESCE(SUM(montant_ttc-COALESCE(montant_paye,0)),0) FROM factures WHERE statut IN ('envoyee','partielle','en_retard')");
$impayeStmt->execute();
$montantImpaye = (float)$impayeStmt->fetchColumn();
$nbImpayes = (int)$pdo->query("SELECT COUNT(*) FROM factures WHERE statut IN ('envoyee','partielle','en_retard')")->fetchColumn();

// HISTORIQUE
$savRecents = $pdo->query("
  SELECT 'SAV' as type, CONCAT('SAV #',s.id,' — ',COALESCE(c.{$clientNameCol},'Client')) as label, s.{$savCreatedCol} as created_at
  FROM sav s
  LEFT JOIN clients c ON s.{$savClientCol}=c.id
  ORDER BY s.{$savCreatedCol} DESC
  LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$livRecents = $pdo->query("
  SELECT 'Livraison' as type, CONCAT('Livraison — ',COALESCE(c.{$clientNameCol},'Client')) as label, l.{$livCreatedCol} as created_at
  FROM livraisons l
  LEFT JOIN clients c ON l.{$livClientCol}=c.id
  ORDER BY l.{$livCreatedCol} DESC
  LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$facRecents = $pdo->query("
  SELECT 'Facture' as type, CONCAT(f.{$factNumberCol},' — ',COALESCE(c.{$clientNameCol},'Client')) as label, f.{$factCreatedCol} as created_at
  FROM factures f
  LEFT JOIN clients c ON f.{$factClientCol}=c.id
  ORDER BY f.{$factCreatedCol} DESC
  LIMIT 4
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$historique = array_merge($savRecents, $livRecents, $facRecents);
usort($historique, static fn(array $a, array $b): int => strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']));
$historique = array_slice($historique, 0, 8);

$nbNotifs = $savEnRetard + $stockAlerte + $stockRupture;

// Clients pour le widget support
$clientEmailExpr = $clientEmailCol !== null ? "COALESCE({$clientEmailCol}, '')" : "''";
$clientDetailExpr = $clientPhoneCol !== null
    ? "COALESCE({$clientPhoneCol}, " . ($clientCityCol !== null ? "{$clientCityCol}" : "''") . ", '')"
    : ($clientCityCol !== null ? "COALESCE({$clientCityCol}, '')" : "''");
$clientStatusExpr = $clientStatusCol !== null ? "COALESCE({$clientStatusCol}, 'actif')" : "'actif'";

$clientsWidget = $pdo->query("
    SELECT id, 
           COALESCE({$clientNameCol}, CONCAT('Client #', id)) as nom,
           {$clientEmailExpr} as email,
           {$clientDetailExpr} as detail,
           {$clientStatusExpr} as statut
    FROM clients 
    ORDER BY nom ASC
    LIMIT 300
")->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <style>
    body { margin:0; font-family: inherit; background:var(--bg-page); }
    .card { background:var(--bg-card); border-radius:14px; padding:24px; box-shadow:var(--shadow); }
    .grid-dashboard { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
    @media (max-width: 1024px) { .grid-dashboard { grid-template-columns: repeat(2,1fr) !important; } }
    @media (max-width: 640px) { .grid-dashboard { grid-template-columns: 1fr !important; } header nav { display:none !important; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>

<!-- FOND PAGE -->
<div style="min-height:calc(100vh - 56px); background: #f9fafb;">
<div class="page-container">

  <!-- HERO BANNER -->
  <div class="dashboard-hero">
    <h1>Tableau de bord</h1>
    <p>Bonjour <?= h($userNom) ?> 👋 — <?= date('l d F Y') ?></p>
    <div class="hero-stats-row">
      <div class="hero-stat">
        <div class="hero-stat-value"><?= $totalClients ?></div>
        <div class="hero-stat-label">Clients actifs</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-value"><?= $savOuverts ?></div>
        <div class="hero-stat-label">SAV ouverts</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-value"><?= $livraisonsEnCours ?></div>
        <div class="hero-stat-label">Livraisons</div>
      </div>
      <div class="hero-stat">
        <div class="hero-stat-value"><?= number_format($montantImpaye, 0, ',', ' ') ?>€</div>
        <div class="hero-stat-label">Impayes</div>
      </div>
    </div>
  </div>

  <!-- GRILLE CARDS -->
  <div class="dash-grid" style="display:grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px;">
    <!-- CLIENTS -->
    <div class="dash-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <div class="dash-card-label">Clients</div>
          <div class="dash-card-number"><?= $totalClients ?></div>
          <div class="dash-card-sub" style="color:#10b981;">+<?= $nouveauxMois ?> ce mois</div>
        </div>
        <div class="dash-card-icon" style="background:#ede9fe;">👥</div>
      </div>
      <a href="clients.php" class="dash-btn" style="background:#6366f1;">Voir les clients →</a>
    </div>

    <!-- SAV -->
    <div class="dash-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <div class="dash-card-label">SAV</div>
          <div class="dash-card-number"><?= $savOuverts ?></div>
          <div class="dash-card-sub" style="color:#ef4444;"><?= $savEnRetard ?> en retard</div>
        </div>
        <div class="dash-card-icon" style="background:#fef3c7;">🔧</div>
      </div>
      <a href="sav.php" class="dash-btn" style="background:#f59e0b;">Voir les SAV →</a>
    </div>

    <!-- LIVRAISONS -->
    <div class="dash-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <div class="dash-card-label">Livraisons</div>
          <div class="dash-card-number"><?= $livraisonsEnCours ?></div>
          <div class="dash-card-sub" style="color:#3b82f6;"><?= $livraisonsJour ?> aujourd'hui</div>
        </div>
        <div class="dash-card-icon" style="background:#dbeafe;">🚚</div>
      </div>
      <a href="livraison.php" class="dash-btn" style="background:#3b82f6;">Voir les livraisons →</a>
    </div>

    <!-- STOCK -->
    <div class="dash-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <div class="dash-card-label">Stock</div>
          <div class="dash-card-number"><?= $stockAlerte + $stockRupture ?></div>
          <?php if (($stockAlerte + $stockRupture) === 0): ?>
            <div class="dash-card-sub" style="color:#10b981;">✅ Stock OK</div>
          <?php else: ?>
            <div class="dash-card-sub" style="color:#f59e0b;">⚠️ <?= $stockAlerte ?> sous seuil</div>
            <div class="dash-card-sub" style="color:#ef4444;">🔴 <?= $stockRupture ?> rupture</div>
          <?php endif; ?>
        </div>
        <div class="dash-card-icon" style="background:#ffedd5;">📦</div>
      </div>
      <a href="stock.php" class="dash-btn" style="background:#f97316;">Voir le stock →</a>
    </div>

    <!-- PAIEMENTS -->
    <div class="dash-card">
      <div style="display:flex; justify-content:space-between; align-items:flex-start;">
        <div>
          <div class="dash-card-label">Paiements</div>
          <div class="dash-card-number" style="font-size:28px;"><?= number_format($montantImpaye, 0, ',', ' ') ?>€</div>
          <div class="dash-card-sub" style="color:#6b7280;"><?= $nbImpayes ?> facture(s) en attente</div>
        </div>
        <div class="dash-card-icon" style="background:#dcfce7;">💶</div>
      </div>
      <a href="paiements.php" class="dash-btn" style="background:#10b981;">Voir les paiements →</a>
    </div>

    <!-- HISTORIQUE -->
    <div class="dash-card" style="cursor:pointer;" onclick="window.location.href='historique.php'">
      <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <div style="font-size:14px; font-weight:600; color:var(--text-primary);">📋 Activite recente</div>
        <a href="historique.php" onclick="event.stopPropagation()" style="font-size:12px;color:#6366f1;text-decoration:none;font-weight:500;">Tout voir →</a>
      </div>
      <?php
        $icones = ['SAV'=>'🔧','Livraison'=>'🚚','Facture'=>'📄'];
        $couleurs = ['SAV'=>'#fef3c7','Livraison'=>'#dbeafe','Facture'=>'#ede9fe'];
        foreach (array_slice($historique, 0, 5) as $item):
          $ic = $icones[$item['type']] ?? '📌';
          $bg = $couleurs[$item['type']] ?? '#f3f4f6';
      ?>
        <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);">
          <div style="width:30px;height:30px;border-radius:8px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:13px;flex-shrink:0;"><?= $ic ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:12px;color:var(--text-primary);font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h((string)$item['label']) ?></div>
            <div style="font-size:10px;color:var(--text-second);"><?= date('d/m à H:i', strtotime((string)$item['created_at'])) ?></div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

</div><!-- /page-container -->
</div><!-- /fond page -->

<!-- ===== WIDGET SUPPORT CLIENT ===== -->
<button id="client-support-btn" title="Clients">
  <svg width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
    <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
    <circle cx="9" cy="7" r="4"/>
    <path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/>
  </svg>
</button>

<div id="client-support-panel">
  <div class="csp-header">
    <div class="csp-title">
      👥 Clients
      <span class="csp-count" id="csp-count"><?= count($clientsWidget) ?></span>
    </div>
    <button onclick="toggleClientPanel()" style="background:none;border:none;cursor:pointer;color:var(--text-second);padding:4px;">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div class="csp-search">
    <input type="text" id="csp-search-input" placeholder="Rechercher un client..." oninput="filterClients(this.value)">
  </div>
  <div class="csp-list" id="csp-list"></div>
  <div class="csp-footer">
    <a href="clients.php">Voir tous les clients →</a>
  </div>
</div>

<!-- PANEL DETAIL CLIENT -->
<div id="csp-detail-panel">
  <div class="csp-header">
    <button onclick="closeDetail()" style="background:none;border:none;cursor:pointer;color:var(--text-second);padding:4px;display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
      Retour
    </button>
    <button onclick="closeAllPanels()" style="background:none;border:none;cursor:pointer;color:var(--text-second);padding:4px;">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg>
    </button>
  </div>
  <div id="csp-detail-content" style="overflow-y:auto;flex:1;padding:0;"></div>
</div>

<script <?= csp_nonce() ?>>
// Donnees clients injectees depuis PHP
const CSP_CLIENTS = <?= json_encode(array_map(function($c) {
  return [
    'id'     => (int)$c['id'],
    'nom'    => (string)$c['nom'],
    'detail' => (string)$c['detail'],
    'statut' => (string)$c['statut'],
  ];
}, $clientsWidget), JSON_UNESCAPED_UNICODE) ?>;

let cspOpen = false;

function toggleClientPanel() {
  cspOpen = !cspOpen;
  document.getElementById('client-support-panel').classList.toggle('open', cspOpen);
  if (cspOpen) {
    document.getElementById('csp-search-input').focus();
    renderClients(CSP_CLIENTS);
  }
}

function filterClients(q) {
  const filtered = q.trim() === ''
    ? CSP_CLIENTS
    : CSP_CLIENTS.filter(c =>
        c.nom.toLowerCase().includes(q.toLowerCase()) ||
        c.detail.toLowerCase().includes(q.toLowerCase())
      );
  renderClients(filtered);
  document.getElementById('csp-count').textContent = filtered.length;
}

function renderClients(clients) {
  const list = document.getElementById('csp-list');
  if (!clients.length) {
    list.innerHTML = '<div style="padding:24px;text-align:center;color:var(--text-second);font-size:13px;">Aucun client trouvé</div>';
    return;
  }
  list.innerHTML = clients.map(c => `
    <div class="csp-item" onclick="openClientDetail(${c.id})" style="cursor:pointer;">
      <div class="csp-avatar">${escHtml(c.nom.charAt(0).toUpperCase())}</div>
      <div style="flex:1;min-width:0;">
        <div class="csp-name">${escHtml(c.nom)}</div>
        ${c.detail ? `<div class="csp-detail">${escHtml(c.detail)}</div>` : ''}
      </div>
      <svg width="14" height="14" fill="none" stroke="#9ca3af" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><path d="M9 18l6-6-6-6"/></svg>
    </div>`).join('');
}

function openClientDetail(clientId) {
  const detailPanel = document.getElementById('csp-detail-panel');
  const content = document.getElementById('csp-detail-content');
  content.innerHTML = '<div style="padding:32px;text-align:center;color:#6b7280;font-size:13px;">Chargement...</div>';
  document.getElementById('client-support-panel').classList.remove('open');
  detailPanel.classList.add('open');

  fetch('/API/client_widget_detail.php?id=' + clientId, {
    headers: { 'X-Requested-With': 'XMLHttpRequest' }
  })
  .then(r => r.json())
  .then(data => {
    if (!data.ok) { content.innerHTML = '<div style="padding:24px;color:#ef4444;font-size:13px;">Erreur : ' + escHtml(data.error || 'Erreur inconnue') + '</div>'; return; }
    const c = data.client;
    const livs = data.livraisons;
    const savs = data.sav;

    const statutLivColor = { planifiee:'#dbeafe|#2563eb', en_cours:'#fef9c3|#ca8a04', livree:'#dcfce7|#16a34a', annulee:'#f3f4f6|#6b7280' };
    const statutSavColor = { ouvert:'#dbeafe|#2563eb', en_cours:'#fef9c3|#ca8a04', resolu:'#dcfce7|#16a34a', annule:'#f3f4f6|#6b7280' };
    const prioriteColor  = { basse:'#f3f4f6|#6b7280', normale:'#dbeafe|#2563eb', haute:'#fef9c3|#ca8a04', urgente:'#fee2e2|#dc2626' };

    function badge(val, map) {
      const parts = (map[val] || '#f3f4f6|#6b7280').split('|');
      return `<span style="background:${parts[0]};color:${parts[1]};font-size:10px;font-weight:700;padding:2px 8px;border-radius:999px;">${escHtml(val)}</span>`;
    }

    let html = `
      <!-- INFOS CLIENT -->
      <div style="padding:20px;border-bottom:1px solid #e5e7eb;">
        <div style="display:flex;align-items:center;gap:12px;margin-bottom:16px;">
          <div style="width:44px;height:44px;border-radius:12px;background:#2563eb;display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:700;color:#fff;flex-shrink:0;">${escHtml(c.raison_sociale.charAt(0).toUpperCase())}</div>
          <div>
            <div style="font-size:15px;font-weight:700;color:#111827;">${escHtml(c.raison_sociale)}</div>
            <div style="font-size:11px;color:#6b7280;">${escHtml(c.numero_client)}</div>
          </div>
          <a href="/public/client_fiche.php?id=${c.id}" style="margin-left:auto;font-size:11px;color:#2563eb;text-decoration:none;font-weight:600;white-space:nowrap;">Voir fiche →</a>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
          ${c.telephone1 ? `<div style="font-size:12px;color:#6b7280;">📞 ${escHtml(c.telephone1)}</div>` : ''}
          ${c.email ? `<div style="font-size:12px;color:#6b7280;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">✉️ ${escHtml(c.email)}</div>` : ''}
          ${c.ville ? `<div style="font-size:12px;color:#6b7280;">📍 ${escHtml(c.code_postal)} ${escHtml(c.ville)}</div>` : ''}
          ${c.nom_dirigeant ? `<div style="font-size:12px;color:#6b7280;">👤 ${escHtml(c.nom_dirigeant)} ${escHtml(c.prenom_dirigeant || '')}</div>` : ''}
        </div>
      </div>

      <!-- LIVRAISONS -->
      <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;">
        <div style="font-size:12px;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">🚚 Livraisons (${livs.length})</div>
        ${livs.length === 0
          ? '<div style="font-size:12px;color:#9ca3af;text-align:center;padding:8px 0;">Aucune livraison</div>'
          : livs.map(l => `
            <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #f3f4f6;">
              <div style="flex:1;min-width:0;">
                <div style="font-size:12px;font-weight:600;color:#111827;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(l.objet)}</div>
                <div style="font-size:11px;color:#6b7280;">${escHtml(l.reference)} · ${escHtml(l.date_prevue)}</div>
              </div>
              ${badge(l.statut, statutLivColor)}
            </div>`).join('')
        }
      </div>

      <!-- SAV -->
      <div style="padding:16px 20px;">
        <div style="font-size:12px;font-weight:700;color:#111827;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:10px;">🔧 SAV (${savs.length})</div>
        ${savs.length === 0
          ? '<div style="font-size:12px;color:#9ca3af;text-align:center;padding:8px 0;">Aucun SAV</div>'
          : savs.map(s => `
            <div style="padding:8px 0;border-bottom:1px solid #f3f4f6;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                <div style="font-size:12px;font-weight:600;color:#111827;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${escHtml(s.description.substring(0,50))}${s.description.length > 50 ? '…' : ''}</div>
                ${badge(s.statut, statutSavColor)}
              </div>
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:11px;color:#6b7280;">${escHtml(s.reference)} · ${escHtml(s.date_ouverture)}</span>
                ${badge(s.priorite, prioriteColor)}
              </div>
            </div>`).join('')
        }
      </div>`;

    content.innerHTML = html;
  })
  .catch(() => {
    content.innerHTML = '<div style="padding:24px;color:#ef4444;font-size:13px;">Erreur de connexion</div>';
  });
}

function closeDetail() {
  document.getElementById('csp-detail-panel').classList.remove('open');
  document.getElementById('client-support-panel').classList.add('open');
}

function closeAllPanels() {
  document.getElementById('csp-detail-panel').classList.remove('open');
  document.getElementById('client-support-panel').classList.remove('open');
  cspOpen = false;
}

function escHtml(str) {
  const s = String(str ?? '');
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('client-support-btn').addEventListener('click', toggleClientPanel);

// Fermer en cliquant a l'exterieur
document.addEventListener('click', e => {
  const btn    = document.getElementById('client-support-btn');
  const panel  = document.getElementById('client-support-panel');
  const detail = document.getElementById('csp-detail-panel');
  if (!panel.contains(e.target) && !detail.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
    panel.classList.remove('open');
    detail.classList.remove('open');
    cspOpen = false;
  }
});
</script>

</body>
</html>
