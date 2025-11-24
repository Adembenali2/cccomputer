<?php
// API pour envoyer un message dans la messagerie interne
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
    // Inclure session_config.php EN PREMIER (il démarre la session si nécessaire)
    // session_config.php configure les paramètres de session AVANT de démarrer la session
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
} catch (Throwable $e) {
    error_log('messagerie_send.php require error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation'], 500);
}

if (empty($_SESSION['user_id'])) {
    error_log('messagerie_send.php - Session user_id vide. Session ID: ' . session_id());
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
$idDestinataire = !empty($data['id_destinataire']) ? (int)$data['id_destinataire'] : null;
$sujet = trim($data['sujet'] ?? '');
$message = trim($data['message'] ?? '');
$typeLien = trim($data['type_lien'] ?? '');
$idLien = !empty($data['id_lien']) ? (int)$data['id_lien'] : null;

// Validation
if (empty($sujet) || empty($message)) {
    jsonResponse(['ok' => false, 'error' => 'Le sujet et le message sont obligatoires'], 400);
}

if (strlen($sujet) > 255) {
    jsonResponse(['ok' => false, 'error' => 'Le sujet est trop long (max 255 caractères)'], 400);
}

$allowedTypes = ['client', 'livraison', 'sav'];
if ($typeLien && !in_array($typeLien, $allowedTypes, true)) {
    jsonResponse(['ok' => false, 'error' => 'Type de lien invalide'], 400);
}

// Si un type de lien est spécifié, vérifier que l'ID existe
if ($typeLien && $idLien) {
    try {
        if ($typeLien === 'client') {
            $check = $pdo->prepare("SELECT id FROM clients WHERE id = :id LIMIT 1");
        } elseif ($typeLien === 'livraison') {
            $check = $pdo->prepare("SELECT id FROM livraisons WHERE id = :id LIMIT 1");
        } elseif ($typeLien === 'sav') {
            $check = $pdo->prepare("SELECT id FROM sav WHERE id = :id LIMIT 1");
        }
        
        if (isset($check)) {
            $check->execute([':id' => $idLien]);
            if (!$check->fetch()) {
                jsonResponse(['ok' => false, 'error' => ucfirst($typeLien) . ' introuvable'], 404);
            }
        }
    } catch (PDOException $e) {
        error_log('messagerie_send.php check lien error: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur de vérification'], 500);
    }
}

// Si un destinataire est spécifié, vérifier qu'il existe
if ($idDestinataire) {
    try {
        $check = $pdo->prepare("SELECT id FROM utilisateurs WHERE id = :id AND statut = 'actif' LIMIT 1");
        $check->execute([':id' => $idDestinataire]);
        if (!$check->fetch()) {
            jsonResponse(['ok' => false, 'error' => 'Destinataire introuvable ou inactif'], 404);
        }
    } catch (PDOException $e) {
        error_log('messagerie_send.php check destinataire error: ' . $e->getMessage());
        jsonResponse(['ok' => false, 'error' => 'Erreur de vérification'], 500);
    }
}

try {
    // Vérifier si la table messagerie existe
    // Note: SHOW TABLES LIKE ne supporte pas les paramètres liés, on utilise INFORMATION_SCHEMA
    $checkTable = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = :table
    ");
    $checkTable->execute([':table' => 'messagerie']);
    if (((int)$checkTable->fetch(PDO::FETCH_ASSOC)['cnt']) === 0) {
        jsonResponse(['ok' => false, 'error' => 'La table de messagerie n\'existe pas. Veuillez exécuter la migration SQL.'], 500);
    }
    
    $pdo->beginTransaction();
    
    $sql = "
        INSERT INTO messagerie (
            id_expediteur, id_destinataire, sujet, message, 
            type_lien, id_lien, lu, date_envoi
        ) VALUES (
            :id_expediteur, :id_destinataire, :sujet, :message,
            :type_lien, :id_lien, 0, NOW()
        )
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id_expediteur' => $idExpediteur,
        ':id_destinataire' => $idDestinataire,
        ':sujet' => $sujet,
        ':message' => $message,
        ':type_lien' => $typeLien ?: null,
        ':id_lien' => $idLien
    ]);
    
    $messageId = (int)$pdo->lastInsertId();
    
    $pdo->commit();
    
    // Enregistrer dans l'historique
    try {
        $destinataireLabel = $idDestinataire ? "utilisateur #{$idDestinataire}" : "tous les utilisateurs";
        $lienLabel = '';
        if ($typeLien && $idLien) {
            $lienLabel = " (lié à {$typeLien} #{$idLien})";
        }
        $details = "Message envoyé à {$destinataireLabel} : \"{$sujet}\"{$lienLabel}";
        enregistrerAction($pdo, $idExpediteur, 'message_envoye', $details);
    } catch (Throwable $e) {
        error_log('messagerie_send.php log error: ' . $e->getMessage());
    }
    
    jsonResponse([
        'ok' => true,
        'message_id' => $messageId,
        'message' => 'Message envoyé avec succès'
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('messagerie_send.php SQL error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur de base de données'], 500);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('messagerie_send.php error: ' . $e->getMessage());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue'], 500);
}

