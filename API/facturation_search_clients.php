<?php
/**
 * API endpoint pour rechercher des clients (pour la barre de recherche)
 * 
 * GET /API/facturation_search_clients.php
 * 
 * Paramètres:
 * - q (string, requis): Terme de recherche
 * - limit (int, optionnel): Nombre de résultats (défaut: 10)
 * 
 * Retourne:
 * {
 *   "ok": true,
 *   "data": [
 *     {
 *       "id": 1,
 *       "name": "ACME Industries",
 *       "raison_sociale": "ACME Industries",
 *       "numero_client": "CLI-0001",
 *       "prenom": "Jean",
 *       "nom": "Dupont"
 *     },
 *     ...
 *   ]
 * }
 */

require_once __DIR__ . '/../includes/api_helpers.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\ClientRepository;

initApi();
requireApiAuth();
$pdo = requirePdoConnection();

try {
    // Récupérer les paramètres
    $query = trim($_GET['q'] ?? '');
    $limit = !empty($_GET['limit']) ? (int)$_GET['limit'] : 10;
    
    // Validation
    if (empty($query)) {
        jsonResponse([
            'ok' => true,
            'data' => []
        ]);
    }
    
    if ($limit < 1 || $limit > 50) {
        $limit = 10;
    }
    
    // Rechercher les clients
    $clientRepository = new ClientRepository($pdo);
    $clients = $clientRepository->search($query, $limit);
    
    // Formater les résultats
    $data = [];
    foreach ($clients as $client) {
        $data[] = [
            'id' => $client->id,
            'name' => $client->raisonSociale,
            'raison_sociale' => $client->raisonSociale,
            'numero_client' => $client->numeroClient,
            'prenom' => $client->prenomDirigeant ?? '',
            'nom' => $client->nomDirigeant ?? '',
            'reference' => $client->numeroClient
        ];
    }
    
    jsonResponse([
        'ok' => true,
        'data' => $data
    ]);
    
} catch (Throwable $e) {
    error_log('facturation_search_clients.php error: ' . $e->getMessage());
    error_log('facturation_search_clients.php File: ' . $e->getFile() . ' Line: ' . $e->getLine());
    error_log('facturation_search_clients.php trace: ' . $e->getTraceAsString());
    
    jsonResponse([
        'ok' => false,
        'error' => 'Erreur serveur',
        'debug' => (defined('DEBUG_MODE') && DEBUG_MODE) ? [
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
            'type' => get_class($e)
        ] : null
    ], 500);
}

