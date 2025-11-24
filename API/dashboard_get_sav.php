<?php
// API pour récupérer les SAV d'un client (pour dashboard)
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
    error_log('dashboard_get_sav.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$clientId = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

if ($clientId <= 0) {
    error_log('dashboard_get_sav.php: ID client invalide ou manquant - client_id=' . ($_GET['client_id'] ?? 'non défini'));
    jsonResponse(['ok' => false, 'error' => 'ID client invalide ou manquant'], 400);
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    jsonResponse(['ok' => false, 'error' => 'Erreur de connexion à la base de données'], 500);
}

try {
    // Vérifier si la table sav existe
    // Note: SHOW TABLES LIKE ne supporte pas les paramètres liés, on utilise une requête INFORMATION_SCHEMA à la place
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = :table
    ");
    $checkTable->execute([':table' => 'sav']);
    $tableExists = ((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
    if (!$tableExists) {
        // Table n'existe pas encore, retourner une liste vide
        jsonResponse(['ok' => true, 'savs' => []]);
    }
    
    $sql = "
        SELECT 
            s.id,
            s.reference,
            s.description,
            s.date_ouverture,
            s.date_fermeture,
            s.statut,
            s.priorite,
            s.type_panne,
            s.commentaire,
            s.id_technicien,
            u.nom AS technicien_nom,
            u.prenom AS technicien_prenom,
            s.created_at,
            s.updated_at
        FROM sav s
        LEFT JOIN utilisateurs u ON u.id = s.id_technicien
        WHERE s.id_client = :client_id
        ORDER BY 
            CASE s.priorite
                WHEN 'urgente' THEN 1
                WHEN 'haute' THEN 2
                WHEN 'normale' THEN 3
                WHEN 'basse' THEN 4
            END,
            s.date_ouverture DESC, s.id DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':client_id' => $clientId]);
    $savs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse(['ok' => true, 'savs' => $savs]);
    
} catch (PDOException $e) {
    error_log('dashboard_get_sav.php SQL error: ' . $e->getMessage());
    // Si la table n'existe pas, retourner une liste vide au lieu d'une erreur
    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Unknown table") !== false) {
        jsonResponse(['ok' => true, 'savs' => []]);
    }
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('dashboard_get_sav.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

