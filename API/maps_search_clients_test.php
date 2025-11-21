<?php
// Version de test simplifiée pour déboguer
ob_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('html_errors', 0);

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $data, int $statusCode = 200): void {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Init error: ' . $e->getMessage()], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'PDO not defined'], 500);
}

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50);

if (empty($query)) {
    jsonResponse(['ok' => true, 'clients' => []]);
}

try {
    // Requête SQL ultra-simple
    $searchTerm = '%' . $query . '%';
    $sql = "SELECT id, numero_client, raison_sociale, adresse, code_postal, ville, nom_dirigeant, prenom_dirigeant, telephone1, email FROM clients WHERE raison_sociale LIKE ? ORDER BY raison_sociale ASC LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = [];
    foreach ($clients as $c) {
        $address = trim(($c['adresse'] ?? '') . ' ' . ($c['code_postal'] ?? '') . ' ' . ($c['ville'] ?? ''));
        $nomDirigeant = trim(($c['prenom_dirigeant'] ?? '') . ' ' . ($c['nom_dirigeant'] ?? ''));
        
        $formatted[] = [
            'id' => (int)$c['id'],
            'code' => $c['numero_client'] ?? '',
            'name' => $c['raison_sociale'] ?? '',
            'nom_dirigeant' => $c['nom_dirigeant'] ?? null,
            'prenom_dirigeant' => $c['prenom_dirigeant'] ?? null,
            'dirigeant_complet' => $nomDirigeant ?: null,
            'address' => $address,
            'adresse' => $c['adresse'] ?? '',
            'code_postal' => $c['code_postal'] ?? '',
            'ville' => $c['ville'] ?? '',
            'telephone' => $c['telephone1'] ?? '',
            'email' => $c['email'] ?? ''
        ];
    }
    
    jsonResponse(['ok' => true, 'clients' => $formatted]);
    
} catch (PDOException $e) {
    jsonResponse(['ok' => false, 'error' => 'SQL Error: ' . $e->getMessage(), 'code' => $e->getCode()], 500);
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => 'Error: ' . $e->getMessage()], 500);
}

