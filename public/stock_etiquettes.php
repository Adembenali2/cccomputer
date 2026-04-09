<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

$pdo = getPdo();
$stockId = (int)($_GET['stock_id'] ?? 0);
$all = isset($_GET['all']);
$idsCsv = trim((string)($_GET['ids'] ?? ''));

if ($idsCsv !== '') {
  $idList = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsCsv)), static fn(int $v): bool => $v > 0)));
  if ($idList) {
    $placeholders = implode(',', array_fill(0, count($idList), '?'));
    $stmt = $pdo->prepare("SELECT * FROM stock WHERE actif = 1 AND id IN ({$placeholders}) ORDER BY categorie, designation");
    $stmt->execute($idList);
    $articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } else {
    $articles = [];
  }
} elseif ($stockId > 0) {
  $stmt = $pdo->prepare("SELECT * FROM stock WHERE id = ? AND actif = 1");
  $stmt->execute([$stockId]);
  $articles = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($all) {
  $articles = $pdo->query("SELECT * FROM stock WHERE actif = 1 ORDER BY categorie, designation")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} else {
  $articles = [];
}

/**
 * Build QR data-uri with broad compatibility:
 * 1) Endroid v5 (PngWriter)
 * 2) Endroid legacy APIs
 * 3) Remote QR image fallback (server-side fetch)
 */
function buildQrDataUri(string $text): string
{
  if ($text === '') {
    return '';
  }

  // Endroid v5+
  if (class_exists('\\Endroid\\QrCode\\Writer\\PngWriter') && class_exists('\\Endroid\\QrCode\\QrCode')) {
    try {
      $writer = new \Endroid\QrCode\Writer\PngWriter();
      $qrCode = new \Endroid\QrCode\QrCode(data: $text, size: 120, margin: 2);
      $result = $writer->write($qrCode);
      if (method_exists($result, 'getDataUri')) {
        return (string)$result->getDataUri();
      }
    } catch (Throwable $e) {
      // Continue to fallback
    }
  }

  // Endroid legacy
  if (class_exists('\\Endroid\\QrCode\\QrCode')) {
    try {
      $legacy = new \Endroid\QrCode\QrCode($text);
      if (method_exists($legacy, 'writeDataUri')) {
        return (string)$legacy->writeDataUri();
      }
      if (method_exists($legacy, 'writeString')) {
        $png = (string)$legacy->writeString();
        if ($png !== '') {
          return 'data:image/png;base64,' . base64_encode($png);
        }
      }
    } catch (Throwable $e) {
      // Continue to fallback
    }
  }

  // Remote fallback (server side) - works with CSP because image becomes data URI
  $url = 'https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=' . rawurlencode($text);
  try {
    $ctx = stream_context_create([
      'http' => ['timeout' => 5],
      'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $bin = @file_get_contents($url, false, $ctx);
    if (is_string($bin) && $bin !== '') {
      return 'data:image/png;base64,' . base64_encode($bin);
    }
  } catch (Throwable $e) {
    // Ignore and return empty
  }

  return '';
}

foreach ($articles as &$article) {
  $qrText = (string)($article['qr_code'] ?? '');
  if ($qrText === '') {
    $qrText = (string)($article['reference'] ?? '');
  }
  $article['qr_data_uri'] = buildQrDataUri($qrText);
}
unset($article);
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
    <button type="button" id="btnPrint">Imprimer</button>
    <button type="button" id="btnClose">Fermer</button>
    <div style="line-height:30px;"><?= count($articles) ?> étiquettes — Format A4 (3×8)</div>
  </div>
  <div class="planche">
    <?php foreach ($articles as $a): ?>
    <div class="etiquette">
      <div class="qr-box" id="qr-<?= (int)$a['id'] ?>">
        <?php if (!empty($a['qr_data_uri'])): ?>
          <img src="<?= htmlspecialchars((string)$a['qr_data_uri'], ENT_QUOTES, 'UTF-8') ?>" alt="QR">
        <?php else: ?>
          <div style="font-size:9px;color:#ef4444;text-align:center;">QR indisponible</div>
        <?php endif; ?>
      </div>
      <div class="etiquette-info">
        <div class="etiquette-ref"><?= htmlspecialchars((string)$a['reference'], ENT_QUOTES, 'UTF-8') ?></div>
        <div class="etiquette-nom"><?= htmlspecialchars((string)mb_substr((string)$a['designation'], 0, 28), ENT_QUOTES, 'UTF-8') ?></div>
        <div class="etiquette-cat"><?= htmlspecialchars((string)ucfirst(str_replace('_', ' ', (string)$a['categorie'])), ENT_QUOTES, 'UTF-8') ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <script <?= csp_nonce() ?>>
  document.getElementById('btnPrint')?.addEventListener('click', function(){ window.print(); });
  document.getElementById('btnClose')?.addEventListener('click', function(){ window.close(); });
  </script>
</body>
</html>
