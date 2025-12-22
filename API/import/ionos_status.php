<?php
/**
 * API/import/ionos_status.php
 * STUB: Import removed, to be rebuilt
 * 
 * This endpoint previously handled IONOS import status.
 * All import functionality has been removed and is to be rebuilt.
 */

// Forcer les headers JSON dès le début (avant toute sortie)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

// Nettoyer toute sortie bufferisée
while (ob_get_level() > 0) {
    ob_end_clean();
}

http_response_code(501);
echo json_encode([
    'ok' => false,
    'code' => 'IMPORT_REMOVED',
    'error' => 'Import removed, to be rebuilt',
    'message' => 'This import endpoint has been removed and is pending reconstruction.',
    'server_time' => date('Y-m-d H:i:s')
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT);
exit;

