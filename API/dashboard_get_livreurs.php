<?php
// API pour récupérer la liste des livreurs (pour dashboard)
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
    error_log('dashboard_get_livreurs.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

try {
    // Récupérer les utilisateurs avec Emploi = 'Livreur' et statut = 'actif'
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
        WHERE Emploi = 'Livreur' AND statut = 'actif'
        ORDER BY nom, prenom ASC
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

