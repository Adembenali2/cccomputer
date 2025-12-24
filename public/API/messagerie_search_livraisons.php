<?php
// API pour rechercher des livraisons dans la messagerie
require_once __DIR__ . '/../includes/api_helpers.php';
initApi();
requireApiAuth();

$query = trim($_GET['q'] ?? '');
$limit = min((int)($_GET['limit'] ?? 10), 20);

if (empty($query) || strlen($query) < 1) {
    jsonResponse(['ok' => true, 'results' => []]);
}

try {
    // Vérifier que la table livraisons existe
    // Note: SHOW TABLES LIKE ne supporte pas les paramètres liés, on utilise INFORMATION_SCHEMA
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = :table
    ");
    $checkTable->execute([':table' => 'livraisons']);
    if (((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) === 0) {
        jsonResponse(['ok' => true, 'results' => []]); // Retourne vide si la table n'existe pas
    }
    
    $searchTerm = '%' . $query . '%';
    $limitInt = max(1, min((int)$limit, 50)); // Entre 1 et 50
    
    // Requête simplifiée avec binding correct
    // Note: LIMIT ne peut pas être lié en paramètre dans MySQL, donc on cast en int (sécurisé)
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
        LIMIT :limit
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':q1', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':q2', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':q3', $searchTerm, PDO::PARAM_STR);
    $stmt->bindValue(':limit', $limitInt, PDO::PARAM_INT);
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
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_search_livraisons.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

