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
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard</title>
  <style>
    body { margin:0; font-family: inherit; background:#f1f5f9; }
    .nav-link { display:flex; flex-direction:column; align-items:center; padding:6px 10px; border-radius:8px; text-decoration:none; color:rgba(255,255,255,.7); font-size:9px; gap:3px; transition:all .2s; }
    .nav-link:hover { background:rgba(255,255,255,.1); color:#fff; }
    .card { background:#fff; border-radius:14px; padding:24px; box-shadow:0 1px 4px rgba(0,0,0,.06); }
    .grid-dashboard { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
    @media (max-width: 1024px) { .grid-dashboard { grid-template-columns: repeat(2,1fr) !important; } }
    @media (max-width: 640px) { .grid-dashboard { grid-template-columns: 1fr !important; } header nav { display:none !important; } }
  </style>
</head>
<body>
<header style="position:sticky;top:0;z-index:500;background:#1e1b4b;padding:0 24px;height:56px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 8px rgba(0,0,0,.2);">
  <a href="dashboard.php" style="display:flex;align-items:center;gap:10px;text-decoration:none;">
    <img src="../assets/logos/logo.png" alt="CCComputer" style="height:32px;" onerror="this.style.display='none'">
    <span style="color:#fff;font-weight:700;font-size:16px;letter-spacing:.3px;">CCComputer</span>
  </a>
  <nav style="display:flex;align-items:center;gap:4px;">
    <a href="dashboard.php" title="Tableau de bord" class="nav-link">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>Dashboard
    </a>
    <a href="clients.php" title="Espace commercial" class="nav-link">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v6a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 21V5a2 2 0 00-2-2h-4a2 2 0 00-2 2v16"/></svg>Commercial
    </a>
    <a href="#" title="Carte clients" class="nav-link js-maps">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>Maps
    </a>
    <a href="#" title="Calendrier" class="nav-link js-cal">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>Calendrier
    </a>
    <a href="#" title="Notifications" class="nav-link js-notif" style="position:relative;">
      <div style="position:relative;">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9M13.73 21a2 2 0 01-3.46 0"/></svg>
        <?php if ($nbNotifs > 0): ?><span style="position:absolute;top:-6px;right:-6px;background:#ef4444;color:#fff;border-radius:999px;font-size:9px;font-weight:700;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 3px;"><?= $nbNotifs ?></span><?php endif; ?>
      </div>Notifications
    </a>
    <a href="#" title="Messagerie" class="nav-link js-msg">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>Messagerie
    </a>
  </nav>
  <div style="display:flex;align-items:center;gap:12px;position:relative;">
    <button id="btnProfilMenu" type="button" style="display:flex;align-items:center;gap:8px;cursor:pointer;background:none;border:none;">
      <div style="width:34px;height:34px;border-radius:50%;background:#6366f1;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:14px;"><?= h(strtoupper((string)mb_substr($userNom,0,1))) ?></div>
      <div style="color:#fff;text-align:left;"><div style="font-size:13px;font-weight:600;"><?= h($userNom) ?></div><div style="font-size:10px;color:rgba(255,255,255,.5);"><?= h(ucfirst($userRole)) ?></div></div>
      <svg width="14" height="14" fill="none" stroke="rgba(255,255,255,.5)" stroke-width="2" viewBox="0 0 24 24"><path d="M6 9l6 6 6-6"/></svg>
    </button>
    <div id="profilMenu" style="display:none;position:absolute;top:56px;right:0;background:#fff;border-radius:10px;box-shadow:0 8px 24px rgba(0,0,0,.15);min-width:180px;z-index:1000;padding:8px 0;">
      <a href="profil.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">👤 Mon profil</a>
      <a href="parametres.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#374151;text-decoration:none;">⚙️ Paramètres</a>
      <hr style="margin:4px 0;border:none;border-top:1px solid #f3f4f6;">
      <a href="../source/connexion/logout.php" style="display:flex;align-items:center;gap:10px;padding:10px 16px;font-size:13px;color:#ef4444;text-decoration:none;">🚪 Déconnexion</a>
    </div>
  </div>
</header>

<div style="max-width:1400px;margin:0 auto;padding:24px;">
  <div style="margin-bottom:24px;">
    <h1 style="font-size:22px;font-weight:700;color:#111827;margin:0 0 2px;">Tableau de bord</h1>
    <p style="color:#6b7280;font-size:13px;margin:0;">Bonjour <?= h($userNom) ?> 👋 — <?= date('l d F Y') ?></p>
  </div>

  <div class="grid-dashboard">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div><div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">Clients</div><div style="font-size:36px;font-weight:700;color:#111827;line-height:1;"><?= $totalClients ?></div><div style="font-size:12px;color:#10b981;margin-top:4px;">+<?= $nouveauxMois ?> ce mois</div></div>
        <div style="width:48px;height:48px;border-radius:12px;background:#ede9fe;display:flex;align-items:center;justify-content:center;font-size:22px;">👥</div>
      </div>
      <a href="clients.php" style="display:block;text-align:center;background:#6366f1;color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Voir les clients →</a>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div><div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">SAV</div><div style="font-size:36px;font-weight:700;color:#111827;line-height:1;"><?= $savOuverts ?></div><div style="font-size:12px;color:#ef4444;margin-top:4px;"><?= $savEnRetard ?> en retard</div></div>
        <div style="width:48px;height:48px;border-radius:12px;background:#fef3c7;display:flex;align-items:center;justify-content:center;font-size:22px;">🔧</div>
      </div>
      <a href="sav.php" style="display:block;text-align:center;background:#f59e0b;color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Voir les SAV →</a>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div><div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">Livraisons</div><div style="font-size:36px;font-weight:700;color:#111827;line-height:1;"><?= $livraisonsEnCours ?></div><div style="font-size:12px;color:#3b82f6;margin-top:4px;"><?= $livraisonsJour ?> aujourd'hui</div></div>
        <div style="width:48px;height:48px;border-radius:12px;background:#dbeafe;display:flex;align-items:center;justify-content:center;font-size:22px;">🚚</div>
      </div>
      <a href="livraison.php" style="display:block;text-align:center;background:#3b82f6;color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Voir les livraisons →</a>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div style="font-size:14px;font-weight:600;color:#111827;">📋 Historique récent</div>
      </div>
      <?php $icones = ['SAV'=>'🔧','Livraison'=>'🚚','Facture'=>'📄']; $couleurs = ['SAV'=>'#fef3c7','Livraison'=>'#dbeafe','Facture'=>'#ede9fe']; ?>
      <?php foreach ($historique as $h): $ic = $icones[$h['type']] ?? '📌'; $bg = $couleurs[$h['type']] ?? '#f3f4f6'; ?>
        <div style="display:flex;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #f9fafb;">
          <div style="width:32px;height:32px;border-radius:8px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;"><?= $ic ?></div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;color:#374151;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= h((string)$h['label']) ?></div>
            <div style="font-size:11px;color:#9ca3af;"><?= date('d/m/Y à H:i', strtotime((string)$h['created_at'])) ?></div>
          </div>
          <span style="font-size:10px;color:#6b7280;background:#f3f4f6;padding:2px 8px;border-radius:999px;white-space:nowrap;"><?= h((string)$h['type']) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div>
          <div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">Stock</div>
          <div style="font-size:36px;font-weight:700;color:#111827;line-height:1;"><?= $stockAlerte + $stockRupture ?></div>
          <?php if (($stockAlerte + $stockRupture) === 0): ?>
            <div style="font-size:12px;color:#10b981;margin-top:4px;">✅ Stock OK</div>
          <?php else: ?>
            <div style="font-size:12px;color:#f59e0b;margin-top:4px;">⚠️ <?= $stockAlerte ?> sous seuil</div>
            <div style="font-size:12px;color:#ef4444;">🔴 <?= $stockRupture ?> en rupture</div>
          <?php endif; ?>
        </div>
        <div style="width:48px;height:48px;border-radius:12px;background:#ffedd5;display:flex;align-items:center;justify-content:center;font-size:22px;">📦</div>
      </div>
      <a href="stock.php" style="display:block;text-align:center;background:#f97316;color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Voir le stock →</a>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:20px;">
        <div><div style="font-size:12px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:6px;">Paiements</div><div style="font-size:36px;font-weight:700;color:#111827;line-height:1;"><?= number_format($montantImpaye, 0, ',', ' ') ?> €</div><div style="font-size:12px;color:#6b7280;margin-top:4px;"><?= $nbImpayes ?> facture(s) en attente</div></div>
        <div style="width:48px;height:48px;border-radius:12px;background:#dcfce7;display:flex;align-items:center;justify-content:center;font-size:22px;">💶</div>
      </div>
      <a href="paiements.php" style="display:block;text-align:center;background:#10b981;color:#fff;border-radius:8px;padding:9px;font-size:13px;font-weight:600;text-decoration:none;">Voir les paiements →</a>
    </div>
  </div>
</div>

<script <?= csp_nonce() ?>>
const profilBtn = document.getElementById('btnProfilMenu');
const profilMenu = document.getElementById('profilMenu');
profilBtn?.addEventListener('click', function (e) {
  e.stopPropagation();
  if (!profilMenu) return;
  profilMenu.style.display = profilMenu.style.display === 'block' ? 'none' : 'block';
});
document.addEventListener('click', function (e) {
  if (!profilMenu) return;
  if (!e.target.closest('#btnProfilMenu') && !e.target.closest('#profilMenu')) {
    profilMenu.style.display = 'none';
  }
});
function bindSoon(selector, msg){
  document.querySelectorAll(selector).forEach(el => {
    el.addEventListener('click', function(e){
      e.preventDefault();
      alert(msg);
    });
  });
}
bindSoon('.js-maps', '🗺️ Maps — Bientôt disponible');
bindSoon('.js-cal', '📅 Calendrier — Bientôt disponible');
bindSoon('.js-notif', '🔔 Notifications — Bientôt disponible');
bindSoon('.js-msg', '💬 Messagerie — Bientôt disponible');
</script>
</body>
</html>
