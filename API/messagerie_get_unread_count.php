<?php
// API pour récupérer le nombre de messages non lus (pour le badge dans le header)
require_once __DIR__ . '/../includes/api_helpers.php';

initApi();

// Pour ce endpoint spécifique, on retourne 0 au lieu d'une erreur si non authentifié
// pour ne pas bloquer le header (comportement existant conservé)
if (empty($_SESSION['user_id'])) {
    jsonResponse(['ok' => true, 'count' => 0]);
}

$userId = (int)$_SESSION['user_id'];

// Récupérer PDO via la fonction centralisée (apiFail en cas d'erreur)
// Note: pour ce endpoint spécifique, on retourne 0 au lieu d'une erreur pour ne pas bloquer le header
try {
    $pdo = getPdo();
} catch (RuntimeException $e) {
    error_log('messagerie_get_unread_count.php: getPdo() failed - ' . $e->getMessage());
    jsonResponse(['ok' => true, 'count' => 0]); // Retourner 0 pour ne pas bloquer le header
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
        // Table n'existe pas encore, retourner 0
        jsonResponse(['ok' => true, 'count' => 0]);
    }
    
    // Compter les messages directs non lus
    $countDirect = 0;
    try {
        $stmt1 = $pdo->prepare("
            SELECT COUNT(*) 
            FROM messagerie 
            WHERE id_destinataire = :user_id
              AND lu = 0 
              AND supprime_destinataire = 0
        ");
        $stmt1->execute([':user_id' => $userId]);
        $countDirect = (int)$stmt1->fetchColumn();
    } catch (PDOException $e) {
        error_log('messagerie_get_unread_count.php - Erreur comptage direct: ' . $e->getMessage());
        $countDirect = 0;
    }
    
    // Compter les messages "à tous" non lus
    $countBroadcast = 0;
    try {
        // Vérifier si la table messagerie_lectures existe
        $checkLectures = $pdo->prepare("
            SELECT COUNT(*) as cnt 
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = DATABASE() 
            AND TABLE_NAME = :table
        ");
        $checkLectures->execute([':table' => 'messagerie_lectures']);
        $hasLecturesTable = ((int)$checkLectures->fetch(PDO::FETCH_ASSOC)['cnt']) > 0;
        
        if ($hasLecturesTable) {
            // Utiliser la table de lectures
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
        } else {
            // Table de lectures n'existe pas, compter tous les messages à tous
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
    } catch (PDOException $e) {
        error_log('messagerie_get_unread_count.php - Erreur comptage broadcast: ' . $e->getMessage());
        $countBroadcast = 0;
    }
    
    $count = $countDirect + $countBroadcast;
    
    jsonResponse(['ok' => true, 'count' => $count]);
    
} catch (PDOException $e) {
    error_log('messagerie_get_unread_count.php SQL error: ' . $e->getMessage());
    error_log('messagerie_get_unread_count.php SQL trace: ' . $e->getTraceAsString());
    // Retourner 0 au lieu d'une erreur pour éviter de bloquer le header
    jsonResponse(['ok' => true, 'count' => 0]);
} catch (Throwable $e) {
    error_log('messagerie_get_unread_count.php error: ' . $e->getMessage());
    error_log('messagerie_get_unread_count.php trace: ' . $e->getTraceAsString());
    // Retourner 0 au lieu d'une erreur pour éviter de bloquer le header
    jsonResponse(['ok' => true, 'count' => 0]);
}

