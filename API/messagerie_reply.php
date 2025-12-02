<?php
// API pour répondre à un message (par emoji ou texte)

// Activer le buffer de sortie IMMÉDIATEMENT pour capturer toute sortie accidentelle
ob_start();

// Désactiver l'affichage des erreurs HTML pour retourner uniquement du JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);

// Définir le header JSON en premier (avant toute autre sortie)
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// La fonction jsonResponse() est définie dans includes/api_helpers.php

// Gestionnaire d'erreur global pour capturer toutes les erreurs fatales
set_error_handler(function($severity, $message, $file, $line) {
    if (error_reporting() === 0) {
        return false;
    }
    // Nettoyer toute sortie avant de répondre
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("messagerie_reply.php Error: $message in $file:$line");
    jsonResponse(['ok' => false, 'error' => 'Erreur serveur: ' . $message], 500);
    return true;
});

// Gestionnaire d'exception pour capturer les exceptions non capturées
set_exception_handler(function($exception) {
    // Nettoyer toute sortie avant de répondre
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log('messagerie_reply.php Uncaught exception: ' . $exception->getMessage());
    error_log('Stack trace: ' . $exception->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . $exception->getMessage()], 500);
});

// Gestionnaire d'erreur fatale
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        // Nettoyer toute sortie avant de répondre
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        error_log('messagerie_reply.php Fatal error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Erreur fatale: ' . $error['message']], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE);
        exit;
    }
});

try {
    // Inclure session_config.php EN PREMIER (il démarre la session si nécessaire)
    // session_config.php configure les paramètres de session AVANT de démarrer la session
    $outputBefore = ob_get_contents();
    require_once __DIR__ . '/../includes/session_config.php';
    require_once __DIR__ . '/../includes/db.php';
    require_once __DIR__ . '/../includes/historique.php';
    require_once __DIR__ . '/../includes/api_helpers.php';
    $outputAfter = ob_get_contents();
    
    // Si des includes ont généré de la sortie, la nettoyer
    if ($outputAfter !== $outputBefore) {
        ob_clean();
        error_log('messagerie_reply.php - Sortie détectée dans les includes, nettoyée');
    }
} catch (Throwable $e) {
    error_log('messagerie_reply.php require error: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    jsonResponse(['ok' => false, 'error' => 'Erreur d\'initialisation: ' . $e->getMessage()], 500);
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
        // Vérifier d'abord si la table messagerie existe
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
        
        // Vérifier si la colonne id_message_parent existe
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
        
        if (!$hasParentColumn) {
            error_log('messagerie_reply.php - La colonne id_message_parent n\'existe pas. La réponse sera créée sans lien parent.');
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
            ':type_lien' => !empty($parent['type_lien']) ? $parent['type_lien'] : null,
            ':id_lien' => !empty($parent['id_lien']) ? (int)$parent['id_lien'] : null,
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
            ':type_lien' => !empty($parent['type_lien']) ? $parent['type_lien'] : null,
            ':id_lien' => !empty($parent['id_lien']) ? (int)$parent['id_lien'] : null
        ];
    }
    
    $pdo->beginTransaction();
    
    try {
        $stmt = $pdo->prepare($sql);
        
        // Log pour débogage (seulement si DEBUG est défini)
        if (defined('DEBUG') && DEBUG) {
            error_log('messagerie_reply.php - SQL: ' . $sql);
            error_log('messagerie_reply.php - Params: ' . json_encode($params));
        }
        
        $stmt->execute($params);
        
        $replyId = (int)$pdo->lastInsertId();
        
        if ($replyId <= 0) {
            throw new Exception('Impossible d\'obtenir l\'ID de la réponse insérée');
        }
        
        $pdo->commit();
    } catch (PDOException $e) {
        if (isset($pdo) && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e; // Re-lancer pour être capturé par le catch externe
    }
    
    // Enregistrer dans l'historique (sans générer de sortie)
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
        
        // Vérifier qu'il n'y a pas de sortie avant l'appel
        $outputBefore = ob_get_contents();
        enregistrerAction($pdo, $idExpediteur, 'message_repondu', $details);
        $outputAfter = ob_get_contents();
        
        // Si enregistrerAction a généré de la sortie, la nettoyer
        if ($outputAfter !== $outputBefore) {
            ob_clean();
            error_log('messagerie_reply.php - Sortie détectée dans enregistrerAction, nettoyée');
        }
    } catch (Throwable $e) {
        error_log('messagerie_reply.php log error: ' . $e->getMessage());
        // Ne pas bloquer la réponse si l'historique échoue
    }
    
    // S'assurer qu'il n'y a pas de sortie avant d'envoyer le JSON
    $finalOutput = ob_get_contents();
    if (!empty($finalOutput)) {
        ob_clean();
        error_log('messagerie_reply.php - Sortie détectée avant jsonResponse, nettoyée: ' . substr($finalOutput, 0, 200));
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
    error_log('messagerie_reply.php SQL error code: ' . $e->getCode());
    error_log('messagerie_reply.php SQL: ' . ($sql ?? 'N/A'));
    error_log('messagerie_reply.php Params: ' . json_encode($params ?? []));
    // En mode développement, on peut retourner plus de détails
    if (defined('DEBUG') && DEBUG) {
        jsonResponse(['ok' => false, 'error' => 'Erreur de base de données: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')], 500);
    } else {
        jsonResponse(['ok' => false, 'error' => 'Erreur de base de données. Vérifiez les logs pour plus de détails.'], 500);
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('messagerie_reply.php error: ' . $e->getMessage());
    error_log('messagerie_reply.php stack trace: ' . $e->getTraceAsString());
    // En mode développement, on peut retourner plus de détails
    if (defined('DEBUG') && DEBUG) {
        jsonResponse(['ok' => false, 'error' => 'Erreur inattendue: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')], 500);
    } else {
        jsonResponse(['ok' => false, 'error' => 'Erreur inattendue. Vérifiez les logs pour plus de détails.'], 500);
    }
}

