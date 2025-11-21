<?php
// API pour récupérer les premières livraisons (pour affichage par défaut)
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
    error_log('messagerie_get_first_livraisons.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$limit = min((int)($_GET['limit'] ?? 3), 10);

try {
    $sql = "
        SELECT 
            l.id,
            l.reference,
            l.objet,
            l.date_prevue,
            c.raison_sociale AS client_nom
        FROM livraisons l
        LEFT JOIN clients c ON c.id = l.id_client
        ORDER BY l.date_prevue DESC, l.id DESC
        LIMIT " . (int)$limit . "
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    
    $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($livraisons as $l) {
        $label = $l['reference'];
        if ($l['client_nom']) {
            $label .= ' - ' . $l['client_nom'];
        }
        if ($l['objet']) {
            $label .= ' (' . $l['objet'] . ')';
        }
        
        $results[] = [
            'id' => (int)$l['id'],
            'reference' => $l['reference'],
            'label' => $label
        ];
    }
    
    jsonResponse(['ok' => true, 'results' => $results]);
    
} catch (PDOException $e) {
    error_log('messagerie_get_first_livraisons.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_get_first_livraisons.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

