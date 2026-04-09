<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();
$stockId = (int)($_GET['stock_id'] ?? 0);
$all = isset($_GET['all']);

if ($stockId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ? AND actif = 1");
  $stmt->execute([$stockId]);
  $articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($all) {
  $articles = $pdo->query("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
  $articles = [];
}
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Étiquettes stock</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 12px; }
    .planche { display:grid; grid-template-columns:repeat(3,1fr); gap:4px; max-width:800px; margin:0 auto; }
    .etiquette { border:1px solid #ccc; border-radius:4px; padding:8px; display:flex; align-items:center; gap:8px; height:88px; break-inside:avoid; }
    .qr-box { width:70px; height:70px; flex-shrink:0; }
    .qr-box canvas, .qr-box img { width:70px!important; height:70px!important; }
    .etiquette-ref { font-size:10px; font-family:monospace; font-weight:700; color:#111; }
    .etiquette-nom { font-size:9px; color:#333; margin-top:2px; }
    .etiquette-cat { font-size:8px; color:#6b7280; margin-top:2px; text-transform:uppercase; }
    @media print { .no-print { display:none!important; } body { margin:0; padding:0; } .planche { gap:2px; } .etiquette { border:1px solid #000; } }
  </style>
</head>
<body>
  <div class="no-print" style="display:flex;gap:8px;justify-content:center;margin-bottom:10px;">
    <button type="button" onclick="window.print()">Imprimer</button>
    <button type="button" onclick="window.close()">Fermer</button>
    <div style="line-height:30px;"><?= count($articles) ?> étiquettes — Format A4 (3×8)</div>
  </div>
  <div class="planche">
    <?php foreach ($articles as $a): ?>
    <div class="etiquette">
      <div class="qr-box" id="qr-<?= (int)$a['id'] ?>" data-code="<?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?>"></div>
      <div class="etiquette-info">
        <div class="etiquette-ref"><?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="etiquette-nom"><?= htmlspecialchars((string)mb_substr((string)$a['designation'], 0, 28), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="etiquette-cat"><?= htmlspecialchars((string)ucfirst(str_replace('_', ' ', (string)$a['categorie'])), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
  <script>
  document.querySelectorAll('.qr-box').forEach(function(el) {
    new QRCode(el, {
      text: el.dataset.code,
      width: 70, height: 70,
      colorDark: "#000000",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.M
    });
  });
  </script>
</body>
</html>
