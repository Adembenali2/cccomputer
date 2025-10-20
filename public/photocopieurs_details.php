<?php
// /public/photocopieurs_details.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Paramètres d'entrée: on privilégie mac (mac_norm = 12 hex sans ":")
// Optionnellement on peut aussi accepter ?sn=SERIALNUMBER en secours.
$macParam = strtoupper(trim($_GET['mac'] ?? ''));
$snParam  = trim($_GET['sn'] ?? '');

// Validation basique
$useMac = false;
$useSn  = false;

if ($macParam !== '' && preg_match('/^[0-9A-F]{12}$/', $macParam)) {
    $useMac = true;
} elseif ($snParam !== '') {
    // on laisse passer tout en échappant correctement avec PDO
    $useSn = true;
} else {
    http_response_code(400);
    echo "<!doctype html><meta charset='utf-8'><p>Paramètre manquant ou invalide. Utilisez ?mac=001122AABBCC (12 hex) ou ?sn=SERIAL.</p>";
    exit;
}

// Récupération des relevés
try {
    if ($useMac) {
        $stmt = $pdo->prepare("
            SELECT *
            FROM compteur_relevee
            WHERE mac_norm = :mac
            ORDER BY `Timestamp` DESC, id DESC
        ");
        $stmt->execute([':mac' => $macParam]);
    } else { // useSn
        $stmt = $pdo->prepare("
            SELECT *
            FROM compteur_relevee
            WHERE SerialNumber = :sn
            ORDER BY `Timestamp` DESC, id DESC
        ");
        $stmt->execute([':sn' => $snParam]);
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('photocopieurs_details SQL error: ' . $e->getMessage());
    $rows = [];
}

// entête: on prend la 1ère ligne (la plus récente) si dispo
$latest = $rows[0] ?? null;
$macDisplay = $latest['MacAddress'] ?? ($useMac ? $macParam : '—');
$snDisplay  = $latest['SerialNumber'] ?? ($useSn ? $snParam : '—');
$model      = $latest['Model'] ?? '—';
$name       = $latest['Nom'] ?? '—';
$status     = $latest['Status'] ?? '—';

// petite helper pour pourcentage/— 
function pctOrDash($v): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    $v = max(0, min(100, (int)$v));
    return (string)$v . '%';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="utf-8" />
  <meta http-equiv="X-UA-Compatible" content="IE=edge" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Détails photocopieur — Historique</title>
  <link rel="stylesheet" href="/assets/css/main.css" />
  <style>
    .details-header { display:grid; gap:.25rem; margin: 1rem 0; }
    .details-header .h1 { font-size: 1.35rem; font-weight: 700; }
    .meta { color: var(--text-secondary); }
    .badge { display:inline-block; padding:.15rem .5rem; border-radius: 999px; border:1px solid var(--border-color); font-size:.85rem; }
    .toolbar { display:flex; gap:.5rem; align-items:center; margin:.5rem 0 1rem; }
    table.details { width:100%; border-collapse: collapse; }
    table.details th, table.details td { padding:.5rem .6rem; border-bottom:1px solid var(--border-color); text-align:left; vertical-align: top; }
    table.details th { background: var(--bg-elevated); position: sticky; top:0; z-index: 1; }
    .td-num { text-align: right; font-variant-numeric: tabular-nums; white-space:nowrap; }
    .muted { color: var(--text-secondary); }
    .back-link { text-decoration:none; }
  </style>
</head>
<body class="page-details">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

  <div class="page-container">
    <div class="toolbar">
      <a href="/public/clients.php" class="back-link">← Retour</a>
    </div>

    <div class="details-header">
      <div class="h1">Historique du photocopieur</div>
      <div class="meta">
        <span class="badge">Modèle: <?= h($model) ?></span>
        <span class="badge">Nom: <?= h($name) ?></span>
        <span class="badge">SN: <?= h($snDisplay) ?></span>
        <span class="badge">MAC: <?= h($macDisplay) ?></span>
        <span class="badge">Statut: <?= h($status) ?></span>
      </div>
      <?php if (!$rows): ?>
        <div class="muted">Aucun relevé trouvé pour ce photocopieur.</div>
      <?php endif; ?>
    </div>

    <?php if ($rows): ?>
      <div class="table-wrapper">
        <table class="details">
          <thead>
            <tr>
              <th>Date</th>
              <th>IP</th>
              <th>Modèle</th>
              <th>Nom</th>
              <th>SN</th>
              <th>MAC</th>
              <th>Statut</th>
              <th>Toner K</th>
              <th>Toner C</th>
              <th>Toner M</th>
              <th>Toner Y</th>
              <th class="td-num">Total BW</th>
              <th class="td-num">Total Color</th>
              <th class="td-num">Total Pages</th>
              <th class="td-num">Printed BW</th>
              <th class="td-num">Printed Color</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $ts   = $r['Timestamp'] ? date('Y-m-d H:i', strtotime($r['Timestamp'])) : '—';
              $ip   = $r['IpAddress'] ?? '—';
              $mod  = $r['Model'] ?? '—';
              $nom  = $r['Nom'] ?? '—';
              $sn   = $r['SerialNumber'] ?? '—';
              $mac  = $r['MacAddress'] ?? '—';
              $st   = $r['Status'] ?? '—';
              $tk   = pctOrDash($r['TonerBlack']);
              $tc   = pctOrDash($r['TonerCyan']);
              $tm   = pctOrDash($r['TonerMagenta']);
              $ty   = pctOrDash($r['TonerYellow']);
              $totBW   = is_null($r['TotalBW'])    ? '—' : number_format((int)$r['TotalBW'], 0, ',', ' ');
              $totCol  = is_null($r['TotalColor']) ? '—' : number_format((int)$r['TotalColor'], 0, ',', ' ');
              $totPg   = is_null($r['TotalPages']) ? '—' : number_format((int)$r['TotalPages'], 0, ',', ' ');
              $prBW    = is_null($r['BWPrinted'])  ? '—' : number_format((int)$r['BWPrinted'], 0, ',', ' ');
              $prCol   = is_null($r['ColorPrinted']) ? '—' : number_format((int)$r['ColorPrinted'], 0, ',', ' ');
            ?>
              <tr>
                <td><?= h($ts) ?></td>
                <td><?= h($ip) ?></td>
                <td><?= h($mod) ?></td>
                <td><?= h($nom) ?></td>
                <td><?= h($sn) ?></td>
                <td><?= h($mac) ?></td>
                <td><?= h($st) ?></td>
                <td><?= h($tk) ?></td>
                <td><?= h($tc) ?></td>
                <td><?= h($tm) ?></td>
                <td><?= h($ty) ?></td>
                <td class="td-num"><?= h($totBW) ?></td>
                <td class="td-num"><?= h($totCol) ?></td>
                <td class="td-num"><?= h($totPg) ?></td>
                <td class="td-num"><?= h($prBW) ?></td>
                <td class="td-num"><?= h($prCol) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
