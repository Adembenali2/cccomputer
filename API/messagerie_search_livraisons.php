<?php
// API pour rechercher des livraisons dans la messagerie
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
    error_log('messagerie_search_livraisons.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 10), 20);

if (empty($query) || strlen($query) < 1) {
    jsonResponse(['ok' => true, 'results' => []]);
}

try {
    // Vérifier que la table livraisons existe
    $checkTable = $pdo->query("SHOW TABLES LIKE 'livraisons'");
    if ($checkTable->rowCount() === 0) {
        jsonResponse(['ok' => true, 'results' => []]); // Retourne vide si la table n'existe pas
    }
    
    $searchTerm = '%' . $query . '%';
    $limitInt = (int)$limit;
    
    // Requête simplifiée avec binding correct
    $sql = "
        SELECT 
            l.id,
            l.reference,
            l.objet,
            c.raison_sociale AS client_nom
        FROM livraisons l
        LEFT JOIN clients c ON c.id = l.id_client
        WHERE l.reference LIKE :q1
           OR l.objet LIKE :q2
           OR c.raison_sociale LIKE :q3
        ORDER BY l.date_prevue DESC, l.id DESC
        LIMIT {$limitInt}
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':q1', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':q2', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':q3', $searchTerm, PDO::PARAM_STR);
    $stmt->execute();
    
    $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $results = [];
    foreach ($livraisons as $l) {
        $results[] = [
            'id' => (int)$l['id'],
            'reference' => $l['reference'] ?? '',
            'label' => ($l['reference'] ?? '') . ' - ' . ($l['client_nom'] ?? 'N/A') . ' (' . ($l['objet'] ?? '') . ')'
        ];
    }
    
    jsonResponse(['ok' => true, 'results' => $results]);
    
} catch (PDOException $e) {
    error_log('messagerie_search_livraisons.php SQL error: ' . $e->getMessage() . ' | Code: ' . $e->getCode());
    error_log('messagerie_search_livraisons.php SQL: ' . ($sql ?? 'N/A'));
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('messagerie_search_livraisons.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $e->getMessage()], 500);
}

