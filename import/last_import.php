<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/db.php';

/**
 * Récupère le dernier import SFTP depuis la table import_run
 * IMPORTANT: Récupère UNIQUEMENT la ligne "résumé" final (contient processed_files ou stage='summary')
 * et non les lignes intermédiaires (stage='process_file') qui ont imported=0
 */

// Récupérer les derniers imports et filtrer pour trouver le dernier résumé SFTP
$stmt = $pdo->prepare("
    SELECT id, ran_at, imported, skipped, ok, msg 
    FROM import_run 
    ORDER BY id DESC 
    LIMIT 100
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Filtrer pour trouver le dernier résumé SFTP (contient processed_files OU stage='summary')
$row = null;
$debugLog = [];

foreach($rows as $r) {
    $msg = (string)($r['msg'] ?? '');
    
    // Vérifier si c'est un résumé SFTP
    $isSummary = false;
    $decoded = null;
    
    if (!empty($msg)) {
        $decoded = json_decode($msg, true);
        if (is_array($decoded)) {
            // Vérifier si source='SFTP' ET si c'est un résumé
            if (isset($decoded['source']) && $decoded['source'] === 'SFTP') {
                // C'est un résumé si :
                // 1. contient 'processed_files' (résumé final)
                // 2. contient 'stage':'summary' (si présent)
                // 3. NE contient PAS 'stage':'process_file' (lignes intermédiaires)
                $hasProcessedFiles = isset($decoded['processed_files']);
                $hasStageSummary = isset($decoded['stage']) && $decoded['stage'] === 'summary';
                $hasStageProcessFile = isset($decoded['stage']) && $decoded['stage'] === 'process_file';
                
                if (($hasProcessedFiles || $hasStageSummary) && !$hasStageProcessFile) {
                    $isSummary = true;
                }
            }
        }
    }
    
    if ($isSummary) {
        $row = $r;
        $debugLog[] = [
            'action' => 'found_summary',
            'id' => (int)$r['id'],
            'ran_at' => $r['ran_at'],
            'has_processed_files' => isset($decoded['processed_files']),
            'stage' => $decoded['stage'] ?? null
        ];
        break;
    }
}

// Fallback: si aucun résumé trouvé, chercher le dernier SFTP (ancien format ou sans stage)
if (!$row) {
    foreach($rows as $r) {
        $msg = (string)($r['msg'] ?? '');
        
        if (empty($msg)) {
            // Ancien format sans JSON - probablement SFTP
            $row = $r;
            $debugLog[] = ['action' => 'fallback_no_msg', 'id' => (int)$r['id']];
            break;
        }
        
        $decoded = json_decode($msg, true);
        if (is_array($decoded)) {
            // Si source='SFTP' ou pas de source (ancien format SFTP)
            if (isset($decoded['source']) && $decoded['source'] === 'SFTP') {
                // Prendre seulement si ce n'est PAS un process_file
                if (!isset($decoded['stage']) || $decoded['stage'] !== 'process_file') {
                    $row = $r;
                    $debugLog[] = ['action' => 'fallback_sftp_no_stage', 'id' => (int)$r['id']];
                    break;
                }
            } elseif (!isset($decoded['source']) && strpos($msg, 'upload_compteur') !== false) {
                // Ancien format SFTP sans source explicite
                $row = $r;
                $debugLog[] = ['action' => 'fallback_old_format', 'id' => (int)$r['id']];
                break;
            }
        } elseif (strpos($msg, 'upload_compteur') !== false) {
            // Format texte ancien SFTP
            $row = $r;
            $debugLog[] = ['action' => 'fallback_text', 'id' => (int)$r['id']];
            break;
        }
    }
}

if (!$row) { 
    echo json_encode(['has_run'=>false, 'debug' => $debugLog]); 
    exit; 
}

$summary = null;
$inserted = null;
$updated = null;
$processedFiles = null;

if (!empty($row['msg'])) {
    $decoded = json_decode((string)$row['msg'], true);
    if (is_array($decoded)) {
        $summary = $decoded;
        // Extraire inserted/updated depuis le JSON si disponible
        $inserted = isset($decoded['inserted']) ? (int)$decoded['inserted'] : null;
        $updated = isset($decoded['updated']) ? (int)$decoded['updated'] : null;
        $processedFiles = isset($decoded['processed_files']) ? (int)$decoded['processed_files'] : null;
    } else {
        // Ancien format texte - convertir en format structuré
        $summary = ['source' => 'SFTP', 'raw' => $row['msg']];
    }
}

// Fallback sur colonnes imported/skipped si JSON n'a pas les valeurs
if ($inserted === null) {
    $inserted = (int)$row['imported'];
}

$recent = (time() - strtotime((string)$row['ran_at'])) < 180; // < 3 min

// Logs debug (non sensibles)
$debugInfo = [
    'selected_id' => (int)$row['id'],
    'ran_at' => (string)$row['ran_at'],
    'imported_column' => (int)$row['imported'],
    'skipped_column' => (int)$row['skipped'],
    'inserted_from_json' => $inserted,
    'updated_from_json' => $updated,
    'processed_files_from_json' => $processedFiles,
    'has_msg_json' => !empty($row['msg']) && is_array($summary),
    'search_log' => $debugLog
];

echo json_encode([
    'has_run'  => true,
    'id'       => (int)$row['id'],
    'ran_at'   => (string)$row['ran_at'],
    'imported' => $inserted, // Utilise inserted du JSON si disponible, sinon colonne imported
    'skipped'  => (int)$row['skipped'],
    'updated'  => $updated, // Nouveau champ depuis JSON
    'ok'       => (int)$row['ok'],
    'recent'   => $recent ? 1 : 0,
    'summary'  => $summary,
    'debug'    => $debugInfo // Logs debug temporaires
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
