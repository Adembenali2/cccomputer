<?php
// API pour rechercher des clients pour la page maps.php
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200) {
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
    error_log('maps_search_clients.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50); // Max 50 résultats

if (empty($query) || strlen($query) < 2) {
    jsonResponse(['ok' => true, 'clients' => []]);
}

try {
    // Recherche dans raison_sociale, numero_client, adresse, ville, code_postal
    $searchTerm = '%' . $query . '%';
    $sql = "
        SELECT 
            id,
            numero_client,
            raison_sociale,
            adresse,
            code_postal,
            ville,
            adresse_livraison,
            livraison_identique,
            nom_dirigeant,
            prenom_dirigeant,
            telephone1,
            email
        FROM clients
        WHERE 
            raison_sociale LIKE :q
            OR numero_client LIKE :q
            OR adresse LIKE :q
            OR ville LIKE :q
            OR code_postal LIKE :q
            OR CONCAT(adresse, ' ', code_postal, ' ', ville) LIKE :q
        ORDER BY raison_sociale ASC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':q', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les résultats
    $formatted = [];
    foreach ($clients as $c) {
        $address = trim($c['adresse'] . ' ' . $c['code_postal'] . ' ' . $c['ville']);
        $formatted[] = [
            'id' => (int)$c['id'],
            'code' => $c['numero_client'],
            'name' => $c['raison_sociale'],
            'address' => $address,
            'adresse' => $c['adresse'],
            'code_postal' => $c['code_postal'],
            'ville' => $c['ville'],
            'telephone' => $c['telephone1'],
            'email' => $c['email'],
            'basePriority' => 1 // Par défaut, peut être basé sur livraisons en attente plus tard
        ];
    }
    
    jsonResponse(['ok' => true, 'clients' => $formatted]);
    
} catch (PDOException $e) {
    error_log('maps_search_clients.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('maps_search_clients.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

