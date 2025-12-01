<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

/**
 * Récupère le dernier import WEB_COMPTEUR depuis la table import_run
 * Similaire à last_import.php mais filtre par source='WEB_COMPTEUR'
 */

// Récupérer le dernier import avec source='WEB_COMPTEUR'
// On récupère les derniers imports et on filtre en PHP pour compatibilité avec toutes les versions MySQL
$stmt = $pdo->prepare("
    SELECT id, ran_at, imported, skipped, ok, msg 
    FROM import_run 
    ORDER BY id DESC 
    LIMIT 50
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrer pour trouver le dernier avec source='WEB_COMPTEUR'
$row = null;
foreach($rows as $r) {
    if (!empty($r['msg'])) {
        $decoded = json_decode((string)$r['msg'], true);
        if (is_array($decoded) && isset($decoded['source']) && $decoded['source'] === 'WEB_COMPTEUR') {
            $row = $r;
            break;
        }
    }
}

if (!$row) { 
    echo json_encode(['has_run'=>false]); 
    exit; 
}

$summary = null;
if (!empty($row['msg'])) {
    $decoded = json_decode((string)$row['msg'], true);
    if (is_array($decoded)) $summary = $decoded;
}
$recent = (time() - strtotime((string)$row['ran_at'])) < 180; // < 3 min

echo json_encode([
    'has_run'  => true,
    'id'       => (int)$row['id'],
    'ran_at'   => (string)$row['ran_at'],
    'imported' => (int)$row['imported'],
    'skipped'  => (int)$row['skipped'],
    'ok'       => (int)$row['ok'],
    'recent'   => $recent ? 1 : 0,
    'summary'  => $summary
]);

