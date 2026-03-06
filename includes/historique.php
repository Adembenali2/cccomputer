<?php
declare(strict_types=1);

/**
 * includes/historique.php
 * Système d'audit centralisé réutilisable dans tout le projet
 *
 * Fonctions : getClientIp, enregistrerAction, getActionCategory, formatActionLabel,
 * sanitizeAuditDetails, auditEntityChange
 */

// ====== MAPPING CENTRALISÉ ======

/** Patterns action → catégorie (ordre important : plus spécifique en premier) */
const AUDIT_CATEGORY_PATTERNS = [
    'Clients' => ['client%', 'photocopieur%'],
    'SAV' => ['sav%'],
    'Livraisons' => ['livraison%'],
    'Stock' => ['mouvement_stock%', 'stock%'],
    'Messagerie' => ['message%'],
    'Factures' => ['facture%'],
    'Paiements' => ['paiement%'],
    'Profil' => ['profil%', 'statut_utilisateur%', 'mot_de_passe%'],
    'Authentification' => ['connexion%', 'deconnexion%', 'login%'],
    'Agenda' => ['rdv%'],
    'Système' => ['system%', 'import%'],
];

/** Labels lisibles pour les actions courantes */
const AUDIT_ACTION_LABELS = [
    'connexion_reussie' => 'Connexion réussie',
    'connexion_echouee' => 'Connexion échouée',
    'deconnexion' => 'Déconnexion',
    'client_ajoute' => 'Client ajouté',
    'client_modifie' => 'Client modifié',
    'client_mis_a_jour' => 'Client mis à jour',
    'client_supprime' => 'Client supprimé',
    'photocopieur_attribue' => 'Photocopieur attribué',
    'sav_cree' => 'SAV créé',
    'sav_modifie' => 'SAV modifié',
    'sav_cloture' => 'SAV clôturé',
    'livraison_creee' => 'Livraison créée',
    'livraison_modifiee' => 'Livraison modifiée',
    'facture_generee' => 'Facture générée',
    'facture_modifiee' => 'Facture modifiée',
    'facture_envoyee' => 'Facture envoyée',
    'paiement_enregistre' => 'Paiement enregistré',
    'paiement_modifie' => 'Paiement modifié',
    'paiement_supprime' => 'Paiement supprimé',
    'message_envoye' => 'Message envoyé',
    'message_repondu' => 'Message répondu',
    'message_supprime' => 'Message supprimé',
    'profil_modifie' => 'Profil modifié',
    'statut_utilisateur_modifie' => 'Statut utilisateur modifié',
    'mot_de_passe_modifie' => 'Mot de passe modifié',
    'rdv_cree' => 'RDV créé',
    'rdv_modifie' => 'RDV modifié',
    'rdv_supprime' => 'RDV supprimé',
];

/** Classes CSS des badges par catégorie */
const AUDIT_BADGE_CLASSES = [
    'Clients' => 'badge-clients',
    'SAV' => 'badge-sav',
    'Livraisons' => 'badge-livraisons',
    'Stock' => 'badge-stock',
    'Messagerie' => 'badge-messagerie',
    'Factures' => 'badge-factures',
    'Paiements' => 'badge-paiements',
    'Profil' => 'badge-profil',
    'Authentification' => 'badge-auth',
    'Agenda' => 'badge-agenda',
    'Système' => 'badge-system',
    'Autre' => 'badge-other',
];

const AUDIT_DETAILS_MAX_LENGTH = 2000;
const AUDIT_ACTION_MAX_LENGTH = 64;

// ====== FONCTIONS ======

/**
 * Détecte la meilleure IP client derrière un proxy (Railway).
 */
function getClientIp(): string
{
    $isPublicIp = function (string $ip): bool {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    };

    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if ($xff !== '') {
        foreach (explode(',', $xff) as $raw) {
            $ip = trim($raw);
            if ($isPublicIp($ip)) return $ip;
        }
    }

    $xri = $_SERVER['HTTP_X_REAL_IP'] ?? '';
    if ($xri !== '' && filter_var($xri, FILTER_VALIDATE_IP)) return $xri;

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if ($remote !== '' && filter_var($remote, FILTER_VALIDATE_IP)) return $remote;

    return 'UNKNOWN';
}

/**
 * Retourne la catégorie d'une action à partir de son code.
 */
function getActionCategory(?string $action): string
{
    if (!$action) return 'Autre';
    foreach (AUDIT_CATEGORY_PATTERNS as $cat => $patterns) {
        foreach ($patterns as $p) {
            $prefix = rtrim($p, '%');
            if ($prefix !== '' && stripos($action, $prefix) === 0) return $cat;
        }
    }
    return 'Autre';
}

/**
 * Retourne le label lisible d'une action.
 */
function formatActionLabel(?string $action): string
{
    if (!$action) return '—';
    return AUDIT_ACTION_LABELS[$action] ?? str_replace('_', ' ', $action);
}

/**
 * Retourne la classe CSS du badge pour une catégorie.
 */
function getBadgeClassForCategory(string $category): string
{
    return AUDIT_BADGE_CLASSES[$category] ?? 'badge-other';
}

/**
 * Nettoie et tronque les détails d'audit.
 * Ne jamais enregistrer : mot de passe, token, données sensibles.
 */
function sanitizeAuditDetails(string $details): string
{
    $details = trim($details);
    if ($details === '') return '';
    $forbidden = ['password', 'mot_de_passe', 'token', 'csrf', 'secret', 'cle_api'];
    $lower = strtolower($details);
    foreach ($forbidden as $f) {
        if (str_contains($lower, $f)) {
            $details = preg_replace('/' . preg_quote($f, '/') . '[^,\s]*[=:]\s*[^\s,]+/iu', $f . '=***', $details);
        }
    }
    return mb_substr(trim(preg_replace('/\s+/', ' ', $details)), 0, AUDIT_DETAILS_MAX_LENGTH);
}

/**
 * Enregistre une action dans l'historique (audit).
 *
 * @param PDO        $pdo     Connexion PDO
 * @param int|null   $userId  ID utilisateur (null pour action système)
 * @param string     $action  Code court (ex: "connexion_reussie", "client_ajoute")
 * @param string     $details Détails libres (sera nettoyé via sanitizeAuditDetails)
 * @return bool
 */
function enregistrerAction(PDO $pdo, ?int $userId, string $action, string $details = ''): bool
{
    $action = mb_substr($action, 0, AUDIT_ACTION_MAX_LENGTH);
    $details = sanitizeAuditDetails($details);
    $ipAddress = getClientIp();

    $sql = "INSERT INTO historique (user_id, action, details, ip_address, date_action)
            VALUES (:user_id, :action, :details, :ip_address, NOW())";

    try {
        $stmt = $pdo->prepare($sql);
        if ($userId === null) {
            $stmt->bindValue(':user_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':action', $action, PDO::PARAM_STR);
        $stmt->bindValue(':details', $details, PDO::PARAM_STR);
        $stmt->bindValue(':ip_address', $ipAddress, PDO::PARAM_STR);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Erreur d'historique: " . $e->getMessage());
        return false;
    }
}

/**
 * Helper pour enregistrer un changement d'entité (ancienne/nouvelle valeur).
 * Évite de stocker des données sensibles.
 *
 * @param PDO    $pdo        Connexion PDO
 * @param int|null $userId   ID utilisateur
 * @param string $action     Code action (ex: client_modifie)
 * @param string $entityType Type d'entité (ex: "Client", "SAV")
 * @param int|null $entityId ID de l'entité
 * @param string $summary    Résumé court du changement (ex: "Statut: ouvert → clôturé")
 */
function auditEntityChange(
    PDO $pdo,
    ?int $userId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    string $summary = ''
): bool {
    $parts = [$entityType];
    if ($entityId !== null) $parts[] = "#{$entityId}";
    if ($summary !== '') $parts[] = $summary;
    $details = implode(' - ', $parts);
    return enregistrerAction($pdo, $userId, $action, $details);
}
