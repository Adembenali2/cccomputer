<?php
// /public/photocopieurs_details.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

function h(?string $s): string { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

/** Normalise: "aa-bb:cc dd.ee ff" -> "AABBCCDDEEFF" (mac_norm) et "AA:BB:CC:DD:EE:FF" (MacAddress) */
function normalizeMac(?string $mac): array {
  $raw = strtoupper(trim((string)$mac));
  $hex = preg_replace('~[^0-9A-F]~', '', $raw);
  if (strlen($hex) !== 12) return ['norm' => null, 'colon' => null];
  $norm  = $hex; // AABBCCDDEEFF
  $pairs = implode(':', str_split($hex, 2)); // AA:BB:CC:DD:EE:FF
  return ['norm' => $norm, 'colon' => $pairs];
}

/* ---------- Entrée ---------- */
$macParam = strtoupper(trim($_GET['mac'] ?? ''));
$snParam  = trim($_GET['sn'] ?? '');

$useMac = false; $useSn = false;
if ($macParam !== '' && preg_match('/^[0-9A-F]{12}$/', $macParam)) $useMac = true;
elseif ($snParam !== '')                                           $useSn  = true;
else {
  http_response_code(400);
  echo "<!doctype html><meta charset='utf-8'><p>Paramètre manquant ou invalide. Utilisez ?mac=001122AABBCC (12 hex) ou ?sn=SERIAL.</p>";
  exit;
}

/* ---------- Action: associer un client ---------- */
$flash = ['type'=>null,'msg'=>null];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'attach_client') {
    $clientId = (int)($_POST['id_client'] ?? 0);
    $snPost   = trim($_POST['sn'] ?? '');
    $macPost  = trim($_POST['mac'] ?? '');

    $mac = normalizeMac($macPost);
    $macNorm = $mac['norm'];      // AABBCCDDEEFF
    $macColon = $mac['colon'];    // AA:BB:CC:DD:EE:FF

    if ($clientId <= 0 || !$macNorm) {
        $flash = ['type'=>'error','msg'=>"Client ou MAC invalide."];
    } else {
        try {
            // IMPORTANT: nécessite un index unique sur photocopieurs_clients.mac_norm
            //   ALTER TABLE photocopieurs_clients ADD UNIQUE KEY uq_pc_mac (mac_norm);
            $sql = "
              INSERT INTO photocopieurs_clients (mac_norm, MacAddress, SerialNumber, id_client)
              VALUES (:mac_norm, :mac_addr, :sn, :id_client)
              ON DUPLICATE KEY UPDATE
                id_client   = VALUES(id_client),
                SerialNumber= VALUES(SerialNumber),
                MacAddress  = VALUES(MacAddress)
            ";
            $pdo->prepare($sql)->execute([
                ':mac_norm'  => $macNorm,
                ':mac_addr'  => $macColon,
                ':sn'        => ($snPost !== '' ? $snPost : null),
                ':id_client' => $clientId
            ]);

            // Recharge la page (nouvel état: attribué)
            header('Location: '.$_SERVER['REQUEST_URI']);
            exit;
        } catch (PDOException $e) {
            error_log('attach_client error: '.$e->getMessage());
            $flash = ['type'=>'error','msg'=>"Erreur: impossible d'associer le client."];
        }
    }
}

/* ---------- Lecture relevés ---------- */
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

/* ---------- En-tête ---------- */
$latest     = $rows[0] ?? null;
$macDisplay = $latest['MacAddress']   ?? ($useMac ? implode(':', str_split($macParam,2)) : '—'); // joli avec :
$snDisplay  = $latest['SerialNumber'] ?? ($useSn ? $snParam : '—');
$model      = $latest['Model']        ?? '—';
$name       = $latest['Nom']          ?? '—';
$status     = $latest['Status']       ?? '—';
$ipDisplay  = $latest['IpAddress']    ?? '—';

/* ---------- Client attribué (si existe) ---------- */
$client = null;
try {
  if ($useMac) {
    $q = $pdo->prepare("
      SELECT c.id, c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
      FROM photocopieurs_clients pc
      LEFT JOIN clients c ON c.id = pc.id_client
      WHERE pc.mac_norm = :mac
      LIMIT 1
    ");
    $q->execute([':mac' => $macParam]);
  } else {
    $q = $pdo->prepare("
      SELECT c.id, c.raison_sociale, c.telephone1, c.nom_dirigeant, c.prenom_dirigeant
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

/* ---------- Liste clients (pour la popup) ---------- */
$clientsList = [];
try {
  $clientsList = $pdo->query("
    SELECT id, numero_client, raison_sociale,
           COALESCE(nom_dirigeant,'') AS nom_dirigeant,
           COALESCE(prenom_dirigeant,'') AS prenom_dirigeant,
           telephone1
    FROM clients
    ORDER BY raison_sociale ASC
  ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
  error_log('clients list error: '.$e->getMessage());
}

/* ---------- Helpers ---------- */
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

      <?php if ($client && ($client['id'] ?? null)): ?>
        <a href="/public/client_fiche.php?id=<?= (int)$client['id'] ?>" class="btn btn-primary" id="btn-espace-client">Espace client</a>
      <?php else: ?>
        <!-- Pas encore attribué → ouvre le formulaire d’attribution -->
        <button type="button" class="btn btn-primary" id="btn-espace-client">Espace client</button>
      <?php endif; ?>
    </div>

    <?php if ($flash['type']): ?>
      <div class="flash <?= $flash['type']==='error'?'flash-error':'flash-success' ?>" style="margin-bottom:.75rem;">
        <?= h($flash['msg']) ?>
      </div>
    <?php endif; ?>

    <div class="details-header">
      <div class="h1">Historique du photocopieur</div>

      <div class="meta">
        <span class="badge">Modèle: <?= h($model) ?></span>
        <span class="badge">Nom: <?= h($name) ?></span>
        <span class="badge">SN: <?= h($snDisplay) ?></span>
        <span class="badge">MAC: <?= h($macDisplay) ?></span>
        <span class="badge">IP: <?= h($ipDisplay) ?></span>
        <span class="badge">Statut: <?= h($status) ?></span>

        <?php if ($client): ?>
          <span class="badge">Client: <?= h($client['raison_sociale'] ?? '—') ?></span>
          <span class="badge">Tél: <?= h($client['telephone1'] ?? '—') ?></span>
          <span class="badge">Dirigeant: <?= h(trim(($client['nom_dirigeant'] ?? '').' '.($client['prenom_dirigeant'] ?? '')) ?: '—') ?></span>
        <?php endif; ?>
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
                <td class="td-toner"><div class="toner-bar k"><span style="width:<?= $tk!==null?$tk:0 ?>%"></span></div><em><?= $tk!==null ? $tk.'%' : '—' ?></em></td>
                <td class="td-toner"><div class="toner-bar c"><span style="width:<?= $tc!==null?$tc:0 ?>%"></span></div><em><?= $tc!==null ? $tc.'%' : '—' ?></em></td>
                <td class="td-toner"><div class="toner-bar m"><span style="width:<?= $tm!==null?$tm:0 ?>%"></span></div><em><?= $tm!==null ? $tm.'%' : '—' ?></em></td>
                <td class="td-toner"><div class="toner-bar y"><span style="width:<?= $ty!==null?$ty:0 ?>%"></span></div><em><?= $ty!==null ? $ty.'%' : '—' ?></em></td>
                <td class="td-num"><?= h($totBW) ?></td>
                <td class="td-num"><?= h($totCol) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== Popup d’attribution client ===== -->
  <div id="attachOverlay" class="popup-overlay"></div>
  <div id="attachModal" class="support-popup" style="max-width:640px;">
    <h3 style="margin-top:0;">Attribuer ce photocopieur à un client</h3>

    <form method="post" class="standard-form" action="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="action" value="attach_client">
      <input type="hidden" name="sn"  value="<?= h($snDisplay) ?>">
      <!-- On envoie la MAC affichée; le PHP la normalise quoi qu'il arrive -->
      <input type="hidden" name="mac" value="<?= h($macDisplay) ?>">

      <!-- Recherche instantanée -->
      <div>
        <label for="clientSearch">Rechercher un client</label>
        <input type="text" id="clientSearch" placeholder="Tapez raison sociale, N° client, dirigeant, téléphone…">
        <small class="muted">Filtre instantané — Entrée pour sélectionner le premier résultat.</small>
      </div>

      <div>
        <label for="clientSelect">Résultats</label>
        <select name="id_client" id="clientSelect" size="8" required
                style="width:100%; max-height:280px; overflow:auto;">
          <?php foreach ($clientsList as $c): 
            $label = sprintf('%s (%s) — %s %s — %s',
              $c['raison_sociale'],
              $c['numero_client'],
              trim($c['nom_dirigeant'].' '.$c['prenom_dirigeant']),
              '',
              $c['telephone1']
            );
          ?>
            <option value="<?= (int)$c['id'] ?>"
                    data-search="<?= h(strtolower(
                      $c['raison_sociale'].' '.$c['numero_client'].' '.
                      $c['nom_dirigeant'].' '.$c['prenom_dirigeant'].' '.$c['telephone1']
                    )) ?>">
              <?= h($label) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div id="noMatch" class="muted" style="display:none; margin-top:.25rem;">Aucun résultat…</div>
      </div>

      <div style="display:flex; gap:.5rem; justify-content:flex-end;">
        <button type="button" id="btnAttachCancel" class="btn">Annuler</button>
        <button type="submit" class="btn btn-primary">Associer</button>
      </div>
    </form>
  </div>

  <script>
    (function(){
      const btn   = document.getElementById('btn-espace-client');
      const modal = document.getElementById('attachModal');
      const ovl   = document.getElementById('attachOverlay');
      const cancel= document.getElementById('btnAttachCancel');

      // Ouverture uniquement si c'est un <button> (pas de client lié)
      if (btn && btn.tagName.toLowerCase() === 'button') {
        btn.addEventListener('click', openModal);
      }

      function openModal(){ ovl.classList.add('active'); modal.classList.add('active'); document.body.classList.add('modal-open'); }
      function closeModal(){ ovl.classList.remove('active'); modal.classList.remove('active'); document.body.classList.remove('modal-open'); }

      ovl && ovl.addEventListener('click', closeModal);
      cancel && cancel.addEventListener('click', closeModal);

      /* ---- Recherche instantanée ---- */
      const q = document.getElementById('clientSearch');
      const sel = document.getElementById('clientSelect');
      const noMatch = document.getElementById('noMatch');

      if (q && sel) {
        const opts = Array.from(sel.options);

        function filter() {
          const v = q.value.trim().toLowerCase();
          let shown = 0;
          opts.forEach(o => {
            const ok = !v || (o.dataset.search || o.textContent.toLowerCase()).includes(v);
            o.hidden = !ok;
            if (ok) shown++;
          });

          // Sélection automatique du premier visible
          const firstVisible = opts.find(o => !o.hidden);
          sel.value = firstVisible ? firstVisible.value : '';

          noMatch.style.display = shown ? 'none' : 'block';
        }

        q.addEventListener('input', filter);
        // Enter => sélectionne le premier visible
        q.addEventListener('keydown', (e) => {
          if (e.key === 'Enter') {
            e.preventDefault();
            const first = opts.find(o => !o.hidden);
            if (first) sel.value = first.value;
          }
        });

        // Navigation clavier
        q.addEventListener('keydown', (e) => { if (e.key === 'ArrowDown') { e.preventDefault(); sel.focus(); } });
        sel.addEventListener('keydown', (e) => {
          if (e.key === 'ArrowUp' && sel.selectedIndex === 0) { e.preventDefault(); q.focus(); }
        });

        filter();
      }
    })();
  </script>
</body>
</html>
