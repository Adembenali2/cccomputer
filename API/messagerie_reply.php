<?php
// API pour répondre à un message (par emoji ou texte)
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
    // Démarrer la session AVANT tout
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
    require_once __DIR__ . '/../includes/api_helpers.php';
} catch (Throwable $e) {
    error_log('messagerie_reply.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    error_log('messagerie_reply.php - Session user_id vide. Session ID: ' . session_id());
    jsonResponse(['ok' => false, 'error' => 'Non authentifié'], 401);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['ok' => false, 'error' => 'Méthode non autorisée'], 405);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    jsonResponse(['ok' => false, 'error' => 'JSON invalide'], 400);
}

// Vérification CSRF
$csrfToken = $data['csrf_token'] ?? '';
$csrfSession = $_SESSION['csrf_token'] ?? '';
if (empty($csrfToken) || empty($csrfSession) || !hash_equals($csrfSession, $csrfToken)) {
    jsonResponse(['ok' => false, 'error' => 'Token CSRF invalide'], 403);
}

$idExpediteur = (int)$_SESSION['user_id'];
$idMessageParent = (int)($data['message_id'] ?? 0);
$reponseType = trim($data['reponse_type'] ?? ''); // 'emoji' ou 'text'
$reponseContenu = trim($data['reponse_contenu'] ?? '');

if ($idMessageParent <= 0) {
    jsonResponse(['ok' => false, 'error' => 'ID message parent invalide'], 400);
}

if (!in_array($reponseType, ['emoji', 'text'], true)) {
    jsonResponse(['ok' => false, 'error' => 'Type de réponse invalide'], 400);
}

if (empty($reponseContenu)) {
    jsonResponse(['ok' => false, 'error' => 'Le contenu de la réponse est obligatoire'], 400);
}

try {
    // Vérifier que le message parent existe
    $checkParent = $pdo->prepare("
        SELECT id, id_expediteur, id_destinataire, sujet, type_lien, id_lien
        FROM messagerie 
        WHERE id = :id
        LIMIT 1
    ");
    $checkParent->execute([':id' => $idMessageParent]);
    $parent = $checkParent->fetch(PDO::FETCH_ASSOC);
    
    if (!$parent) {
        jsonResponse(['ok' => false, 'error' => 'Message parent introuvable'], 404);
    }
    
    // Déterminer le destinataire de la réponse
    // Si je réponds à un message dont je suis le destinataire, je réponds à l'expéditeur
    // Si je réponds à un message dont je suis l'expéditeur, je réponds au destinataire
    // Si le message parent est "à tous" (id_destinataire IS NULL), la réponse est aussi "à tous"
    $idDestinataire = null;
    if ($parent['id_destinataire'] === null) {
        // Message "à tous" : la réponse est aussi "à tous"
        $idDestinataire = null;
    } elseif ((int)$parent['id_expediteur'] === $idExpediteur) {
        // Je suis l'expéditeur, je réponds au destinataire
        $idDestinataire = $parent['id_destinataire'];
    } else {
        // Je suis le destinataire, je réponds à l'expéditeur
        $idDestinataire = $parent['id_expediteur'];
    }
    
    // Construire le sujet de la réponse
    $sujetReponse = 'Re: ' . mb_substr($parent['sujet'], 0, 200);
    
    // Construire le message de réponse
    $messageReponse = '';
    if ($reponseType === 'emoji') {
        $messageReponse = $reponseContenu; // L'emoji directement
    } else {
        $messageReponse = $reponseContenu; // Le texte
    }
    
    // Vérifier si la table messagerie existe et si id_message_parent existe
    $hasParentColumn = false;
    try {
        if (function_exists('columnExists')) {
            $hasParentColumn = columnExists($pdo, 'messagerie', 'id_message_parent');
        } else {
            // Fallback : vérifier directement avec SQL
            $checkCol = $pdo->prepare("
                SELECT COUNT(*) as cnt 
                FROM INFORMATION_SCHEMA.COLUMNS 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = :table 
                AND COLUMN_NAME = :column
            ");
            $checkCol->execute([':table' => 'messagerie', ':column' => 'id_message_parent']);
            $hasParentColumn = ((int)$checkCol->fetch(PDO::FETCH_ASSOC)['cnt'] > 0);
        }
    } catch (Throwable $e) {
        error_log('messagerie_reply.php - Erreur vérification colonne id_message_parent: ' . $e->getMessage());
        $hasParentColumn = false;
    }
    
    if ($hasParentColumn) {
        $sql = "
            INSERT INTO messagerie (
                id_expediteur, id_destinataire, sujet, message, 
                type_lien, id_lien, id_message_parent, lu, date_envoi
            ) VALUES (
                :id_expediteur, :id_destinataire, :sujet, :message,
                :type_lien, :id_lien, :id_message_parent, 0, NOW()
            )
        ";
        $params = [
            ':id_expediteur' => $idExpediteur,
            ':id_destinataire' => $idDestinataire,
            ':sujet' => $sujetReponse,
            ':message' => $messageReponse,
            ':type_lien' => $parent['type_lien'] ?: null,
            ':id_lien' => $parent['id_lien'] ?: null,
            ':id_message_parent' => $idMessageParent
        ];
    } else {
        // Fallback si la colonne n'existe pas encore
        $sql = "
            INSERT INTO messagerie (
                id_expediteur, id_destinataire, sujet, message, 
                type_lien, id_lien, lu, date_envoi
            ) VALUES (
                :id_expediteur, :id_destinataire, :sujet, :message,
                :type_lien, :id_lien, 0, NOW()
            )
        ";
        $params = [
            ':id_expediteur' => $idExpediteur,
            ':id_destinataire' => $idDestinataire,
            ':sujet' => $sujetReponse,
            ':message' => $messageReponse,
            ':type_lien' => $parent['type_lien'] ?: null,
            ':id_lien' => $parent['id_lien'] ?: null
        ];
    }
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    $replyId = (int)$pdo->lastInsertId();
    
    $pdo->commit();
    
    // Enregistrer dans l'historique
    try {
        $reponseTypeLabel = $reponseType === 'emoji' ? 'emoji' : 'texte';
        $contenuShort = mb_substr($reponseContenu, 0, 50);
        if (mb_strlen($reponseContenu) > 50) {
            $contenuShort .= '...';
        }
        $details = sprintf(
            'Réponse (%s) envoyée au message #%d : "%s" - Contenu: %s',
            $reponseTypeLabel,
            $idMessageParent,
            mb_substr($parent['sujet'], 0, 50),
            $contenuShort
        );
        enregistrerAction($pdo, $idExpediteur, 'message_repondu', $details);
    } catch (Throwable $e) {
        error_log('messagerie_reply.php log error: ' . $e->getMessage());
    }
    
    jsonResponse([
        'ok' => true,
        'reply_id' => $replyId,
        'message' => 'Réponse envoyée avec succès'
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('messagerie_reply.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('messagerie_reply.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

