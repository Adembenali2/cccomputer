<?php
// /public/clients.php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

// ------------------------------------------------------------------
// Récup : un seul relevé (le dernier) par photocopieur (mac_norm)
// avec rattachement éventuel au client (sinon “— sans client —”)
// ------------------------------------------------------------------
$sql = "SELECT *
        FROM v_photocopieurs_clients_last
        ORDER BY
          COALESCE(raison_sociale, '— sans client —') ASC,
          COALESCE(SerialNumber, mac_norm) ASC";

try {
    $stmt = $pdo->query($sql);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('clients.php SQL error: ' . $e->getMessage());
    $rows = [];
}

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }
function pctOrDash($v): string {
    if ($v === null || $v === '' || !is_numeric($v)) return '—';
    $v = max(0, min(100, (int)$v));
    return (string)$v . '%';
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Clients & Photocopieurs - CCComputer</title>

    <!-- Feuilles de styles -->
    <link rel="stylesheet" href="/assets/css/main.css" />
    <link rel="stylesheet" href="/assets/css/clients.css" />
</head>
<body class="page-clients">
    <?php require_once __DIR__ . '/../source/templates/header.php'; ?>

    <div class="page-container">
        <div class="page-header">
            <h2 class="page-title">Photocopieurs par client (dernier relevé)</h2>
        </div>

        <div class="filters-row" style="margin-bottom:1rem; display:flex; gap:0.75rem; align-items:center;">
            <input type="text" id="q" placeholder="Filtrer (client, modèle, SN, MAC)…"
                   style="flex:1; padding:0.55rem 0.75rem; border:1px solid var(--border-color); border-radius: var(--radius-md); background:var(--bg-primary); color:var(--text-primary);">
            <button id="clearQ" class="btn" style="padding:0.55rem 0.9rem; border:1px solid var(--border-color); background:var(--bg-primary); border-radius:var(--radius-md); cursor:pointer;">
                Effacer
            </button>
        </div>

        <div class="table-wrapper">
            <table class="tbl-photocopieurs" id="tbl">
                <thead>
                    <tr>
                        <th style="min-width:220px;">Client</th>
                        <th>Dirigeant</th>
                        <th>Téléphone</th>
                        <th style="min-width:280px;">Photocopieur</th>
                        <th style="min-width:240px;">Toners</th>
                        <th>Total BW</th>
                        <th>Total Color</th>
                        <th>Dernier relevé</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $raison  = $r['raison_sociale'] ?: '— sans client —';
                    $numero  = $r['numero_client']   ?: '';
                    $dirNom  = trim(($r['nom_dirigeant'] ?? '').' '.($r['prenom_dirigeant'] ?? ''));
                    $tel     = $r['telephone1'] ?: '';
                    $modele  = $r['Model'] ?: '';
                    $sn      = $r['SerialNumber'] ?: '';
                    $mac     = $r['MacAddress'] ?: ($r['mac_norm'] ?? '');
                    $nom     = $r['Nom'] ?: '';
                    $lastTs  = $r['last_ts'] ? date('Y-m-d H:i', strtotime($r['last_ts'])) : '—';
                    $totBW   = is_null($r['TotalBW'])    ? '—' : (int)$r['TotalBW'];
                    $totCol  = is_null($r['TotalColor']) ? '—' : (int)$r['TotalColor'];

                    $tk = $r['TonerBlack'];   $tk = is_null($tk) ? null : max(0, min(100, (int)$tk));
                    $tc = $r['TonerCyan'];    $tc = is_null($tc) ? null : max(0, min(100, (int)$tc));
                    $tm = $r['TonerMagenta']; $tm = is_null($tm) ? null : max(0, min(100, (int)$tm));
                    $ty = $r['TonerYellow'];  $ty = is_null($ty) ? null : max(0, min(100, (int)$ty));

                    $searchText = strtolower(
                        ($r['raison_sociale'] ?? '') . ' ' .
                        ($r['numero_client'] ?? '') . ' ' .
                        $dirNom . ' ' . $tel . ' ' .
                        $modele . ' ' . $sn . ' ' . $mac
                    );
                ?>
                    <tr data-search="<?= h($searchText) ?>">
                        <td>
                            <div class="client-cell">
                                <div class="client-raison"><?= h($raison) ?></div>
                                <?php if ($numero): ?>
                                    <div class="client-num"><?= h($numero) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td><?= h($dirNom ?: '—') ?></td>
                        <td><?= h($tel ?: '—') ?></td>
                        <td>
                            <div class="machine-cell">
                                <div class="machine-line"><strong><?= h($modele ?: '—') ?></strong></div>
                                <div class="machine-sub">
                                    SN: <?= h($sn ?: '—') ?> · MAC: <?= h($mac ?: '—') ?>
                                </div>
                                <?php if ($nom): ?>
                                <div class="machine-sub">Nom: <?= h($nom) ?></div>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <div class="toners">
                                <div class="toner t-k" title="Black: <?= pctOrDash($tk) ?>">
                                    <span style="width:<?= ($tk!==null?$tk:0) ?>%"></span>
                                    <em><?= pctOrDash($tk) ?></em>
                                </div>
                                <div class="toner t-c" title="Cyan: <?= pctOrDash($tc) ?>">
                                    <span style="width:<?= ($tc!==null?$tc:0) ?>%"></span>
                                    <em><?= pctOrDash($tc) ?></em>
                                </div>
                                <div class="toner t-m" title="Magenta: <?= pctOrDash($tm) ?>">
                                    <span style="width:<?= ($tm!==null?$tm:0) ?>%"></span>
                                    <em><?= pctOrDash($tm) ?></em>
                                </div>
                                <div class="toner t-y" title="Yellow: <?= pctOrDash($ty) ?>">
                                    <span style="width:<?= ($ty!==null?$ty:0) ?>%"></span>
                                    <em><?= pctOrDash($ty) ?></em>
                                </div>
                            </div>
                        </td>
                        <td class="td-metric"><?= h((string)$totBW) ?></td>
                        <td class="td-metric"><?= h((string)$totCol) ?></td>
                        <td class="td-date"><?= h($lastTs) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <?php if (!$rows): ?>
                <div style="padding:1rem; color:var(--text-secondary);">Aucune donnée à afficher.</div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Petit filtre client-side
    (function() {
      const q = document.getElementById('q');
      const clear = document.getElementById('clearQ');
      const rows = Array.from(document.querySelectorAll('#tbl tbody tr'));

      function applyFilter() {
        const val = (q.value || '').trim().toLowerCase();
        rows.forEach(tr => {
          const hay = tr.getAttribute('data-search') || '';
          tr.style.display = hay.includes(val) ? '' : 'none';
        });
      }
      q && q.addEventListener('input', applyFilter);
      clear && clear.addEventListener('click', () => { q.value = ''; applyFilter(); });
    })();
    </script>
</body>
</html>
