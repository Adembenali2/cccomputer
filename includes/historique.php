<?php
// includes/historique.php

/**
 * Détecte la meilleure IP client possible derrière un proxy (Railway).
 * - Priorité au premier IP public de X-Forwarded-For (si fourni par le reverse-proxy)
 * - Sinon X-Real-IP
 * - Sinon REMOTE_ADDR
 */
function getClientIp(): string
{
    // Helper de validation IP publique
    $isPublicIp = function (string $ip): bool {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    };

    // 1) X-Forwarded-For: peut contenir une liste "client, proxy1, proxy2..."
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        // On parcourt de gauche à droite et on prend la 1ère IP publique valide
        foreach (explode(',', $xff) as $raw) {
            $ip = trim($raw);
            if ($isPublicIp($ip)) {
                return $ip;
            }
        }
    }

    // 2) X-Real-IP (certains proxys la posent)
    $xri = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($xri !== '' && filter_var($xri, FILTER_VALIDATE_IP)) {
        return $xri;
    }

    // 3) REMOTE_ADDR (souvent IP du proxy si derrière un LB)
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) {
        return $remote;
    }

    return 'UNKNOWN';
}

/**
 * Enregistre une action dans l'historique.
 *
 * @param PDO        $pdo     Connexion PDO
 * @param int|null   $userId  ID utilisateur (null pour action système)
 * @param string     $action  Code court (ex: "connexion_reussie", "deconnexion")
 * @param string     $details Détails libres
 * @return bool
 */
function enregistrerAction(PDO $pdo, ?int $userId, string $action, string $details = ''): bool
{
    // (Optionnel) Adapter ces longueurs selon le schéma de ta table
    // Exemple: action VARCHAR(64), details TEXT (ici on limite à 2000 pour éviter les surprises)
    $action  = mb_substr($action, 0, 64);
    $details = mb_substr($details, 0, 2000);

    $ipAddress = getClientIp();

    $sql = "INSERT INTO historique (user_id, action, details, ip_address, date_action)
            VALUES (:user_id, :action, :details, :ip_address, NOW())";

    try {
        $stmt = $pdo->prepare($sql);

        // user_id peut être NULL → lier avec le bon type
        if ($userId === null) {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }

        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':details', $details, PDO::PARAM_STR);
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);

        return $stmt->execute();
    } catch (\PDOException $e) {
        // Log silencieux pour ne pas casser le flux applicatif
        error_log("Erreur d'historique: " . $e->getMessage());
        return false;
    }
}
