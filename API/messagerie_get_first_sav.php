<?php
// API pour récupérer les premiers SAV (pour affichage par défaut)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    error_log('messagerie_get_first_sav.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$limit = min((int)($_GET['limit'] ?? 3), 10);

try {
    $sql = "
        SELECT 
            s.id,
            s.reference,
            s.description,
            s.date_ouverture,
            c.raison_sociale AS client_nom
        FROM sav s
        LEFT JOIN clients c ON c.id = s.id_client
        ORDER BY s.date_ouverture DESC, s.id DESC
        LIMIT " . (int)$limit . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    $savs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($savs as $s) {
        $descriptionShort = mb_substr($s['description'] ?? '', 0, 50);
        if (mb_strlen($s['description'] ?? '') > 50) {
            $descriptionShort .= '...';
        }
        
        $label = $s['reference'];
        if ($s['client_nom']) {
            $label .= ' - ' . $s['client_nom'];
        }
        if ($descriptionShort) {
            $label .= ' (' . $descriptionShort . ')';
        }
        
        $results[] = [
            'id' => (int)$s['id'],
            'reference' => $s['reference'],
            'label' => $label
        ];
    }
    
    jsonResponse(['ok' => true, 'results' => $results]);
    
} catch (PDOException $e) {
    error_log('messagerie_get_first_sav.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_get_first_sav.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

