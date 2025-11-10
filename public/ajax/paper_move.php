
<?php
// /ajax/paper_move.php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok'=>0,'err'=>'Method not allowed']); exit;
}

$paperId  = (int)($_POST['paper_id'] ?? 0);
$qtyDelta = (int)($_POST['qty_delta'] ?? 0);  // négatif = sortie
$reason   = $_POST['reason'] ?? 'ajustement';
$reference= trim($_POST['reference'] ?? '');
$userId   = $_SESSION['user']['id'] ?? null;

if ($paperId<=0 || $qtyDelta===0 || !in_array($reason,['ajustement','achat','retour','correction'],true)) {
  echo json_encode(['ok'=>0,'err'=>'Paramètres invalides']); exit;
}

try {
  $pdo->beginTransaction();

  // lock le stock courant pour éviter la concurrence
  $lock = $pdo->prepare("SELECT COALESCE(SUM(qty_delta),0) AS cur FROM paper_moves WHERE paper_id=? FOR UPDATE");
  $lock->execute([$paperId]);
  $cur = (int)$lock->fetchColumn();

  if ($qtyDelta < 0 && $cur + $qtyDelta < 0) {
    $pdo->rollBack();
    echo json_encode(['ok'=>0,'err'=>'Stock insuffisant']); exit;
  }

  $ins = $pdo->prepare("INSERT INTO paper_moves(paper_id, qty_delta, reason, reference, user_id) VALUES (?,?,?,?,?)");
  $ins->execute([$paperId, $qtyDelta, $reason, ($reference?:null), $userId]);

  $pdo->commit();
  echo json_encode(['ok'=>1,'new_stock'=>$cur + $qtyDelta]);
} catch (PDOException $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('paper_move: '.$e->getMessage());
  echo json_encode(['ok'=>0,'err'=>'Erreur SQL']);
}
