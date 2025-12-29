<?php
// API pour récupérer la liste des techniciens (pour dashboard)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

try {
    // Récupérer uniquement les utilisateurs avec Emploi = 'Technicien' et statut = 'actif'
    $sql = "
        SELECT 
            id,
            nom,
            prenom,
            Email,
            telephone,
            Emploi,
            statut
        FROM utilisateurs
        WHERE Emploi = 'Technicien' 
          AND statut = 'actif'
        ORDER BY nom ASC, prenom ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $techniciens = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = [];
    foreach ($techniciens as $t) {
        $formatted[] = [
            'id' => (int)$t['id'],
            'nom' => $t['nom'],
            'prenom' => $t['prenom'],
            'full_name' => trim($t['prenom'] . ' ' . $t['nom']),
            'email' => $t['Email'],
            'telephone' => $t['telephone']
        ];
    }
    
    jsonResponse(['ok' => true, 'techniciens' => $formatted]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_techniciens.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_techniciens.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

