<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helpers.php';

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo 'Accès refusé';
    exit;
}

$pdo = getPdo();

$stockId = (int)($_GET['stock_id'] ?? 0);
$categorie = trim((string)($_GET['categorie'] ?? ''));
$all = (int)($_GET['all'] ?? 0) === 1;

$where = [];
$params = [];
if ($stockId > 0) {
    $where[] = 'id = :id';
    $params[':id'] = $stockId;
} elseif ($categorie !== '') {
    $where[] = 'categorie = :categorie';
    $params[':categorie'] = $categorie;
} elseif (!$all) {
    $where[] = '1 = 0';
}

$sql = "
SELECT id, reference, designation, categorie, unite, contenance
FROM stock
" . (!empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '') . "
ORDER BY designation ASC, id ASC
LIMIT 2000
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

function catBadgeStyle(string $cat): string {
    $map = [
        'papier' => 'background:#dbeafe;color:#1e3a8a;',
        'toner_noir' => 'background:#111827;color:#fff;',
        'toner_cyan' => 'background:#0891b2;color:#fff;',
        'toner_magenta' => 'background:#db2777;color:#fff;',
        'toner_jaune' => 'background:#fde047;color:#111827;',
        'pc' => 'background:#4f46e5;color:#fff;',
        'ecran_lcd' => 'background:#7c3aed;color:#fff;',
        'imprimante' => 'background:#166534;color:#fff;',
        'piece_detachee' => 'background:#ea580c;color:#fff;',
        'consommable' => 'background:#0f766e;color:#fff;',
        'autre' => 'background:#6b7280;color:#fff;',
    ];
    return $map[$cat] ?? 'background:#6b7280;color:#fff;';
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Étiquettes stock</title>
  <style>
    body{font-family:Arial,sans-serif;margin:12px}
    .no-print{margin-bottom:12px}
    .planche{display:grid;grid-template-columns:repeat(3,1fr);gap:4px}
    .etiquette{border:1px solid #ccc;padding:8px;height:90px;display:flex;align-items:center;gap:8px;overflow:hidden}
    .qr-placeholder{width:70px;height:70px;display:flex;align-items:center;justify-content:center}
    .right{min-width:0}
    .ref{font-weight:700;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .des{font-size:10px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .cat{display:inline-block;border-radius:999px;padding:2px 6px;font-size:9px;margin-top:3px}
    .meta{font-size:9px;color:#374151;margin-top:3px}
    @media print {
      .no-print { display: none !important; }
      body { margin: 0; padding: 0; }
      .etiquette { page-break-inside: avoid; border: 1px solid #000; }
      .planche { display: grid; grid-template-columns: repeat(3, 1fr); gap: 2px; }
    }
  </style>
</head>
<body>
  <div class="no-print">
    <h2>Prévisualisation - Étiquettes QR Code</h2>
    <div>Produit: <?= count($items) ?> article(s)</div>
    <div>Code-barres: Référence stock</div>
    <div>Format: Planche A4 - 24 étiquettes - 3 colonnes × 8 lignes</div>
    <button type="button" onclick="window.print()">Imprimer</button>
  </div>

  <div class="planche">
    <?php foreach ($items as $it): ?>
      <div class="etiquette">
        <div class="qr-placeholder" id="qr-<?= (int)$it['id'] ?>" data-code="<?= h((string)$it['reference']) ?>"></div>
        <div class="right">
          <div class="ref"><?= h((string)$it['reference']) ?></div>
          <div class="des" title="<?= h((string)$it['designation']) ?>"><?= h(mb_substr((string)$it['designation'], 0, 25)) ?></div>
          <span class="cat" style="<?= catBadgeStyle((string)$it['categorie']) ?>"><?= h((string)$it['categorie']) ?></span>
          <?php if ((string)$it['categorie'] === 'papier'): ?>
            <div class="meta"><?= h((string)($it['unite'] ?? '')) ?><?= !empty($it['contenance']) ? ' - '.(int)$it['contenance'].' feuilles' : '' ?></div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script nonce="<?= csp_nonce() ?>">
  document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.qr-placeholder').forEach(function(el) {
      new QRCode(el, {
        text: el.dataset.code,
        width: 70,
        height: 70,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.M
      });
    });
  });
  </script>
</body>
</html>

