<?php

/**
 * Enregistre une action dans l'historique.
 *
 * @param PDO $pdo L'objet de connexion à la base de données.
 * @param int|null $userId L'ID de l'utilisateur qui effectue l'action (peut être null pour les actions système).
 * @param string $action Le type d'action (ex: "connexion_reussie", "creation_client").
 * @param string $details Détails supplémentaires sur l'action (optionnel).
 * @return bool Retourne true en cas de succès, false sinon.
 */
function enregistrerAction(PDO $pdo, ?int $userId, string $action, string $details = ''): bool 
{
    // Récupérer l'adresse IP de l'utilisateur de manière sécurisée
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'UNKNOWN';

    $sql = "INSERT INTO historique (user_id, action, details, ip_address, date_action) 
            VALUES (:user_id, :action, :details, :ip_address, NOW())";
    
    try {
        $stmt = $pdo->prepare($sql);
        
        return $stmt->execute([
            ':user_id' => $userId,
            ':action' => $action,
            ':details' => $details,
            ':ip_address' => $ipAddress
        ]);
    } catch (PDOException $e) {
        // Optionnel : enregistrer l'erreur dans un fichier de log
        // error_log('Erreur d\'historique: ' . $e->getMessage());
        return false;
    }
}
?>