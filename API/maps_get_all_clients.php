<?php
// API pour récupérer tous les clients pour la page maps.php (affichage initial sur la carte)
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
    error_log('maps_get_all_clients.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation: ' . $e->getMessage()], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Vérifier que $pdo est bien défini
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('maps_get_all_clients.php: $pdo not defined or invalid');
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

$limit = min((int)($_GET['limit'] ?? 1000), 5000); // Par défaut 1000, max 5000

try {
    // Récupérer tous les clients avec leurs adresses
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
        WHERE adresse IS NOT NULL 
          AND adresse != ''
          AND code_postal IS NOT NULL 
          AND code_postal != ''
          AND ville IS NOT NULL 
          AND ville != ''
        ORDER BY raison_sociale ASC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    
    $execResult = $stmt->execute();
    if (!$execResult) {
        $errorInfo = $stmt->errorInfo();
        error_log('maps_get_all_clients.php execute error: ' . json_encode($errorInfo));
        jsonResponse(['ok' => false, 'error' => 'Erreur d\'exécution SQL'], 500);
    }
    
    $allClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formater les résultats
    $formatted = [];
    foreach ($allClients as $c) {
        // Construire l'adresse complète
        $adresseComplete = trim($c['adresse']);
        $codePostal = trim($c['code_postal']);
        $ville = trim($c['ville']);
        
        $address = trim(sprintf('%s %s %s', $adresseComplete, $codePostal, $ville));
        
        // Pour le géocodage, utiliser l'adresse de livraison si elle est différente et existe
        $addressForGeocode = $address;
        $livraisonIdentique = isset($c['livraison_identique']) ? (bool)$c['livraison_identique'] : false;
        if (!empty($c['adresse_livraison']) && !$livraisonIdentique) {
            $addressForGeocode = trim($c['adresse_livraison'] . ' ' . $codePostal . ' ' . $ville);
        }
        
        $nomDirigeant = trim(($c['prenom_dirigeant'] ?? '') . ' ' . ($c['nom_dirigeant'] ?? ''));
        $nomDirigeant = $nomDirigeant ?: null;
        
        $formatted[] = [
            'id' => (int)$c['id'],
            'code' => $c['numero_client'],
            'name' => $c['raison_sociale'],
            'nom_dirigeant' => $c['nom_dirigeant'] ?? null,
            'prenom_dirigeant' => $c['prenom_dirigeant'] ?? null,
            'dirigeant_complet' => $nomDirigeant,
            'address' => $address, // Adresse principale
            'address_geocode' => $addressForGeocode, // Adresse à utiliser pour le géocodage
            'adresse' => $c['adresse'],
            'code_postal' => $c['code_postal'],
            'ville' => $c['ville'],
            'adresse_livraison' => $c['adresse_livraison'] ?? null,
            'livraison_identique' => (bool)($c['livraison_identique'] ?? false),
            'telephone' => $c['telephone1'],
            'email' => $c['email'],
            'basePriority' => 1 // Par défaut, peut être basé sur livraisons en attente plus tard
        ];
    }
    
    jsonResponse(['ok' => true, 'clients' => $formatted, 'total' => count($formatted)]);
    
} catch (PDOException $e) {
    error_log('maps_get_all_clients.php SQL error: ' . $e->getMessage());
    error_log('maps_get_all_clients.php SQL error code: ' . ($e->getCode() ?? 'N/A'));
    error_log('maps_get_all_clients.php SQL error info: ' . json_encode($e->errorInfo ?? []));
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('maps_get_all_clients.php error: ' . $e->getMessage());
    error_log('maps_get_all_clients.php error file: ' . $e->getFile() . ':' . $e->getLine());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

