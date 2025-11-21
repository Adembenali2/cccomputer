<?php
// API pour récupérer les livraisons d'un client (pour dashboard)
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
    error_log('dashboard_get_deliveries.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($clientId <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID client invalide'], 400);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

try {
    $sql = "
        SELECT 
            l.id,
            l.reference,
            l.adresse_livraison,
            l.objet,
            l.date_prevue,
            l.date_reelle,
            l.statut,
            l.commentaire,
            l.id_livreur,
            u.nom AS livreur_nom,
            u.prenom AS livreur_prenom,
            l.created_at,
            l.updated_at
        FROM livraisons l
        LEFT JOIN utilisateurs u ON u.id = l.id_livreur
        WHERE l.id_client = :client_id
        ORDER BY l.date_prevue DESC, l.id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $livraisons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(['ok' => true, 'livraisons' => $livraisons]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_deliveries.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_deliveries.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

