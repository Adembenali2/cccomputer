<?php
// API pour récupérer la liste des livreurs (pour dashboard)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();
requireApiAuth();

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
$pdo = getPdoOrFail();

try {
    // Récupérer uniquement les utilisateurs avec Emploi = 'Livreur' et statut = 'actif'
    // Le champ Emploi est un ENUM : 'Chargé relation clients','Livreur','Technicien','Secrétaire','Dirigeant','Admin'
    // On filtre strictement sur Emploi = 'Livreur' pour obtenir uniquement les livreurs
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
        WHERE Emploi = 'Livreur' 
          AND statut = 'actif'
        ORDER BY nom ASC, prenom ASC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $livreurs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $formatted = [];
    foreach ($livreurs as $l) {
        $formatted[] = [
            'id' => (int)$l['id'],
            'nom' => $l['nom'],
            'prenom' => $l['prenom'],
            'full_name' => trim($l['prenom'] . ' ' . $l['nom']),
            'email' => $l['Email'],
            'telephone' => $l['telephone']
        ];
    }
    
    jsonResponse(['ok' => true, 'livreurs' => $formatted]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_livreurs.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_livreurs.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

