<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

/**
 * Récupère le dernier import SFTP depuis la table import_run
 * Filtre uniquement les imports avec source='SFTP' ou sans source (anciens imports)
 */

// Récupérer les derniers imports et filtrer pour trouver le dernier SFTP
$stmt = $pdo->prepare("
    SELECT id, ran_at, imported, skipped, ok, msg 
    FROM import_run 
    ORDER BY id DESC 
    LIMIT 50
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrer pour trouver le dernier avec source='SFTP' ou sans source (anciens imports SFTP)
$row = null;
foreach($rows as $r) {
    if (empty($r['msg'])) {
        // Ancien format sans JSON - probablement SFTP
        $row = $r;
        break;
    }
    $decoded = json_decode((string)$r['msg'], true);
    if (is_array($decoded)) {
        // Si source='SFTP' ou pas de source (ancien format SFTP)
        if (isset($decoded['source']) && $decoded['source'] === 'SFTP') {
            $row = $r;
            break;
        } elseif (!isset($decoded['source']) && strpos((string)$r['msg'], 'upload_compteur') !== false) {
            // Ancien format SFTP sans source explicite
            $row = $r;
            break;
        }
    } elseif (strpos((string)$r['msg'], 'upload_compteur') !== false) {
        // Format texte ancien SFTP
        $row = $r;
        break;
    }
}

if (!$row) { 
    echo json_encode(['has_run'=>false]); 
    exit; 
}

$summary = null;
if (!empty($row['msg'])) {
    $decoded = json_decode((string)$row['msg'], true);
    if (is_array($decoded)) {
        $summary = $decoded;
    } else {
        // Ancien format texte - convertir en format structuré
        $summary = ['source' => 'SFTP', 'raw' => $row['msg']];
    }
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
