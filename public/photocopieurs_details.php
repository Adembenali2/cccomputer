<?php
// /public/photocopieurs_details.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// Entrée
$macParam = strtoupper(trim($_GET['mac'] ?? ''));
$snParam  = trim($_GET['sn'] ?? '');

$useMac = false; $useSn = false;
if ($macParam !== '' && preg_match('/^[0-9A-F]{12}$/', $macParam))      $useMac = true;
elseif ($snParam !== '')                                                $useSn  = true;
else {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><p>Paramètre manquant ou invalide. Utilisez ?mac=001122AABBCC (12 hex) ou ?sn=SERIAL.</p>";
  exit;
}

// Lecture relevés
try {
  if ($useMac) {
    $stmt = $pdo->prepare("SELECT * FROM compteur_relevee WHERE mac_norm = :mac ORDER BY `Timestamp` DESC, id DESC");
    $stmt->execute([':mac' => $macParam]);
  } else {
    $stmt = $pdo->prepare("SELECT * FROM compteur_relevee WHERE SerialNumber = :sn ORDER BY `Timestamp` DESC, id DESC");
    $stmt->execute([':sn' => $snParam]);
  }
  $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('photocopieurs_details SQL error: '.$e->getMessage());
  $rows = [];
}

// En-tête
$latest     = $rows[0] ?? null;
$macDisplay = $latest['MacAddress']   ?? ($useMac ? $macParam : '—');
$snDisplay  = $latest['SerialNumber'] ?? ($useSn ? $snParam : '—');
$model      = $latest['Model']        ?? '—';
$name       = $latest['Nom']          ?? '—';
$status     = $latest['Status']       ?? '—';
$ipDisplay  = $latest['IpAddress']    ?? '—';

// Client attribué au photocopieur (via photocopieurs_clients)
$client = null;
try {
  if ($useMac) {
    $q = $pdo->prepare("
      SELECT c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
      FROM photocopieurs_clients pc
      LEFT JOIN clients c ON c.id = pc.id_client
      WHERE pc.mac_norm = :mac
      LIMIT 1
    ");
    $q->execute([':mac' => $macParam]);
  } else {
    $q = $pdo->prepare("
      SELECT c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
      FROM photocopieurs_clients pc
      LEFT JOIN clients c ON c.id = pc.id_client
      WHERE pc.SerialNumber = :sn
      LIMIT 1
    ");
    $q->execute([':sn' => $snParam]);
  }
  $client = $q->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
  error_log('photocopieurs_details client lookup error: '.$e->getMessage());
  $client = null;
}

// helper
function pctOrIntOrNull($v): ?int {
  if ($v === null || $v === '' || !is_numeric($v)) return null;
  return max(0, min(100, (int)$v));
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
  <link rel="stylesheet" href="/assets/css/photocopieurs_details.css" />
</head>
<body class="page-details">
  <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

  <div class="page-container">
    <div class="toolbar">
      <a href="/public/clients.php" class="back-link">← Retour</a>
      <a href="#" class="btn btn-primary" id="btn-espace-client">Espace client</a>
    </div>

    <div class="details-header">
      <div class="h1">Historique du photocopieur</div>
      <div class="meta">
        <span class="badge">Modèle: <?= h($model) ?></span>
        <span class="badge">Nom: <?= h($name) ?></span>
        <span class="badge">SN: <?= h($snDisplay) ?></span>
        <span class="badge">MAC: <?= h($macDisplay) ?></span>
        <span class="badge">IP: <?= h($ipDisplay) ?></span>
        <span class="badge">Statut: <?= h($status) ?></span>
      </div>

      <?php if ($client): ?>
        <div class="client-card">
          <div class="client-title">Client attribué</div>
          <div class="client-grid">
            <div>
              <div class="label">Raison sociale</div>
              <div class="value"><?= h($client['raison_sociale'] ?? '—') ?></div>
            </div>
            <div>
              <div class="label">Téléphone</div>
              <div class="value"><?= h($client['telephone1'] ?? '—') ?></div>
            </div>
            <div>
              <div class="label">Dirigeant</div>
              <div class="value">
                <?= h(trim(($client['nom_dirigeant'] ?? '').' '.($client['prenom_dirigeant'] ?? '')) ?: '—') ?>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

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
              <th>Modèle</th>
              <th>Statut</th>
              <th class="th-toner">Toner K</th>
              <th class="th-toner">Toner C</th>
              <th class="th-toner">Toner M</th>
              <th class="th-toner">Toner Y</th>
              <th class="td-num">Total BW</th>
              <th class="td-num">Total Color</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r):
              $ts   = $r['Timestamp'] ? date('Y-m-d H:i', strtotime($r['Timestamp'])) : '—';
              $mod  = $r['Model'] ?? '—';
              $st   = $r['Status'] ?? '—';

              $tk   = pctOrIntOrNull($r['TonerBlack']);
              $tc   = pctOrIntOrNull($r['TonerCyan']);
              $tm   = pctOrIntOrNull($r['TonerMagenta']);
              $ty   = pctOrIntOrNull($r['TonerYellow']);

              $totBW   = is_null($r['TotalBW'])    ? '—' : number_format((int)$r['TotalBW'], 0, ',', ' ');
              $totCol  = is_null($r['TotalColor']) ? '—' : number_format((int)$r['TotalColor'], 0, ',', ' ');
            ?>
              <tr>
                <td><?= h($ts) ?></td>
                <td><?= h($mod) ?></td>
                <td><?= h($st) ?></td>

                <!-- Barres de toner -->
                <td class="td-toner">
                  <div class="toner-bar k"><span style="width:<?= $tk!==null?$tk:0 ?>%"></span></div>
                  <em><?= $tk!==null ? $tk.'%' : '—' ?></em>
                </td>
                <td class="td-toner">
                  <div class="toner-bar c"><span style="width:<?= $tc!==null?$tc:0 ?>%"></span></div>
                  <em><?= $tc!==null ? $tc.'%' : '—' ?></em>
                </td>
                <td class="td-toner">
                  <div class="toner-bar m"><span style="width:<?= $tm!==null?$tm:0 ?>%"></span></div>
                  <em><?= $tm!==null ? $tm.'%' : '—' ?></em>
                </td>
                <td class="td-toner">
                  <div class="toner-bar y"><span style="width:<?= $ty!==null?$ty:0 ?>%"></span></div>
                  <em><?= $ty!==null ? $ty.'%' : '—' ?></em>
                </td>

                <td class="td-num"><?= h($totBW) ?></td>
                <td class="td-num"><?= h($totCol) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
