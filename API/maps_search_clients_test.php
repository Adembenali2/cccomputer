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
    // Requête SQL simple - rechercher dans plusieurs champs
    $searchTerm = '%' . $query . '%';
    $sql = "SELECT id, numero_client, raison_sociale, adresse, code_postal, ville, nom_dirigeant, prenom_dirigeant, telephone1, email FROM clients WHERE raison_sociale LIKE ? OR numero_client LIKE ? OR nom_dirigeant LIKE ? OR prenom_dirigeant LIKE ? ORDER BY raison_sociale ASC LIMIT " . (int)$limit;
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtrer en PHP pour les combinaisons nom+prenom et adresse complète
    if (!empty($clients)) {
        $filtered = [];
        $queryLower = mb_strtolower($query, 'UTF-8');
        
        foreach ($clients as $c) {
            $match = false;
            
            // Raison sociale
            if (stripos($c['raison_sociale'] ?? '', $query) !== false) $match = true;
            // Numéro client
            elseif (stripos($c['numero_client'] ?? '', $query) !== false) $match = true;
            // Nom dirigeant
            elseif (!empty($c['nom_dirigeant']) && stripos($c['nom_dirigeant'], $query) !== false) $match = true;
            // Prénom dirigeant
            elseif (!empty($c['prenom_dirigeant']) && stripos($c['prenom_dirigeant'], $query) !== false) $match = true;
            // Nom + Prénom
            elseif (!empty($c['nom_dirigeant']) && !empty($c['prenom_dirigeant'])) {
                $nomComplet = trim($c['nom_dirigeant'] . ' ' . $c['prenom_dirigeant']);
                $prenomNom = trim($c['prenom_dirigeant'] . ' ' . $c['nom_dirigeant']);
                if (stripos($nomComplet, $query) !== false || stripos($prenomNom, $query) !== false) $match = true;
            }
            // Adresse
            elseif (!empty($c['adresse']) && stripos($c['adresse'], $query) !== false) $match = true;
            // Ville
            elseif (!empty($c['ville']) && stripos($c['ville'], $query) !== false) $match = true;
            // Code postal
            elseif (!empty($c['code_postal']) && stripos($c['code_postal'], $query) !== false) $match = true;
            // Adresse complète
            elseif (!empty($c['adresse']) && !empty($c['code_postal']) && !empty($c['ville'])) {
                $adresseComplete = trim($c['adresse'] . ' ' . $c['code_postal'] . ' ' . $c['ville']);
                if (stripos($adresseComplete, $query) !== false) $match = true;
            }
            
            if ($match) {
                $filtered[] = $c;
                if (count($filtered) >= $limit) break;
            }
        }
        $clients = $filtered;
    }
    
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

