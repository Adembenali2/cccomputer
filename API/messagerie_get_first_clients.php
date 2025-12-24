<?php
// API pour récupérer les premiers clients (pour affichage par défaut)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// La fonction jsonResponse() est définie dans includes/api_helpers.php

try {
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/api_helpers.php';
    // getPdo() est disponible via api_helpers.php qui charge helpers.php
} catch (Throwable $e) {
    error_log('messagerie_get_first_clients.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation: ' . $e->getMessage()], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

// Récupérer PDO via la fonction centralisée
$pdo = getPdo();

$limit = min((int)($_GET['limit'] ?? 3), 1000); // Augmenter la limite à 1000

try {
    $sql = "
        SELECT 
            id,
            numero_client,
            raison_sociale,
            adresse,
            code_postal,
            ville,
            nom_dirigeant,
            prenom_dirigeant,
            telephone1,
            email
        FROM clients
        ORDER BY raison_sociale ASC
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = [];
    foreach ($clients as $c) {
        $adresseComplete = trim($c['adresse']);
        $codePostal = trim($c['code_postal']);
        $ville = trim($c['ville']);
        $address = trim(sprintf('%s %s %s', $adresseComplete, $codePostal, $ville));
        
        $nomDirigeant = trim(($c['prenom_dirigeant'] ?? '') . ' ' . ($c['nom_dirigeant'] ?? ''));
        $nomDirigeant = $nomDirigeant ?: null;
        
        $formatted[] = [
            'id' => (int)$c['id'],
            'code' => $c['numero_client'],
            'name' => $c['raison_sociale'],
            'nom_dirigeant' => $c['nom_dirigeant'] ?? null,
            'prenom_dirigeant' => $c['prenom_dirigeant'] ?? null,
            'dirigeant_complet' => $nomDirigeant,
            'address' => $address,
            'adresse' => $c['adresse'],
            'code_postal' => $c['code_postal'],
            'ville' => $c['ville'],
            'telephone' => $c['telephone1'],
            'email' => $c['email']
        ];
    }
    
    jsonResponse(['ok' => true, 'clients' => $formatted]);
    
} catch (PDOException $e) {
    error_log('messagerie_get_first_clients.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_get_first_clients.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

