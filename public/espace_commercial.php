<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

function colExistsEC(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$roleSession = strtolower((string)($_SESSION['role'] ?? $_SESSION['emploi'] ?? ''));
$rolesAutorises = ['admin','dirigeant','secretaire','commercial','charge_relation_clients'];
if (!in_array($roleSession, $rolesAutorises, true)) {
    header('Location: dashboard.php');
    exit;
}

$userId  = (int)($_SESSION['user_id'] ?? 0);
$mois    = date('Y-m');
$annee   = date('Y');

$clientNameCol = colExistsEC($pdo, 'clients', 'nom') ? 'nom' : (colExistsEC($pdo, 'clients', 'raison_sociale') ? 'raison_sociale' : 'id');
$clientCreatedCol = colExistsEC($pdo, 'clients', 'created_at') ? 'created_at' : (colExistsEC($pdo, 'clients', 'date_creation') ? 'date_creation' : 'NOW()');
$clientStatutCol = colExistsEC($pdo, 'clients', 'statut') ? 'statut' : null;
$factClientCol = colExistsEC($pdo, 'factures', 'client_id') ? 'client_id' : 'id_client';
$factDateCol = colExistsEC($pdo, 'factures', 'date_facture') ? 'date_facture' : (colExistsEC($pdo, 'factures', 'created_at') ? 'created_at' : 'NOW()');
$factNumCol = colExistsEC($pdo, 'factures', 'numero_facture') ? 'numero_facture' : (colExistsEC($pdo, 'factures', 'reference') ? 'reference' : 'id');
$paiFactureCol = colExistsEC($pdo, 'paiements', 'facture_id') ? 'facture_id' : 'id_facture';
$factDueExpr = colExistsEC($pdo, 'factures', 'date_echeance')
    ? 'date_echeance'
    : "DATE_ADD({$factDateCol}, INTERVAL 30 DAY)";

$caMoisStmt = $pdo->prepare("
  SELECT COALESCE(SUM(montant_ttc),0) FROM factures
  WHERE statut NOT IN ('annulee','brouillon')
    AND DATE_FORMAT({$factDateCol},'%Y-%m')=?
");
$caMoisStmt->execute([$mois]);
$caMois = (float)$caMoisStmt->fetchColumn();

$caAnneeStmt = $pdo->prepare("
  SELECT COALESCE(SUM(montant_ttc),0) FROM factures
  WHERE statut NOT IN ('annulee','brouillon') AND YEAR({$factDateCol})=?
");
$caAnneeStmt->execute([$annee]);
$caAnnee = (float)$caAnneeStmt->fetchColumn();

$facturesImpayees = $pdo->query("
  SELECT COUNT(*) as nb, COALESCE(SUM(montant_ttc-montant_paye),0) as montant
  FROM factures WHERE statut IN ('envoyee','partielle','en_retard')
")->fetch(PDO::FETCH_ASSOC) ?: ['nb' => 0, 'montant' => 0];

$facturesEnRetard = (int)$pdo->query("
  SELECT COUNT(*) FROM factures
  WHERE statut IN ('envoyee','partielle','en_retard')
    AND {$factDueExpr} < CURDATE()
")->fetchColumn();

$clientsWhere = $clientStatutCol ? "WHERE {$clientStatutCol}='actif'" : '';
$totalClients = (int)$pdo->query("SELECT COUNT(*) FROM clients {$clientsWhere}")->fetchColumn();

$nouveauxClientsMoisStmt = $pdo->prepare("
  SELECT COUNT(*) FROM clients
  " . ($clientStatutCol ? "WHERE {$clientStatutCol}='actif' AND " : "WHERE ") . "DATE_FORMAT({$clientCreatedCol},'%Y-%m')=?
");
$nouveauxClientsMoisStmt->execute([$mois]);
$nouveauxClientsMois = (int)$nouveauxClientsMoisStmt->fetchColumn();

$objectifStmt = $pdo->prepare("SELECT * FROM commercial_objectifs WHERE mois=? LIMIT 1");
$objectifStmt->execute([$mois]);
$objectif = $objectifStmt->fetch(PDO::FETCH_ASSOC) ?: [];
$objectifCA = (float)($objectif['objectif_ca'] ?? 0);
$pctCA = $objectifCA > 0 ? min(100, (int)round(($caMois / $objectifCA) * 100)) : 0;

$aRelancer = $pdo->query("
  SELECT f.*, c.{$clientNameCol} as client_nom, c.email as client_email,
         (f.montant_ttc - f.montant_paye) as solde,
         DATEDIFF(CURDATE(), {$factDueExpr}) as jours_retard
  FROM factures f
  LEFT JOIN clients c ON f.{$factClientCol} = c.id
  WHERE f.statut IN ('envoyee','partielle','en_retard')
    AND {$factDueExpr} < CURDATE()
  ORDER BY jours_retard DESC
  LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$activites = $pdo->query("
  SELECT a.*, c.{$clientNameCol} as client_nom
  FROM commercial_activites a
  LEFT JOIN clients c ON a.client_id = c.id
  WHERE a.statut = 'planifie' AND a.date_activite >= CURDATE()
  ORDER BY a.date_activite ASC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$derniersPaiements = $pdo->query("
  SELECT p.*, f.{$factNumCol} as numero_facture, c.{$clientNameCol} as client_nom
  FROM paiements p
  LEFT JOIN factures f ON p.{$paiFactureCol} = f.id
  LEFT JOIN clients c ON f.{$factClientCol} = c.id
  ORDER BY p.created_at DESC
  LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$topClientsStmt = $pdo->prepare("
  SELECT c.id, c.{$clientNameCol} as nom, COALESCE(SUM(f.montant_ttc),0) as ca
  FROM clients c
  LEFT JOIN factures f ON f.{$factClientCol}=c.id
    AND f.statut NOT IN ('annulee','brouillon')
    AND DATE_FORMAT(f.{$factDateCol},'%Y-%m')=?
  GROUP BY c.id, c.{$clientNameCol}
  HAVING ca > 0
  ORDER BY ca DESC
  LIMIT 5
");
$topClientsStmt->execute([$mois]);
$topClients = $topClientsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$maxCA = !empty($topClients) ? (float)$topClients[0]['ca'] : 1.0;

$clientsList = $pdo->query("SELECT id, {$clientNameCol} as nom FROM clients {$clientsWhere} ORDER BY {$clientNameCol}")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$csrfToken = $_SESSION['csrf_token'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <title>Espace Commercial — CCComputer</title>
  <link rel="stylesheet" href="/assets/css/global.css">
  <script src="/assets/js/darkmode.js" <?= csp_nonce() ?>></script>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header_nav.php'; ?>

<div class="page-container">
  <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:24px;">
    <div>
      <h1 class="page-title">Espace Commercial</h1>
      <p class="page-subtitle">Pilotage commercial — <?= htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div style="display:flex;gap:10px;">
      <button type="button" onclick="ouvrirModalObjectif()" style="background:var(--bg-card);color:var(--text-primary);border:1px solid var(--border);border-radius:8px;padding:9px 16px;font-size:13px;cursor:pointer;">🎯 Définir objectif</button>
      <a href="factures.php?action=new" style="background:#6366f1;color:#fff;border-radius:8px;padding:9px 16px;font-size:13px;font-weight:600;text-decoration:none;">+ Nouvelle facture</a>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;">
    <div class="card" style="border-left:4px solid #6366f1;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-second);margin-bottom:8px;">CA ce mois</div>
      <div style="font-size:26px;font-weight:700;color:var(--text-primary);"><?= number_format($caMois, 0, ',', ' ') ?> €</div>
      <?php if ($objectifCA > 0): ?>
      <div style="margin-top:8px;">
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-muted);margin-bottom:4px;"><span>Objectif: <?= number_format($objectifCA,0,',',' ') ?> €</span><span><?= (int)$pctCA ?>%</span></div>
        <div style="height:5px;background:var(--border);border-radius:3px;"><div style="width:<?= (int)$pctCA ?>%;height:100%;background:#6366f1;border-radius:3px;"></div></div>
      </div>
      <?php else: ?>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Année: <?= number_format($caAnnee,0,',',' ') ?> €</div>
      <?php endif; ?>
    </div>

    <div class="card" style="border-left:4px solid #ef4444;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-second);margin-bottom:8px;">Impayés</div>
      <div style="font-size:26px;font-weight:700;color:#ef4444;"><?= number_format((float)$facturesImpayees['montant'],0,',',' ') ?> €</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px;"><?= (int)$facturesImpayees['nb'] ?> facture(s) · <span style="color:#ef4444;"><?= (int)$facturesEnRetard ?> en retard</span></div>
    </div>

    <div class="card" style="border-left:4px solid #10b981;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-second);margin-bottom:8px;">Clients actifs</div>
      <div style="font-size:26px;font-weight:700;color:var(--text-primary);"><?= (int)$totalClients ?></div>
      <div style="font-size:11px;color:#10b981;margin-top:4px;">+<?= (int)$nouveauxClientsMois ?> ce mois</div>
    </div>

    <div class="card" style="border-left:4px solid #f59e0b;">
      <div style="font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-second);margin-bottom:8px;">Activités planifiées</div>
      <div style="font-size:26px;font-weight:700;color:var(--text-primary);"><?= count($activites) ?></div>
      <button type="button" onclick="ouvrirModalActivite()" style="background:none;border:none;font-size:11px;color:#f59e0b;cursor:pointer;padding:0;margin-top:4px;font-weight:500;">+ Planifier →</button>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1.6fr 1fr;gap:20px;margin-bottom:20px;">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div style="font-size:14px;font-weight:600;color:var(--text-primary);">🔔 Factures à relancer</div>
        <a href="factures.php?statut=en_retard" style="font-size:12px;color:#6366f1;text-decoration:none;">Voir tout →</a>
      </div>
      <?php if (empty($aRelancer)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);">✅ Aucune facture en retard</div>
      <?php else: ?>
      <table>
        <thead><tr><th>Client</th><th>Facture</th><th>Solde dû</th><th>Retard</th><th>Actions</th></tr></thead>
        <tbody>
          <?php foreach ($aRelancer as $f): ?>
          <tr>
            <td style="font-weight:500;"><?= htmlspecialchars((string)$f['client_nom'], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="font-family:monospace;font-size:12px;"><?= htmlspecialchars((string)$f[$factNumCol], ENT_QUOTES, 'UTF-8') ?></td>
            <td style="font-weight:700;color:#ef4444;"><?= number_format((float)$f['solde'],2,',',' ') ?> €</td>
            <td><span style="background:#fee2e2;color:#991b1b;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:600;"><?= (int)$f['jours_retard'] ?>j</span></td>
            <td>
              <div style="display:flex;gap:6px;">
                <button type="button" onclick="relancerFacture(<?= (int)$f['id'] ?>, '<?= htmlspecialchars(addslashes((string)$f['client_nom']), ENT_QUOTES, 'UTF-8') ?>')" style="background:#fef3c7;color:#92400e;border:none;border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;font-weight:600;">✉️ Relancer</button>
                <a href="factures.php?id=<?= (int)$f['id'] ?>" style="background:var(--bg-page);color:var(--text-second);border:1px solid var(--border);border-radius:6px;padding:4px 10px;font-size:11px;text-decoration:none;">👁</a>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>

    <div class="card">
      <div style="font-size:14px;font-weight:600;color:var(--text-primary);margin-bottom:16px;">🏆 Top clients ce mois</div>
      <?php if (empty($topClients)): ?>
        <div style="text-align:center;padding:30px;color:var(--text-muted);">Aucune donnée ce mois</div>
      <?php else: ?>
      <?php foreach ($topClients as $i => $c): ?>
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
          <span style="font-size:13px;font-weight:500;color:var(--text-primary);"><?= ($i + 1) ?>. <a href="clients.php?id=<?= (int)$c['id'] ?>" style="color:var(--text-primary);text-decoration:none;"><?= htmlspecialchars((string)$c['nom'], ENT_QUOTES, 'UTF-8') ?></a></span>
          <span style="font-size:13px;font-weight:700;color:#6366f1;"><?= number_format((float)$c['ca'],0,',',' ') ?> €</span>
        </div>
        <div style="height:6px;background:var(--border);border-radius:3px;"><div style="width:<?= max(0, min(100, (int)round(((float)$c['ca'] / max(1.0, $maxCA)) * 100))) ?>%;height:100%;background:#6366f1;border-radius:3px;"></div></div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div style="font-size:14px;font-weight:600;color:var(--text-primary);">💶 Paiements reçus</div>
        <a href="paiements.php" style="font-size:12px;color:#6366f1;text-decoration:none;">Voir tout →</a>
      </div>
      <?php foreach ($derniersPaiements as $p): ?>
      <div style="display:flex;align-items:center;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
        <div>
          <div style="font-size:13px;font-weight:500;color:var(--text-primary);"><?= htmlspecialchars((string)$p['client_nom'], ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars((string)$p['numero_facture'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(date('d/m/Y', strtotime((string)$p['created_at'])), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst((string)$p['mode_paiement']), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <span style="font-weight:700;font-size:14px;color:#10b981;">+<?= number_format((float)$p['montant'],0,',',' ') ?> €</span>
      </div>
      <?php endforeach; ?>
      <?php if (empty($derniersPaiements)): ?><div style="text-align:center;padding:30px;color:var(--text-muted);">Aucun paiement</div><?php endif; ?>
    </div>

    <div class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <div style="font-size:14px;font-weight:600;color:var(--text-primary);">📅 Activités planifiées</div>
        <button type="button" onclick="ouvrirModalActivite()" style="background:#6366f1;color:#fff;border:none;border-radius:6px;padding:5px 12px;font-size:12px;cursor:pointer;font-weight:600;">+ Ajouter</button>
      </div>
      <?php $typesActivite = ['appel'=>['📞','#dbeafe','#1d4ed8'],'email'=>['✉️','#ede9fe','#5b21b6'],'rdv'=>['🤝','#dcfce7','#166534'],'relance'=>['🔔','#fef3c7','#92400e'],'devis'=>['📋','#ffedd5','#9a3412'],'autre'=>['📌','#f3f4f6','#374151']]; ?>
      <?php foreach ($activites as $act): [$ic,$bg,$col] = $typesActivite[$act['type']] ?? ['📌','#f3f4f6','#374151']; ?>
      <div style="display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);">
        <div style="width:32px;height:32px;border-radius:8px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;font-size:14px;flex-shrink:0;"><?= $ic ?></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:500;color:var(--text-primary);"><?= htmlspecialchars((string)$act['titre'], ENT_QUOTES, 'UTF-8') ?></div>
          <div style="font-size:11px;color:var(--text-muted);"><?= htmlspecialchars((string)$act['client_nom'], ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(date('d/m à H:i', strtotime((string)$act['date_activite'])), ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <button type="button" onclick="marquerFaite(<?= (int)$act['id'] ?>)" style="background:none;border:1px solid var(--border);border-radius:6px;padding:3px 8px;font-size:11px;cursor:pointer;color:var(--text-second);">✓ Fait</button>
      </div>
      <?php endforeach; ?>
      <?php if (empty($activites)): ?><div style="text-align:center;padding:30px;color:var(--text-muted);">Aucune activité planifiée</div><?php endif; ?>
    </div>
  </div>
</div>

<div id="modalActivite" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-title">📅 Nouvelle activité commerciale</div>
    <form id="formActivite">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <div style="display:grid;gap:14px;">
        <div>
          <label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Client *</label>
          <select name="client_id" required style="width:100%;">
            <option value="">Sélectionner un client</option>
            <?php foreach ($clientsList as $cl): ?>
            <option value="<?= (int)$cl['id'] ?>"><?= htmlspecialchars((string)$cl['nom'], ENT_QUOTES, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Type *</label><select name="type" required style="width:100%;"><option value="appel">📞 Appel</option><option value="email">✉️ Email</option><option value="rdv">🤝 Rendez-vous</option><option value="relance">🔔 Relance</option><option value="devis">📋 Devis</option><option value="autre">📌 Autre</option></select></div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Titre *</label><input type="text" name="titre" required placeholder="Ex: Appel de suivi" style="width:100%;"></div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Date et heure *</label><input type="datetime-local" name="date_activite" required style="width:100%;" value="<?= htmlspecialchars(date('Y-m-d\TH:i'), ENT_QUOTES, 'UTF-8') ?>"></div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Notes</label><textarea name="description" rows="3" style="width:100%;resize:vertical;" placeholder="Détails de l'activité..."></textarea></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
          <button type="button" onclick="fermerModal('modalActivite')" style="background:var(--bg-page);color:var(--text-primary);border:1px solid var(--border);border-radius:8px;padding:9px 20px;cursor:pointer;">Annuler</button>
          <button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-weight:600;cursor:pointer;">Enregistrer</button>
        </div>
      </div>
    </form>
  </div>
</div>

<div id="modalObjectif" class="modal-overlay" style="display:none;">
  <div class="modal-box" style="max-width:400px;">
    <div class="modal-title">🎯 Objectif — <?= htmlspecialchars(date('F Y'), ENT_QUOTES, 'UTF-8') ?></div>
    <form id="formObjectif">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="mois" value="<?= htmlspecialchars($mois, ENT_QUOTES, 'UTF-8') ?>">
      <div style="display:grid;gap:14px;">
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Objectif CA (€)</label><input type="number" name="objectif_ca" step="100" min="0" style="width:100%;" value="<?= htmlspecialchars((string)($objectif['objectif_ca'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 50000"></div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Objectif nouveaux clients</label><input type="number" name="objectif_clients" min="0" style="width:100%;" value="<?= htmlspecialchars((string)($objectif['objectif_clients'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 10"></div>
        <div><label style="font-size:12px;font-weight:600;color:var(--text-second);display:block;margin-bottom:5px;">Objectif factures émises</label><input type="number" name="objectif_factures" min="0" style="width:100%;" value="<?= htmlspecialchars((string)($objectif['objectif_factures'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 30"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;"><button type="button" onclick="fermerModal('modalObjectif')" style="background:var(--bg-page);color:var(--text-primary);border:1px solid var(--border);border-radius:8px;padding:9px 20px;cursor:pointer;">Annuler</button><button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-weight:600;cursor:pointer;">Enregistrer</button></div>
      </div>
    </form>
  </div>
</div>

<script <?= csp_nonce() ?>>
function ouvrirModalActivite() { document.getElementById('modalActivite').style.display='flex'; }
function ouvrirModalObjectif() { document.getElementById('modalObjectif').style.display='flex'; }
function fermerModal(id) { document.getElementById(id).style.display='none'; }
document.querySelectorAll('.modal-overlay').forEach(function(m){ m.addEventListener('click', function(e){ if (e.target === this) fermerModal(this.id); }); });

document.getElementById('formActivite').addEventListener('submit', async function(e) {
  e.preventDefault();
  const res = await fetch('/API/commercial_activite_save.php', { method: 'POST', body: new FormData(this) });
  const json = await res.json();
  if (json.success) { fermerModal('modalActivite'); location.reload(); } else { alert(json.message || 'Erreur'); }
});

document.getElementById('formObjectif').addEventListener('submit', async function(e) {
  e.preventDefault();
  const res = await fetch('/API/commercial_objectif_save.php', { method: 'POST', body: new FormData(this) });
  const json = await res.json();
  if (json.success) { fermerModal('modalObjectif'); location.reload(); } else { alert(json.message || 'Erreur'); }
});

async function relancerFacture(id, nom) {
  if (!confirm('Envoyer une relance email à ' + nom + ' ?')) return;
  const fd = new FormData();
  fd.append('facture_id', id);
  fd.append('csrf_token', '<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>');
  const res = await fetch('/API/factures_relancer.php', { method:'POST', body:fd });
  const json = await res.json();
  alert(json.success ? '✅ Relance envoyée !' : ('❌ ' + (json.message || 'Erreur')));
}

async function marquerFaite(id) {
  const fd = new FormData();
  fd.append('id', id);
  fd.append('statut', 'fait');
  fd.append('csrf_token', '<?= htmlspecialchars((string)$csrfToken, ENT_QUOTES, 'UTF-8') ?>');
  const res = await fetch('/API/commercial_activite_save.php', { method:'POST', body:fd });
  const json = await res.json();
  if (json.success) location.reload();
}
</script>
</body>
</html>
