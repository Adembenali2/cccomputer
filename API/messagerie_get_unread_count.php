<?php
// API pour récupérer le nombre de messages non lus (pour le badge dans le header)
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

function jsonResponse(array $data, int $statusCode = 200): void {
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
    error_log('messagerie_get_unread_count.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

$userId = (int)$_SESSION['user_id'];

try {
    // Compter les messages directs non lus
    $stmt1 = $pdo->prepare("
        SELECT COUNT(*) 
        FROM messagerie 
        WHERE id_destinataire = :user_id
          AND lu = 0 
          AND supprime_destinataire = 0
    ");
    $stmt1->execute([':user_id' => $userId]);
    $countDirect = (int)$stmt1->fetchColumn();
    
    // Compter les messages "à tous" non lus (non présents dans la table de lectures)
    $countBroadcast = 0;
    try {
        $stmt2 = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messagerie m
            LEFT JOIN messagerie_lectures ml ON ml.id_message = m.id AND ml.id_utilisateur = :user_id
            WHERE m.id_destinataire IS NULL
              AND m.id_expediteur != :user_id2
              AND m.supprime_destinataire = 0
              AND ml.id IS NULL
        ");
        $stmt2->execute([':user_id' => $userId, ':user_id2' => $userId]);
        $countBroadcast = (int)$stmt2->fetchColumn();
    } catch (PDOException $e) {
        // Si la table de lectures n'existe pas encore, on compte tous les messages à tous
        error_log('messagerie_get_unread_count.php - Table lectures peut ne pas exister: ' . $e->getMessage());
        $stmt2b = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messagerie 
            WHERE id_destinataire IS NULL
              AND id_expediteur != :user_id
              AND supprime_destinataire = 0
        ");
        $stmt2b->execute([':user_id' => $userId]);
        $countBroadcast = (int)$stmt2b->fetchColumn();
    }
    
    $count = $countDirect + $countBroadcast;
    
    jsonResponse(['ok' => true, 'count' => $count]);
    
} catch (PDOException $e) {
    error_log('messagerie_get_unread_count.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    error_log('messagerie_get_unread_count.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

