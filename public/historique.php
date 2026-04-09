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
if (!function_exists('csp_nonce')) {
    function csp_nonce(): string {
        $nonce = $GLOBALS['csp_nonce'] ?? '';
        return $nonce === '' ? '' : 'nonce="' . htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"';
    }
}
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
function colExists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$filtreType = (string)($_GET['type'] ?? 'tout');
$filtreDate = (string)($_GET['date'] ?? '');
$filtreSearch = (string)($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$parPage = 25;
$offset = ($page - 1) * $parPage;

$clientNameCol = colExists($pdo, 'clients', 'nom') ? 'nom' : (colExists($pdo, 'clients', 'raison_sociale') ? 'raison_sociale' : 'id');
$savClientCol = colExists($pdo, 'sav', 'client_id') ? 'client_id' : 'id_client';
$livClientCol = colExists($pdo, 'livraisons', 'client_id') ? 'client_id' : 'id_client';
$factClientCol = colExists($pdo, 'factures', 'client_id') ? 'client_id' : 'id_client';
$factNumCol = colExists($pdo, 'factures', 'numero_facture') ? 'numero_facture' : (colExists($pdo, 'factures', 'reference') ? 'reference' : 'id');
$savCreatedCol = colExists($pdo, 'sav', 'created_at') ? 'created_at' : (colExists($pdo, 'sav', 'date_creation') ? 'date_creation' : 'NOW()');
$livCreatedCol = colExists($pdo, 'livraisons', 'created_at') ? 'created_at' : (colExists($pdo, 'livraisons', 'date_creation') ? 'date_creation' : 'NOW()');
$facCreatedCol = colExists($pdo, 'factures', 'created_at') ? 'created_at' : (colExists($pdo, 'factures', 'date_creation') ? 'date_creation' : 'NOW()');
$cliCreatedCol = colExists($pdo, 'clients', 'created_at') ? 'created_at' : (colExists($pdo, 'clients', 'date_creation') ? 'date_creation' : 'NOW()');
$savReferenceCol = colExists($pdo, 'sav', 'reference_sav') ? 'reference_sav' : (colExists($pdo, 'sav', 'reference') ? 'reference' : 'id');
$savDescCol = colExists($pdo, 'sav', 'description_panne') ? 'description_panne' : (colExists($pdo, 'sav', 'description') ? 'description' : 'statut');
$payFactureCol = colExists($pdo, 'paiements', 'facture_id') ? 'facture_id' : (colExists($pdo, 'paiements', 'id_facture') ? 'id_facture' : 'facture_id');
$clientVilleExpr = colExists($pdo, 'clients', 'ville') ? "COALESCE(c.ville,'')" : "''";
$clientPhoneExpr = colExists($pdo, 'clients', 'telephone') ? "COALESCE(c.telephone,'')" : "''";

$events = [];

$savRows = $pdo->query("
  SELECT
    'SAV' as type,
    CONCAT('SAV ouvert — ', COALESCE(c.{$clientNameCol},'Client')) as label,
    CONCAT('Réf: ', COALESCE(s.{$savReferenceCol},'—'), ' | ', COALESCE(s.{$savDescCol},'—')) as detail,
    s.statut,
    s.{$savCreatedCol} as created_at,
    s.id as ref_id,
    'sav.php' as page_url
  FROM sav s
  LEFT JOIN clients c ON s.{$savClientCol} = c.id
  ORDER BY s.{$savCreatedCol} DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$livRows = $pdo->query("
  SELECT
    'Livraison' as type,
    CONCAT('Livraison — ', COALESCE(c.{$clientNameCol},'Client')) as label,
    CONCAT('Statut: ', COALESCE(l.statut,'—')) as detail,
    l.statut,
    l.{$livCreatedCol} as created_at,
    l.id as ref_id,
    'livraison.php' as page_url
  FROM livraisons l
  LEFT JOIN clients c ON l.{$livClientCol} = c.id
  ORDER BY l.{$livCreatedCol} DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$facRows = $pdo->query("
  SELECT
    'Facture' as type,
    CONCAT(COALESCE(f.{$factNumCol},f.id), ' — ', COALESCE(c.{$clientNameCol},'Client')) as label,
    CONCAT('Montant: ', FORMAT(COALESCE(f.montant_ttc,0), 2), ' € | Statut: ', COALESCE(f.statut,'—')) as detail,
    f.statut,
    f.{$facCreatedCol} as created_at,
    f.id as ref_id,
    'factures.php' as page_url
  FROM factures f
  LEFT JOIN clients c ON f.{$factClientCol} = c.id
  ORDER BY f.{$facCreatedCol} DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$paiRows = $pdo->query("
  SELECT
    'Paiement' as type,
    CONCAT('Paiement — ', COALESCE(c.{$clientNameCol},'Client')) as label,
    CONCAT('Montant: ', FORMAT(COALESCE(p.montant,0), 2), ' € | Mode: ', COALESCE(p.mode_paiement,'—')) as detail,
    'recu' as statut,
    p.created_at,
    p.id as ref_id,
    'paiements.php' as page_url
  FROM paiements p
  LEFT JOIN factures f ON p.{$payFactureCol} = f.id
  LEFT JOIN clients c ON f.{$factClientCol} = c.id
  ORDER BY p.created_at DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$stockRows = $pdo->query("
  SELECT
    'Stock' as type,
    CONCAT(COALESCE(sm.type_mouvement,'Mouvement'), ' — ', COALESCE(s.designation,'Article')) as label,
    CONCAT('Qté: ', COALESCE(sm.quantite_avant,0), ' → ', COALESCE(sm.quantite_apres,0),
           IF(sm.motif IS NOT NULL, CONCAT(' | ', sm.motif), '')) as detail,
    sm.type_mouvement as statut,
    sm.created_at,
    sm.id as ref_id,
    'stock.php' as page_url
  FROM stock_mouvements sm
  LEFT JOIN stock s ON sm.stock_id = s.id
  ORDER BY sm.created_at DESC
  LIMIT 200
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$clientRows = $pdo->query("
  SELECT
    'Client' as type,
    CONCAT('Nouveau client — ', COALESCE(c.{$clientNameCol},'Client')) as label,
    CONCAT(
      {$clientVilleExpr},
      IF({$clientPhoneExpr} <> '', CONCAT(' | ', {$clientPhoneExpr}), '')
    ) as detail,
    'actif' as statut,
    c.{$cliCreatedCol} as created_at,
    c.id as ref_id,
    'clients.php' as page_url
  FROM clients c
  WHERE c.statut = 'actif'
  ORDER BY c.{$cliCreatedCol} DESC
  LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$events = array_merge($savRows, $livRows, $facRows, $paiRows, $stockRows, $clientRows);
usort($events, static fn(array $a, array $b): int => strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']));

if ($filtreType !== 'tout') {
    $events = array_filter($events, static fn(array $e): bool => strtolower((string)$e['type']) === strtolower($filtreType));
}
if ($filtreDate !== '') {
    $events = array_filter($events, static fn(array $e): bool => date('Y-m-d', strtotime((string)$e['created_at'])) === $filtreDate);
}
if ($filtreSearch !== '') {
    $s = strtolower($filtreSearch);
    $events = array_filter($events, static fn(array $e): bool =>
        str_contains(strtolower((string)$e['label']), $s) || str_contains(strtolower((string)$e['detail']), $s)
    );
}

$events = array_values($events);
$totalEvents = count($events);
$totalPages = max(1, (int)ceil($totalEvents / $parPage));
$eventsPaged = array_slice($events, $offset, $parPage);

$typesConfig = [
  'SAV'       => ['icone'=>'🔧', 'bg'=>'#fef3c7', 'color'=>'#92400e', 'bgDark'=>'rgba(254,243,199,.15)'],
  'Livraison' => ['icone'=>'🚚', 'bg'=>'#dbeafe', 'color'=>'#1d4ed8', 'bgDark'=>'rgba(219,234,254,.15)'],
  'Facture'   => ['icone'=>'📄', 'bg'=>'#ede9fe', 'color'=>'#5b21b6', 'bgDark'=>'rgba(237,233,254,.15)'],
  'Paiement'  => ['icone'=>'💶', 'bg'=>'#dcfce7', 'color'=>'#166534', 'bgDark'=>'rgba(220,252,231,.15)'],
  'Stock'     => ['icone'=>'📦', 'bg'=>'#ffedd5', 'color'=>'#9a3412', 'bgDark'=>'rgba(255,237,213,.15)'],
  'Client'    => ['icone'=>'👥', 'bg'=>'#e0f2fe', 'color'=>'#075985', 'bgDark'=>'rgba(224,242,254,.15)'],
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Historique — CCComputer</title>
  <link rel="stylesheet" href="/assets/css/global.css">
  <script src="/assets/js/darkmode.js" <?= csp_nonce() ?>></script>
</head>
<body>
<?php require_once __DIR__ . '/../includes/header_nav.php'; ?>

<div class="page-container">
  <div style="margin-bottom:24px;">
    <h1 class="page-title">Historique</h1>
    <p class="page-subtitle">Toutes les activités du système — <?= number_format($totalEvents, 0, ',', ' ') ?> événements</p>
  </div>

  <div class="card" style="margin-bottom:20px;padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="search" value="<?= htmlspecialchars($filtreSearch, ENT_QUOTES, 'UTF-8') ?>" placeholder="Rechercher..." style="width:220px;">
      <select name="type" style="min-width:150px;">
        <option value="tout" <?= $filtreType==='tout'?'selected':'' ?>>Tous les types</option>
        <option value="SAV" <?= $filtreType==='SAV'?'selected':'' ?>>🔧 SAV</option>
        <option value="Livraison" <?= $filtreType==='Livraison'?'selected':'' ?>>🚚 Livraisons</option>
        <option value="Facture" <?= $filtreType==='Facture'?'selected':'' ?>>📄 Factures</option>
        <option value="Paiement" <?= $filtreType==='Paiement'?'selected':'' ?>>💶 Paiements</option>
        <option value="Stock" <?= $filtreType==='Stock'?'selected':'' ?>>📦 Stock</option>
        <option value="Client" <?= $filtreType==='Client'?'selected':'' ?>>👥 Clients</option>
      </select>
      <input type="date" name="date" value="<?= htmlspecialchars($filtreDate, ENT_QUOTES, 'UTF-8') ?>">
      <button type="submit" style="background:#6366f1;color:#fff;border:none;border-radius:8px;padding:9px 18px;font-weight:600;cursor:pointer;">Filtrer</button>
      <?php if ($filtreType !== 'tout' || $filtreDate || $filtreSearch): ?>
        <a href="historique.php" style="color:#ef4444;font-size:13px;text-decoration:none;font-weight:500;">✕ Effacer filtres</a>
      <?php endif; ?>
      <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;">
        <?php $counts = array_count_values(array_column($events, 'type')); foreach ($typesConfig as $t => $cfg): if (!isset($counts[$t])) continue; ?>
          <span style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;padding:4px 10px;border-radius:999px;font-size:11px;font-weight:600;"><?= $cfg['icone'] ?> <?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?> (<?= (int)$counts[$t] ?>)</span>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden;">
    <?php if (empty($eventsPaged)): ?>
      <div style="text-align:center;padding:60px;color:var(--text-muted);"><div style="font-size:40px;margin-bottom:12px;">📭</div><div style="font-size:16px;font-weight:500;">Aucun événement trouvé</div></div>
    <?php else: ?>
      <?php $datePrecedente = ''; foreach ($eventsPaged as $e): $dateEvent = date('d/m/Y', strtotime((string)$e['created_at'])); $heureEvent = date('H:i', strtotime((string)$e['created_at'])); $cfg = $typesConfig[$e['type']] ?? ['icone'=>'📌','bg'=>'#f3f4f6','color'=>'#374151']; ?>
        <?php if ($dateEvent !== $datePrecedente): $datePrecedente = $dateEvent; ?>
          <div style="padding:10px 24px;background:var(--bg-page);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--text-muted);border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
            <?php $ts = strtotime((string)$e['created_at']); if (date('Y-m-d',$ts) === date('Y-m-d')) echo "Aujourd'hui — {$dateEvent}"; elseif (date('Y-m-d',$ts) === date('Y-m-d', strtotime('-1 day'))) echo "Hier — {$dateEvent}"; else echo $dateEvent; ?>
          </div>
        <?php endif; ?>
        <div style="display:flex;align-items:flex-start;gap:16px;padding:14px 24px;border-bottom:1px solid var(--border);transition:background .15s;" onmouseover="this.style.background='var(--bg-page)'" onmouseout="this.style.background=''">
          <div style="width:38px;height:38px;border-radius:10px;background:<?= $cfg['bg'] ?>;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:16px;margin-top:2px;"><?= $cfg['icone'] ?></div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <span style="font-size:14px;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars((string)$e['label'], ENT_QUOTES, 'UTF-8') ?></span>
              <span style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;"><?= htmlspecialchars((string)$e['type'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div style="font-size:12px;color:var(--text-second);margin-top:3px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= htmlspecialchars((string)$e['detail'], ENT_QUOTES, 'UTF-8') ?></div>
          </div>
          <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
            <span style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($heureEvent, ENT_QUOTES, 'UTF-8') ?></span>
            <a href="<?= htmlspecialchars((string)$e['page_url'], ENT_QUOTES, 'UTF-8') ?>" style="font-size:11px;color:#6366f1;text-decoration:none;font-weight:500;white-space:nowrap;">Voir →</a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:20px;flex-wrap:wrap;">
    <?php if ($page > 1): ?><a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page-1])), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text-primary);background:var(--bg-card);">← Précédent</a><?php endif; ?>
    <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$i])), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 14px;border-radius:8px;text-decoration:none;<?= $i===$page ? 'background:#6366f1;color:#fff;border:1px solid #6366f1;' : 'border:1px solid var(--border);color:var(--text-primary);background:var(--bg-card);' ?>"><?= $i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?><a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET,['page'=>$page+1])), ENT_QUOTES, 'UTF-8') ?>" style="padding:8px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text-primary);background:var(--bg-card);">Suivant →</a><?php endif; ?>
    <span style="font-size:12px;color:var(--text-muted);margin-left:8px;">Page <?= (int)$page ?> / <?= (int)$totalPages ?> — <?= (int)$totalEvents ?> événements</span>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
