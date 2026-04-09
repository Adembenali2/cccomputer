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
    body { margin:0; font-family: inherit; background:var(--bg-page); }
    .card { background:var(--bg-card); border-radius:14px; padding:24px; box-shadow:var(--shadow); }
    .grid-dashboard { display:grid; grid-template-columns:repeat(3,1fr); gap:20px; }
    @media (max-width: 1024px) { .grid-dashboard { grid-template-columns: repeat(2,1fr) !important; } }
    @media (max-width: 640px) { .grid-dashboard { grid-template-columns: 1fr !important; } header nav { display:none !important; } }
  </style>
</head>
<body>
<?php require_once __DIR__ . '/../source/templates/header.php'; ?>
<div class="page-container">
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

</body>
</html>
