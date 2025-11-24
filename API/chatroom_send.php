<?php
// API/chatroom_send.php
// Endpoint pour envoyer un message dans la chatroom globale

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/helpers.php';

try {
    // Vérifier que la requête est en POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée']);
        exit;
    }

    // Récupérer les données JSON
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Données JSON invalides']);
        exit;
    }

    // Vérifier le token CSRF
    if (!verifyCsrfToken($data['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Token CSRF invalide']);
        exit;
    }

    // Récupérer l'ID utilisateur depuis la session
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Non authentifié']);
        exit;
    }

    // Valider le message
    $message = trim($data['message'] ?? '');
    if (empty($message)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le message ne peut pas être vide']);
        exit;
    }

    // Limiter la longueur du message (5000 caractères max)
    if (strlen($message) > 5000) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Le message est trop long (max 5000 caractères)']);
        exit;
    }

    // Vérifier que la table existe
    $tableExists = false;
    try {
        $checkTable = $pdo->query("SHOW TABLES LIKE 'chatroom_messages'");
        $tableExists = $checkTable->rowCount() > 0;
    } catch (PDOException $e) {
        error_log('chatroom_send.php - Erreur vérification table: ' . $e->getMessage());
    }

    if (!$tableExists) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Table chatroom_messages non trouvée. Veuillez exécuter la migration SQL.']);
        exit;
    }

    // Insérer le message
    $stmt = $pdo->prepare("
        INSERT INTO chatroom_messages (id_user, message, date_envoi)
        VALUES (:id_user, :message, NOW())
    ");

    $stmt->execute([
        ':id_user' => $userId,
        ':message' => $message
    ]);

    $messageId = (int)$pdo->lastInsertId();

    // Récupérer les informations complètes du message pour la réponse
    $stmt = $pdo->prepare("
        SELECT 
            m.id,
            m.id_user,
            m.message,
            m.date_envoi,
            u.nom,
            u.prenom,
            u.Emploi
        FROM chatroom_messages m
        INNER JOIN utilisateurs u ON u.id = m.id_user
        WHERE m.id = :id
    ");

    $stmt->execute([':id' => $messageId]);
    $messageData = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$messageData) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur lors de la récupération du message']);
        exit;
    }

    // Formater la réponse
    echo json_encode([
        'ok' => true,
        'message' => [
            'id' => (int)$messageData['id'],
            'id_user' => (int)$messageData['id_user'],
            'message' => $messageData['message'],
            'date_envoi' => $messageData['date_envoi'],
            'user_nom' => $messageData['nom'],
            'user_prenom' => $messageData['prenom'],
            'user_emploi' => $messageData['Emploi']
        ]
    ]);

} catch (PDOException $e) {
    error_log('chatroom_send.php - Erreur PDO: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur lors de l\'envoi du message']);
} catch (Exception $e) {
    error_log('chatroom_send.php - Erreur: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Erreur serveur']);
}

