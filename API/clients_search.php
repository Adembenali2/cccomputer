<?php
// API endpoint pour rechercher des clients
// GET /API/clients_search.php?q=search_term

require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

try {
    // Récupérer le terme de recherche
    $searchTerm = trim($_GET['q'] ?? '');
    
    // Si le terme est vide ou contient moins d'1 caractère non-blanc, retourner un tableau vide
    if (empty($searchTerm) || strlen(trim($searchTerm)) < 1) {
        jsonResponse([
            'ok' => true,
            'clients' => []
        ]);
    }
    
    // Nettoyer et préparer le terme de recherche pour LIKE
    $searchTerm = trim($searchTerm);
    $likeTerm = '%' . $searchTerm . '%';
    
    // Requête SQL avec LIKE sur les champs demandés
    $sql = "
        SELECT 
            id,
            numero_client,
            raison_sociale,
            nom_dirigeant,
            prenom_dirigeant,
            ville,
            telephone1,
            email
        FROM clients
        WHERE 
            raison_sociale LIKE :term
            OR nom_dirigeant LIKE :term
            OR prenom_dirigeant LIKE :term
            OR numero_client LIKE :term
        ORDER BY raison_sociale ASC
        LIMIT 20
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':term' => $likeTerm]);
    
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Nettoyer les données (s'assurer que tous les champs sont présents même si NULL)
    $results = [];
    foreach ($clients as $client) {
        $results[] = [
            'id' => (int)($client['id'] ?? 0),
            'numero_client' => $client['numero_client'] ?? '',
            'raison_sociale' => $client['raison_sociale'] ?? '',
            'nom_dirigeant' => $client['nom_dirigeant'] ?? '',
            'prenom_dirigeant' => $client['prenom_dirigeant'] ?? '',
            'ville' => $client['ville'] ?? '',
            'telephone1' => $client['telephone1'] ?? '',
            'email' => $client['email'] ?? ''
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'clients' => $results
    ]);
    
} catch (PDOException $e) {
    error_log('clients_search.php PDO error: ' . $e->getMessage());
    error_log('clients_search.php SQL State: ' . ($e->errorInfo[0] ?? 'N/A'));
    error_log('clients_search.php Error Code: ' . ($e->errorInfo[1] ?? 'N/A'));
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur lors de la recherche de clients',
        'message' => 'Une erreur est survenue lors de la recherche. Veuillez réessayer.'
    ], 500);
    
} catch (Throwable $e) {
    error_log('clients_search.php error: ' . $e->getMessage());
    error_log('clients_search.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('clients_search.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur inattendue',
        'message' => 'Une erreur est survenue lors de la recherche. Veuillez réessayer.'
    ], 500);
}

