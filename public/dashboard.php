<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

$moisActuel = date('Y-m');
$anneeActuelle = date('Y');
$userId = (int)($_SESSION['user_id'] ?? 0);
$rawRole = (string)($_SESSION['role'] ?? ($_SESSION['emploi'] ?? 'inconnu'));
$userRole = mb_strtolower($rawRole);
$userNom = (string)($_SESSION['nom'] ?? ($_SESSION['user_nom'] ?? 'Utilisateur'));

if ($userId <= 0) {
    header('Location: /public/login.php');
    exit;
}

function colExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$factClientCol = colExists($pdo, 'factures', 'client_id') ? 'client_id' : 'id_client';
$savClientCol = colExists($pdo, 'sav', 'client_id') ? 'client_id' : 'id_client';
$livClientCol = colExists($pdo, 'livraisons', 'client_id') ? 'client_id' : 'id_client';
$savTechCol = colExists($pdo, 'sav', 'technicien_id') ? 'technicien_id' : 'id_technicien';
$clientsCreatedCol = colExists($pdo, 'clients', 'created_at') ? 'created_at' : (colExists($pdo, 'clients', 'date_creation') ? 'date_creation' : null);
$savCreatedCol = colExists($pdo, 'sav', 'created_at') ? 'created_at' : (colExists($pdo, 'sav', 'date_creation') ? 'date_creation' : 'NOW()');
$livCreatedCol = colExists($pdo, 'livraisons', 'created_at') ? 'created_at' : (colExists($pdo, 'livraisons', 'date_creation') ? 'date_creation' : 'NOW()');
$factNumberCol = colExists($pdo, 'factures', 'numero_facture') ? 'numero_facture' : (colExists($pdo, 'factures', 'reference') ? 'reference' : 'id');
$dateEcheanceCol = colExists($pdo, 'factures', 'date_echeance') ? 'date_echeance' : 'date_facture';

// Factures
$caMonthRow = $pdo->prepare("
  SELECT COALESCE(SUM(montant_ttc),0) as ca
  FROM factures
  WHERE statut NOT IN ('annulee','brouillon')
    AND DATE_FORMAT(date_facture,'%Y-%m') = ?
");
$caMonthRow->execute([$moisActuel]);
$caMois = (float)$caMonthRow->fetchColumn();

$caYearRow = $pdo->prepare("
  SELECT COALESCE(SUM(montant_ttc),0) as ca
  FROM factures
  WHERE statut NOT IN ('annulee','brouillon')
    AND YEAR(date_facture) = ?
");
$caYearRow->execute([$anneeActuelle]);
$caAnnee = (float)$caYearRow->fetchColumn();

$impayesRow = $pdo->query("
  SELECT COUNT(*) as nb, COALESCE(SUM(montant_ttc - COALESCE(montant_paye,0)),0) as montant
  FROM factures
  WHERE statut IN ('envoyee','partielle','en_retard')
");
$impayes = $impayesRow->fetch(PDO::FETCH_ASSOC) ?: ['nb' => 0, 'montant' => 0];

$ca12Mois = [];
for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-{$i} months"));
    $label = date('M Y', strtotime("-{$i} months"));
    $stmt = $pdo->prepare("
      SELECT COALESCE(SUM(montant_ttc),0)
      FROM factures
      WHERE statut NOT IN ('annulee','brouillon')
        AND DATE_FORMAT(date_facture,'%Y-%m') = ?
    ");
    $stmt->execute([$mois]);
    $ca12Mois[] = ['label' => $label, 'ca' => round((float)$stmt->fetchColumn(), 2)];
}

$statutsFactures = $pdo->query("
  SELECT statut, COUNT(*) as nb
  FROM factures
  WHERE statut != 'annulee'
  GROUP BY statut
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$top5Clients = $pdo->query("
  SELECT c.nom, COALESCE(SUM(f.montant_ttc),0) as ca
  FROM clients c
  LEFT JOIN factures f ON f.{$factClientCol} = c.id AND f.statut NOT IN ('annulee','brouillon')
  GROUP BY c.id, c.nom
  ORDER BY ca DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Clients
$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients WHERE statut='actif'")->fetchColumn();
if ($clientsCreatedCol !== null) {
    $nouveauxStmt = $pdo->prepare("SELECT COUNT(*) FROM clients WHERE statut='actif' AND DATE_FORMAT({$clientsCreatedCol},'%Y-%m') = ?");
    $nouveauxStmt->execute([$moisActuel]);
    $nouveauxClients = (int)$nouveauxStmt->fetchColumn();
} else {
    $nouveauxClients = 0;
}

// SAV
$savEnCours = (int)$pdo->query("SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours')")->fetchColumn();
$savEnRetard = (int)$pdo->query("SELECT COUNT(*) FROM sav WHERE statut IN ('ouvert','en_cours') AND date_intervention < CURDATE()")->fetchColumn();
$savParTech = $pdo->query("
  SELECT COALESCE(u.nom,'Non assigné') as technicien, COUNT(s.id) as nb
  FROM sav s
  LEFT JOIN utilisateurs u ON s.{$savTechCol} = u.id
  WHERE s.statut IN ('ouvert','en_cours')
  GROUP BY s.{$savTechCol}, u.nom
  ORDER BY nb DESC
  LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Stock
$stockAlerte = (int)$pdo->query("SELECT COUNT(*) FROM stock WHERE actif=1 AND quantite > 0 AND quantite <= quantite_min")->fetchColumn();
$stockRupture = (int)$pdo->query("SELECT COUNT(*) FROM stock WHERE actif=1 AND quantite = 0")->fetchColumn();
$articlesAlerte = $pdo->query("
  SELECT designation, categorie, quantite, quantite_min
  FROM stock
  WHERE actif=1 AND quantite <= quantite_min
  ORDER BY quantite ASC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Activités
$dernieresLivraisons = $pdo->query("
  SELECT l.*, c.nom as client_nom
  FROM livraisons l
  LEFT JOIN clients c ON l.{$livClientCol} = c.id
  ORDER BY l.{$livCreatedCol} DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$derniersSAV = $pdo->query("
  SELECT s.*, c.nom as client_nom
  FROM sav s
  LEFT JOIN clients c ON s.{$savClientCol} = c.id
  ORDER BY s.{$savCreatedCol} DESC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$prochainesRelances = $pdo->query("
  SELECT f.{$factNumberCol} AS numero_facture, c.nom as client_nom,
         f.montant_ttc, COALESCE(f.montant_paye,0) as montant_paye, f.{$dateEcheanceCol} as date_echeance
  FROM factures f
  LEFT JOIN clients c ON f.{$factClientCol} = c.id
  WHERE f.statut IN ('envoyee','partielle','en_retard')
    AND f.{$dateEcheanceCol} <= DATE_ADD(CURDATE(), INTERVAL 7 DAY)
  ORDER BY f.{$dateEcheanceCol} ASC
  LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$peutVoirFinances = in_array($userRole, ['admin', 'dirigeant', 'secretaire', 'secrétaire'], true);
$isLivreur = $userRole === 'livreur';
$isTechnicien = $userRole === 'technicien';
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Tableau de bord</title>
  <link rel="stylesheet" href="/assets/css/dashboard.css">
  <style>
    body { background: #f8f9fb; }
    .wrap { padding: 20px; }
    .dash-card { background:#fff; border-radius:12px; padding:20px 24px; box-shadow:0 1px 4px rgba(0,0,0,.07); }
    .grid-6 { display:grid; grid-template-columns:repeat(6,1fr); gap:16px; margin-bottom:20px; }
    .grid-2-1 { display:grid; grid-template-columns:2fr 1fr; gap:16px; margin-bottom:20px; }
    .grid-3 { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; margin-bottom:20px; }
    .widget-title { font-size:14px; font-weight:600; color:#111827; margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; }
    .widget-title a { font-size:12px; font-weight:400; color:#6366f1; text-decoration:none; }
    @media (max-width: 1200px) { .grid-6 { grid-template-columns: repeat(3,1fr); } }
    @media (max-width: 900px) { .grid-6 { grid-template-columns: repeat(2,1fr); } .grid-2-1,.grid-3 { grid-template-columns: 1fr; } }
    @media (max-width: 600px) { .grid-6 { grid-template-columns: 1fr; } .wrap { padding: 12px; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<main class="wrap">
  <div style="margin-bottom:28px;display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;">
    <div>
      <h1 style="font-size:26px;font-weight:700;color:#111827;margin:0 0 4px;">Tableau de bord</h1>
      <p style="color:#6b7280;font-size:14px;margin:0;">Bonjour <?= h($userNom) ?> 👋 — <?= date('l d F Y') ?></p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <?php if (!$isTechnicien && !$isLivreur): ?><a href="factures.php?action=new" style="background:#6366f1;color:#fff;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;text-decoration:none;">+ Facture</a><?php endif; ?>
      <?php if (!$isLivreur): ?><a href="sav.php?action=new" style="background:#f59e0b;color:#fff;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;text-decoration:none;">+ SAV</a><?php endif; ?>
      <?php if (!$isTechnicien && !$isLivreur): ?><a href="clients.php?action=new" style="background:#10b981;color:#fff;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;text-decoration:none;">+ Client</a><?php endif; ?>
      <a href="stock.php?action=new" style="background:#f3f4f6;color:#374151;border:1px solid #e5e7eb;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;text-decoration:none;">+ Stock</a>
    </div>
  </div>

  <div class="grid-6">
    <?php if ($peutVoirFinances): ?>
      <div class="dash-card" style="border-left:4px solid #6366f1;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">CA ce mois</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= number_format($caMois,2,',',' ') ?> €</div><div style="font-size:11px;color:#9ca3af;margin-top:4px;">vs année: <?= number_format($caAnnee,2,',',' ') ?> €</div></div>
      <div class="dash-card" style="border-left:4px solid #8b5cf6;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">CA cette année</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= number_format($caAnnee,2,',',' ') ?> €</div><div style="font-size:11px;color:#9ca3af;margin-top:4px;">—</div></div>
      <div class="dash-card" style="border-left:4px solid #ef4444;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Factures impayées</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= (int)$impayes['nb'] ?></div><div style="font-size:11px;color:#9ca3af;margin-top:4px;"><?= number_format((float)$impayes['montant'],2,',',' ') ?> €</div></div>
    <?php endif; ?>
    <?php if (!$isTechnicien): ?>
      <div class="dash-card" style="border-left:4px solid #10b981;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Clients actifs</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $totalClients ?></div><div style="font-size:11px;color:#9ca3af;margin-top:4px;">+<?= $nouveauxClients ?> ce mois</div></div>
      <div class="dash-card" style="border-left:4px solid #f59e0b;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">SAV en cours</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $savEnCours ?></div><div style="font-size:11px;color:#9ca3af;margin-top:4px;"><?= $savEnRetard ?> en retard</div></div>
    <?php endif; ?>
    <div class="dash-card" style="border-left:4px solid #f97316;"><div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:#6b7280;margin-bottom:8px;">Alertes stock</div><div style="font-size:28px;font-weight:700;color:#111827;"><?= $stockAlerte ?></div><div style="font-size:11px;color:#9ca3af;margin-top:4px;"><?= $stockRupture ?> ruptures</div></div>
  </div>

  <?php if ($peutVoirFinances): ?>
  <div class="grid-2-1">
    <div class="dash-card"><div class="widget-title">Chiffre d'affaires — 12 derniers mois</div><canvas id="chartCA" height="280"></canvas></div>
    <div class="dash-card"><div class="widget-title">Répartition statuts factures</div><canvas id="chartStatuts" height="280"></canvas></div>
  </div>
  <?php endif; ?>

  <?php if (!$isLivreur): ?>
  <div class="grid-2-1">
    <div class="dash-card"><div class="widget-title">SAV par technicien</div><canvas id="chartSAV" height="280"></canvas></div>
    <div class="dash-card">
      <div class="widget-title">Top 5 clients (CA)</div>
      <?php $maxCA = max(array_map(static fn($c) => (float)$c['ca'], $top5Clients ?: [['ca' => 1]])); $maxCA = $maxCA > 0 ? $maxCA : 1; ?>
      <?php foreach ($top5Clients as $i => $client): $pct = (int)round(((float)$client['ca'] / $maxCA) * 100); ?>
        <div style="margin-bottom:14px;">
          <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
            <span style="font-size:13px;font-weight:500;color:#374151;"><?= ($i+1) ?>. <?= h((string)$client['nom']) ?></span>
            <span style="font-size:13px;font-weight:600;color:#6366f1;"><?= number_format((float)$client['ca'],0,',',' ') ?> €</span>
          </div>
          <div style="height:6px;background:#f3f4f6;border-radius:3px;"><div style="width:<?= $pct ?>%;height:100%;background:#6366f1;border-radius:3px;"></div></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="grid-3">
    <?php if (!$isTechnicien): ?>
    <div class="dash-card">
      <div class="widget-title">Dernières livraisons <a href="livraison.php">Voir tout →</a></div>
      <?php foreach ($dernieresLivraisons as $l): $st = (string)($l['statut'] ?? 'planifiee'); $badge = $st === 'livree' ? ['#dcfce7','#166534'] : ($st === 'en_cours' ? ['#dbeafe','#1d4ed8'] : ['#f3f4f6','#374151']); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;">
          <div><div style="font-size:13px;font-weight:500;color:#111827;"><?= h((string)($l['client_nom'] ?? '—')) ?></div><div style="font-size:11px;color:#9ca3af;"><?= date('d/m/Y', strtotime((string)($l[$livCreatedCol] ?? 'now'))) ?></div></div>
          <span style="background:<?= $badge[0] ?>;color:<?= $badge[1] ?>;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;"><?= h(ucfirst($st)) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="dash-card">
      <div class="widget-title">Derniers SAV <a href="sav.php">Voir tout →</a></div>
      <?php foreach ($derniersSAV as $s): $st = (string)($s['statut'] ?? 'ouvert'); $badge = $st === 'resolu' ? ['#dcfce7','#166534'] : ($st === 'en_cours' ? ['#ffedd5','#9a3412'] : ['#fee2e2','#991b1b']); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #f3f4f6;">
          <div><div style="font-size:13px;font-weight:500;color:#111827;"><?= h((string)($s['client_nom'] ?? '—')) ?></div><div style="font-size:11px;color:#9ca3af;">Technicien: <?= h((string)($s['technicien'] ?? 'Non assigné')) ?></div></div>
          <span style="background:<?= $badge[0] ?>;color:<?= $badge[1] ?>;padding:2px 10px;border-radius:999px;font-size:11px;font-weight:600;"><?= h($st) ?></span>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="dash-card">
      <div class="widget-title">⚠️ Alertes stock & relances</div>
      <div style="font-size:13px;font-weight:600;color:#111827;margin-bottom:8px;">Stock en alerte</div>
      <?php foreach ($articlesAlerte as $art): $c = ((int)$art['quantite'] === 0) ? '#ef4444' : '#f59e0b'; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;">
          <span style="font-size:13px;color:#374151;"><?= h((string)$art['designation']) ?></span>
          <span style="font-weight:700;font-size:13px;color:<?= $c ?>;"><?= (int)$art['quantite'] ?> / <?= (int)$art['quantite_min'] ?></span>
        </div>
      <?php endforeach; ?>
      <?php if ($peutVoirFinances): ?>
      <div style="font-size:13px;font-weight:600;color:#111827;margin:12px 0 8px;">📅 Relances à venir (7j)</div>
      <?php foreach ($prochainesRelances as $f): $retard = strtotime((string)$f['date_echeance']) < time(); ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #f3f4f6;">
          <div><div style="font-size:12px;font-weight:500;color:#374151;"><?= h((string)$f['client_nom']) ?></div><div style="font-size:11px;color:#9ca3af;"><?= h((string)$f['numero_facture']) ?> — <?= date('d/m', strtotime((string)$f['date_echeance'])) ?></div></div>
          <span style="font-size:13px;font-weight:600;color:<?= $retard ? '#ef4444' : '#f59e0b' ?>;"><?= number_format((float)$f['montant_ttc'] - (float)$f['montant_paye'],0,',',' ') ?> €</span>
        </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" <?= csp_nonce() ?>></script>
<script <?= csp_nonce() ?>>
const canFinance = <?= $peutVoirFinances ? 'true' : 'false' ?>;
if (canFinance && document.getElementById('chartCA')) {
  const labelsCA = <?= json_encode(array_column($ca12Mois, 'label')) ?>;
  const dataCA = <?= json_encode(array_column($ca12Mois, 'ca')) ?>;
  new Chart(document.getElementById('chartCA'), {
    type: 'line',
    data: { labels: labelsCA, datasets: [{ label: 'CA TTC (€)', data: dataCA, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.08)', borderWidth: 2.5, pointBackgroundColor: '#6366f1', pointRadius: 4, fill: true, tension: 0.4 }]},
    options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, grid: { color: '#f3f4f6' }, ticks: { callback: v => Number(v).toLocaleString('fr-FR') + ' €' } }, x: { grid: { display: false } } } }
  });
  const statutsLabels = <?= json_encode(array_column($statutsFactures, 'statut')) ?>;
  const statutsData = <?= json_encode(array_column($statutsFactures, 'nb')) ?>;
  const statutsColors = {'envoyee':'#6366f1','payee':'#10b981','partielle':'#f59e0b','en_retard':'#ef4444','brouillon':'#9ca3af'};
  new Chart(document.getElementById('chartStatuts'), {
    type: 'doughnut',
    data: { labels: statutsLabels, datasets: [{ data: statutsData, backgroundColor: statutsLabels.map(s => statutsColors[s] || '#e5e7eb'), borderWidth: 0 }]},
    options: { responsive: true, cutout: '65%', plugins: { legend: { position: 'bottom', labels: { padding: 16, font: { size: 12 } } } } }
  });
}
if (document.getElementById('chartSAV')) {
  new Chart(document.getElementById('chartSAV'), {
    type: 'bar',
    data: { labels: <?= json_encode(array_column($savParTech, 'technicien')) ?>, datasets: [{ label: 'SAV en cours', data: <?= json_encode(array_column($savParTech, 'nb')) ?>, backgroundColor: '#f59e0b', borderRadius: 6 }]},
    options: { indexAxis: 'y', responsive: true, plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, grid: { color: '#f3f4f6' } } } }
  });
}
</script>
</body>
</html>
