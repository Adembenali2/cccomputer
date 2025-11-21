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
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation: ' . $e->getMessage()], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Vérifier que $pdo est bien défini
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('maps_search_clients.php: $pdo not defined or invalid');
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 20), 50); // Max 50 résultats

if (empty($query) || strlen($query) < 1) {
    jsonResponse(['ok' => true, 'clients' => []]);
}

// Vérifier que la table clients existe
try {
    $checkTable = $pdo->query("SHOW TABLES LIKE 'clients'");
    if ($checkTable->rowCount() === 0) {
        error_log('maps_search_clients.php: Table clients does not exist');
        jsonResponse(['ok' => false, 'error' => 'Table clients introuvable'], 500);
    }
} catch (PDOException $e) {
    error_log('maps_search_clients.php table check error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de vérification'], 500);
}

try {
    // Recherche dans raison_sociale, nom_dirigeant, prenom_dirigeant, numero_client, adresse, ville, code_postal
    $searchTerm = '%' . $query . '%';
    
    // S'assurer que $limit est un entier valide
    $limit = max(1, min((int)$limit, 50));
    $limitInt = (int)$limit;
    
    // Requête SQL très simple - récupérer plus de résultats puis filtrer en PHP
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
        ORDER BY raison_sociale ASC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($sql);
    if (!$stmt) {
        $errorInfo = $pdo->errorInfo();
        error_log('maps_search_clients.php prepare error: ' . json_encode($errorInfo));
        jsonResponse(['ok' => false, 'error' => 'Erreur de préparation SQL'], 500);
    }
    
    $stmt->bindValue(':q', $searchTerm, PDO::PARAM_STR);
    
    $execResult = $stmt->execute();
    if (!$execResult) {
        $errorInfo = $stmt->errorInfo();
        error_log('maps_search_clients.php execute error: ' . json_encode($errorInfo));
        jsonResponse(['ok' => false, 'error' => 'Erreur d\'exécution SQL'], 500);
    }
    
    $allClients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtrer les résultats en PHP pour inclure nom, prénom, adresse, etc.
    $clients = [];
    $queryLower = mb_strtolower($query, 'UTF-8');
    
    foreach ($allClients as $c) {
        $match = false;
        
        // Raison sociale
        if (stripos($c['raison_sociale'] ?? '', $query) !== false) {
            $match = true;
        }
        // Numéro client
        elseif (stripos($c['numero_client'] ?? '', $query) !== false) {
            $match = true;
        }
        // Nom dirigeant
        elseif (!empty($c['nom_dirigeant']) && stripos($c['nom_dirigeant'], $query) !== false) {
            $match = true;
        }
        // Prénom dirigeant
        elseif (!empty($c['prenom_dirigeant']) && stripos($c['prenom_dirigeant'], $query) !== false) {
            $match = true;
        }
        // Nom + Prénom
        elseif (!empty($c['nom_dirigeant']) && !empty($c['prenom_dirigeant'])) {
            $nomComplet = trim($c['nom_dirigeant'] . ' ' . $c['prenom_dirigeant']);
            $prenomNom = trim($c['prenom_dirigeant'] . ' ' . $c['nom_dirigeant']);
            if (stripos($nomComplet, $query) !== false || stripos($prenomNom, $query) !== false) {
                $match = true;
            }
        }
        // Adresse
        elseif (!empty($c['adresse']) && stripos($c['adresse'], $query) !== false) {
            $match = true;
        }
        // Ville
        elseif (!empty($c['ville']) && stripos($c['ville'], $query) !== false) {
            $match = true;
        }
        // Code postal
        elseif (!empty($c['code_postal']) && stripos($c['code_postal'], $query) !== false) {
            $match = true;
        }
        // Adresse complète
        elseif (!empty($c['adresse']) && !empty($c['code_postal']) && !empty($c['ville'])) {
            $adresseComplete = trim($c['adresse'] . ' ' . $c['code_postal'] . ' ' . $c['ville']);
            if (stripos($adresseComplete, $query) !== false) {
                $match = true;
            }
        }
        
        if ($match) {
            $clients[] = $c;
            if (count($clients) >= $limitInt) {
                break;
            }
        }
    }
    
    // Formater les résultats - Utiliser exactement les données de la base de données
    $formatted = [];
    foreach ($clients as $c) {
        // Construire l'adresse complète exactement comme stockée dans la base de données
        // Utiliser adresse + code_postal + ville
        $adresseComplete = trim($c['adresse']);
        $codePostal = trim($c['code_postal']);
        $ville = trim($c['ville']);
        
        // Construire l'adresse complète avec les données exactes de la base
        $address = trim(sprintf('%s %s %s', $adresseComplete, $codePostal, $ville));
        
        // Pour le géocodage, utiliser l'adresse de livraison si elle est différente et existe
        $addressForGeocode = $address;
        // Vérifier explicitement si livraison_identique est 0 ou false (adresse de livraison différente)
        $livraisonIdentique = isset($c['livraison_identique']) ? (bool)$c['livraison_identique'] : false;
        if (!empty($c['adresse_livraison']) && !$livraisonIdentique) {
            // Si adresse de livraison existe et est différente, l'utiliser
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
            'address' => $address, // Adresse principale (exactement comme dans la BDD)
            'address_geocode' => $addressForGeocode, // Adresse à utiliser pour le géocodage
            'adresse' => $c['adresse'], // Rue exacte de la BDD
            'code_postal' => $c['code_postal'], // Code postal exact de la BDD
            'ville' => $c['ville'], // Ville exacte de la BDD
            'adresse_livraison' => $c['adresse_livraison'] ?? null,
            'livraison_identique' => (bool)($c['livraison_identique'] ?? false),
            'telephone' => $c['telephone1'],
            'email' => $c['email'],
            'basePriority' => 1 // Par défaut, peut être basé sur livraisons en attente plus tard
        ];
    }
    
    jsonResponse(['ok' => true, 'clients' => $formatted]);
    
} catch (PDOException $e) {
    error_log('maps_search_clients.php SQL error: ' . $e->getMessage());
    error_log('maps_search_clients.php SQL error code: ' . ($e->getCode() ?? 'N/A'));
    error_log('maps_search_clients.php SQL error info: ' . json_encode($e->errorInfo ?? []));
    error_log('maps_search_clients.php SQL trace: ' . $e->getTraceAsString());
    // Ne pas exposer le message d'erreur SQL complet pour des raisons de sécurité
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('maps_search_clients.php error: ' . $e->getMessage());
    error_log('maps_search_clients.php error file: ' . $e->getFile() . ':' . $e->getLine());
    error_log('maps_search_clients.php trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

