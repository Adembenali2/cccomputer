<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
$pdo = getPdo();
require_once __DIR__ . '/../includes/security_headers.php';
require_once __DIR__ . '/../includes/helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    header('Location: /public/login.php');
    exit;
}

function colExistsHist(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare("
      SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

$histDateCol = colExistsHist($pdo, 'historique', 'created_at') ? 'created_at' : 'date_action';
$hasTypeCol = colExistsHist($pdo, 'historique', 'type');
$hasLabelCol = colExistsHist($pdo, 'historique', 'label');
$hasDetailCol = colExistsHist($pdo, 'historique', 'detail');
$hasUserNomCol = colExistsHist($pdo, 'historique', 'user_nom');
$hasRefUrlCol = colExistsHist($pdo, 'historique', 'ref_url');

$typeExpr = $hasTypeCol
    ? "h.type"
    : "CASE
        WHEN h.action LIKE 'client%' OR h.action LIKE 'photocopieur%' THEN 'client'
        WHEN h.action LIKE 'facture%' THEN 'facture'
        WHEN h.action LIKE 'paiement%' THEN 'paiement'
        WHEN h.action LIKE 'sav%' THEN 'sav'
        WHEN h.action LIKE 'livraison%' THEN 'livraison'
        WHEN h.action LIKE 'stock%' OR h.action LIKE 'mouvement_stock%' THEN 'stock'
        WHEN h.action LIKE 'connexion%' OR h.action LIKE 'login%' OR h.action LIKE 'deconnexion%' THEN 'connexion'
        ELSE 'utilisateur'
      END";
$labelExpr = $hasLabelCol ? "h.label" : "COALESCE(NULLIF(h.details,''), h.action)";
$detailExpr = $hasDetailCol ? "h.detail" : "COALESCE(h.details,'')";
$userNomExpr = $hasUserNomCol ? "h.user_nom" : "CONCAT('Utilisateur #', COALESCE(CAST(h.user_id AS CHAR), '0'))";
$refUrlExpr = $hasRefUrlCol ? "h.ref_url" : "NULL";

$filtreType = (string)($_GET['type'] ?? 'tout');
$filtreDate = (string)($_GET['date'] ?? '');
$filtreSearch = trim((string)($_GET['search'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$parPage = 30;
$offset = ($page - 1) * $parPage;

$where = ['1=1'];
$params = [];

if ($filtreType !== 'tout' && $filtreType !== '') {
    $where[] = "{$typeExpr} = ?";
    $params[] = $filtreType;
}

if ($filtreDate !== '') {
    $where[] = "DATE(h.{$histDateCol}) = ?";
    $params[] = $filtreDate;
}

if ($filtreSearch !== '') {
    $where[] = "({$labelExpr} LIKE ? OR {$detailExpr} LIKE ? OR {$userNomExpr} LIKE ?)";
    $like = '%' . $filtreSearch . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM historique h WHERE $whereSQL");
$countStmt->execute($params);
$totalEvents = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalEvents / $parPage));

$dataStmt = $pdo->prepare("
  SELECT
    h.*,
    {$typeExpr} AS event_type,
    {$labelExpr} AS event_label,
    {$detailExpr} AS event_detail,
    {$userNomExpr} AS event_user_nom,
    {$refUrlExpr} AS event_ref_url,
    h.{$histDateCol} AS event_created_at
  FROM historique h
  WHERE $whereSQL
  ORDER BY h.{$histDateCol} DESC
  LIMIT $parPage OFFSET $offset
");
$dataStmt->execute($params);
$events = $dataStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$countsStmt = $pdo->query("SELECT {$typeExpr} AS type, COUNT(*) as nb FROM historique h GROUP BY {$typeExpr}");
$counts = [];
foreach (($countsStmt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
    $counts[(string)$row['type']] = (int)$row['nb'];
}
$totalTous = array_sum($counts);

$typesConfig = [
    'client'       => ['icone' => '👥', 'bg' => '#e0f2fe', 'color' => '#075985', 'label' => 'Clients'],
    'facture'      => ['icone' => '📄', 'bg' => '#ede9fe', 'color' => '#5b21b6', 'label' => 'Factures'],
    'paiement'     => ['icone' => '💶', 'bg' => '#dcfce7', 'color' => '#166534', 'label' => 'Paiements'],
    'sav'          => ['icone' => '🔧', 'bg' => '#fef3c7', 'color' => '#92400e', 'label' => 'SAV'],
    'livraison'    => ['icone' => '🚚', 'bg' => '#dbeafe', 'color' => '#1d4ed8', 'label' => 'Livraisons'],
    'stock'        => ['icone' => '📦', 'bg' => '#ffedd5', 'color' => '#9a3412', 'label' => 'Stock'],
    'photocopieur' => ['icone' => '🖨️', 'bg' => '#f0fdf4', 'color' => '#166534', 'label' => 'Photocopieurs'],
    'connexion'    => ['icone' => '🔐', 'bg' => '#f3f4f6', 'color' => '#374151', 'label' => 'Connexions'],
    'utilisateur'  => ['icone' => '👤', 'bg' => '#fce7f3', 'color' => '#9d174d', 'label' => 'Utilisateurs'],
];

$actionsLabels = [
    'creation' => 'Cree',
    'modification' => 'Modifie',
    'suppression' => 'Supprime',
    'envoi' => 'Envoye',
    'annulation' => 'Annule',
    'paiement_recu' => 'Paiement recu',
    'livraison_effectuee' => 'Livre',
    'resolution' => 'Resolu',
    'login' => 'Connexion',
    'entree' => 'Entree',
    'sortie' => 'Sortie',
    'ajustement' => 'Ajustement',
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
    <p class="page-subtitle">Toutes les activites du systeme — <?= number_format($totalEvents, 0, ',', ' ') ?> evenements</p>
  </div>

  <div class="card" style="margin-bottom:20px;padding:16px 20px;">
    <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
      <input type="text" name="search" value="<?= htmlspecialchars($filtreSearch, ENT_QUOTES, 'UTF-8') ?>"
             placeholder="Rechercher..." style="width:200px;"
             oninput="this.form.submit()">

      <select name="type" onchange="this.form.submit()">
        <option value="tout" <?= $filtreType === 'tout' ? 'selected' : '' ?>>
          Tous les types (<?= (int)$totalTous ?>)
        </option>
        <?php foreach ($typesConfig as $t => $cfg): ?>
        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8') ?>" <?= $filtreType === $t ? 'selected' : '' ?>>
          <?= $cfg['icone'] ?> <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)($counts[$t] ?? 0) ?>)
        </option>
        <?php endforeach; ?>
      </select>

      <input type="date" name="date" value="<?= htmlspecialchars($filtreDate, ENT_QUOTES, 'UTF-8') ?>"
             onchange="this.form.submit()">

      <?php if ($filtreType !== 'tout' || $filtreDate !== '' || $filtreSearch !== ''): ?>
      <a href="historique.php"
         style="color:#ef4444;font-size:13px;text-decoration:none;font-weight:500;">
        ✕ Effacer
      </a>
      <?php endif; ?>

      <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
        <?php foreach ($typesConfig as $t => $cfg): ?>
          <?php if (!empty($counts[$t])): ?>
          <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['type' => $t, 'page' => 1])), ENT_QUOTES, 'UTF-8') ?>"
             style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;
                    padding:3px 10px;border-radius:999px;font-size:11px;
                    font-weight:600;text-decoration:none;
                    <?= $filtreType === $t ? 'outline:2px solid ' . $cfg['color'] . ';' : '' ?>">
            <?= $cfg['icone'] ?> <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$counts[$t] ?>)
          </a>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </form>
  </div>

  <div class="card" style="padding:0;overflow:hidden;">
    <?php if (empty($events)): ?>
    <div style="text-align:center;padding:60px;color:var(--text-muted);">
      <div style="font-size:40px;margin-bottom:12px;">📭</div>
      <div style="font-size:16px;font-weight:500;">Aucun evenement trouve</div>
      <?php if ($filtreType !== 'tout' || $filtreDate !== '' || $filtreSearch !== ''): ?>
      <a href="historique.php" style="color:#6366f1;text-decoration:none;font-size:13px;">
        Effacer les filtres
      </a>
      <?php endif; ?>
    </div>
    <?php else: ?>
      <?php $datePrecedente = ''; foreach ($events as $e):
        $eventDateRaw = (string)($e['event_created_at'] ?? ($e[$histDateCol] ?? ''));
        $dateEvent = date('d/m/Y', strtotime($eventDateRaw));
        $heureEvent = date('H:i', strtotime($eventDateRaw));
        $eventType = (string)($e['event_type'] ?? 'utilisateur');
        $eventLabel = (string)($e['event_label'] ?? '');
        $eventDetail = (string)($e['event_detail'] ?? '');
        $eventUserNom = (string)($e['event_user_nom'] ?? 'Systeme');
        $eventRefUrl = (string)($e['event_ref_url'] ?? '');
        $cfg = $typesConfig[$eventType] ?? ['icone' => '📌', 'bg' => '#f3f4f6', 'color' => '#374151', 'label' => ucfirst($eventType)];
        $actionLabel = $actionsLabels[(string)($e['action'] ?? '')] ?? ucfirst((string)($e['action'] ?? 'action'));
      ?>
      <?php if ($dateEvent !== $datePrecedente): $datePrecedente = $dateEvent; ?>
      <div style="padding:8px 24px;background:var(--bg-page);
                  font-size:11px;font-weight:700;text-transform:uppercase;
                  letter-spacing:.8px;color:var(--text-muted);
                  border-top:1px solid var(--border);border-bottom:1px solid var(--border);">
        <?php
          $ts = strtotime($eventDateRaw);
          if (date('Y-m-d', $ts) === date('Y-m-d')) {
              echo "Aujourd'hui — $dateEvent";
          } elseif (date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) {
              echo "Hier — $dateEvent";
          } else {
              echo $dateEvent;
          }
        ?>
      </div>
      <?php endif; ?>

      <div style="display:flex;align-items:flex-start;gap:16px;
                  padding:14px 24px;border-bottom:1px solid var(--border);
                  transition:background .15s;"
           onmouseover="this.style.background='var(--bg-page)'"
           onmouseout="this.style.background=''">

        <div style="width:38px;height:38px;border-radius:10px;
                    background:<?= $cfg['bg'] ?>;flex-shrink:0;
                    display:flex;align-items:center;justify-content:center;font-size:16px;">
          <?= $cfg['icone'] ?>
        </div>

        <div style="flex:1;min-width:0;">
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <span style="font-size:14px;font-weight:600;color:var(--text-primary);">
              <?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span style="background:<?= $cfg['bg'] ?>;color:<?= $cfg['color'] ?>;
                         padding:2px 8px;border-radius:999px;font-size:10px;font-weight:600;">
              <?= $cfg['icone'] ?> <?= htmlspecialchars(ucfirst($eventType), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span style="background:var(--bg-page);color:var(--text-muted);
                         padding:2px 8px;border-radius:999px;font-size:10px;border:1px solid var(--border);">
              <?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?>
            </span>
          </div>
          <?php if ($eventDetail !== ''): ?>
          <div style="font-size:12px;color:var(--text-second);margin-top:3px;">
            <?= htmlspecialchars($eventDetail, ENT_QUOTES, 'UTF-8') ?>
          </div>
          <?php endif; ?>
          <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
            Par <?= htmlspecialchars($eventUserNom !== '' ? $eventUserNom : 'Systeme', ENT_QUOTES, 'UTF-8') ?>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;">
          <span style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($heureEvent, ENT_QUOTES, 'UTF-8') ?></span>
          <?php if ($eventRefUrl !== ''): ?>
          <a href="<?= htmlspecialchars($eventRefUrl, ENT_QUOTES, 'UTF-8') ?>"
             style="font-size:11px;color:#6366f1;text-decoration:none;font-weight:500;">
            Voir →
          </a>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:20px;flex-wrap:wrap;">
    <?php if ($page > 1): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1])), ENT_QUOTES, 'UTF-8') ?>"
         style="padding:8px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text-primary);background:var(--bg-card);">← Precedent</a>
    <?php endif; ?>
    <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i])), ENT_QUOTES, 'UTF-8') ?>"
         style="padding:8px 14px;border-radius:8px;text-decoration:none;<?= $i === $page ? 'background:#6366f1;color:#fff;border:1px solid #6366f1;' : 'border:1px solid var(--border);color:var(--text-primary);background:var(--bg-card);' ?>"><?= (int)$i ?></a>
    <?php endfor; ?>
    <?php if ($page < $totalPages): ?>
      <a href="?<?= htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1])), ENT_QUOTES, 'UTF-8') ?>"
         style="padding:8px 14px;border:1px solid var(--border);border-radius:8px;text-decoration:none;color:var(--text-primary);background:var(--bg-card);">Suivant →</a>
    <?php endif; ?>
    <span style="font-size:12px;color:var(--text-muted);margin-left:8px;">Page <?= (int)$page ?> / <?= (int)$totalPages ?> — <?= (int)$totalEvents ?> evenements</span>
  </div>
  <?php endif; ?>
</div>
</body>
</html>
