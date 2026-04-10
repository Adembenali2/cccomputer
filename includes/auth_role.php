<?php
// /includes/auth_role.php (VERSION SÉCURISÉE REDIRECTION)

// Inclure le fichier auth pour la session
require_once __DIR__ . '/auth.php'; // Vérifie que la session est démarrée

/**
 * Vérifie si l'utilisateur a l'un des rôles autorisés.
 * Si non, redirige vers la page de redirection avec un code 302.
 *
 * @param array $allowed_roles Liste des rôles autorisés
 */
function authorize_roles(array $allowed_roles) {
    // Vérification que l'emploi est bien chargé depuis la session
    global $emploi;

    // Si l'emploi est vide ou non valide, rediriger
    if (empty($emploi) || !in_array($emploi, $allowed_roles, true)) {
        // Redirection vers la page d'accès interdit
        header('Location: /redirection/acces_interdit.php', true, 302);
        exit;
    }
}

/**
 * Accès réservé aux administrateurs
 * Note: Utilise 'Admin' (valeur exacte de la base de données ENUM)
 */
function requireAdmin() {
    return authorize_roles(['Admin']);
}

/**
 * Accès réservé aux chargés relation clients et administrateurs
 * Note: 'Chargé relation clients' est la valeur exacte dans la base, pas 'Commercial'
 */
function requireCommercial() {
    return authorize_roles(['Chargé relation clients', 'commercial', 'Admin']);
}

/**
 * Vérifie si l'utilisateur a la permission d'accéder à une page spécifique.
 * 1. Vérifie si le module est activé (parametres_app)
 * 2. Utilise le système ACL (user_permissions) si une permission existe,
 * 3. Sinon utilise le système de rôles par défaut (fallback)
 *
 * @param string $page Nom de la page (ex: 'dashboard', 'historique', 'maps')
 * @param array $allowed_roles Liste des rôles autorisés par défaut (fallback)
 * @return bool True si l'utilisateur a accès, false sinon
 */
function checkPagePermission(string $page, array $allowed_roles = []): bool {
    if (!function_exists('getPdo')) {
        require_once __DIR__ . '/helpers.php';
    }
    try {
        $pdo = getPdo();
    } catch (RuntimeException $e) {
        error_log('checkPagePermission: Impossible de récupérer PDO: ' . $e->getMessage());
        return false;
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $emploi = $_SESSION['emploi'] ?? '';
    $roleSession = $_SESSION['role'] ?? '';
    $roleNormalized = function (string $v): string {
        $v = strtolower(trim($v));
        $replace = [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', ' ' => '_', '-' => '_',
        ];
        return strtr($v, $replace);
    };
    $currentRole = $roleNormalized((string)($roleSession !== '' ? $roleSession : $emploi));

    if (empty($user_id)) {
        return false;
    }

    try {
        if (!function_exists('isModuleEnabled')) {
            require_once __DIR__ . '/parametres.php';
        }
        if (!isModuleEnabled($pdo, $page)) {
            return false;
        }
        // Offre « standard » : modules business désactivés même si flag DB à 1 (ProductTier)
        $productGated = [
            'dashboard_business' => 'module_dashboard_business',
            'factures_recurrentes' => 'module_factures_recurrentes',
            'opportunites' => 'module_opportunites',
        ];
        if (isset($productGated[$page]) && is_file(__DIR__ . '/../vendor/autoload.php')) {
            require_once __DIR__ . '/../vendor/autoload.php';
            if (class_exists(\App\Services\ProductTier::class)
                && !\App\Services\ProductTier::canUseFeature($pdo, $productGated[$page])) {
                return false;
            }
        }
    } catch (Throwable $e) {
        // Table parametres_app peut ne pas exister
    }

    try {
        $stmt = $pdo->prepare("SELECT allowed FROM user_permissions WHERE user_id = ? AND page = ? LIMIT 1");
        $stmt->execute([$user_id, $page]);
        $permission = $stmt->fetchColumn();

        if ($permission !== false) {
            return (int)$permission === 1;
        }

        if (!empty($allowed_roles)) {
            $allowedNorm = array_map(static fn(string $r): string => $roleNormalized($r), $allowed_roles);
            return in_array($currentRole, $allowedNorm, true);
        }
        // Règles métier globales pour rôle commercial
        if (in_array($currentRole, ['commercial', 'charge_relation_clients'], true)) {
            if (in_array($page, ['utilisateurs', 'parametres'], true)) {
                return false;
            }
            if (in_array($page, ['clients', 'factures', 'paiements', 'sav', 'livraison', 'espace_commercial', 'historique'], true)) {
                return true;
            }
            if ($page === 'stock') {
                return true; // lecture seule à gérer côté page/API
            }
        }
        return true;
    } catch (PDOException $e) {
        error_log('Warning: user_permissions table may not exist: ' . $e->getMessage());
        if (!empty($allowed_roles)) {
            $allowedNorm = array_map(static fn(string $r): string => $roleNormalized($r), $allowed_roles);
            return in_array($currentRole, $allowedNorm, true);
        }
        if (in_array($currentRole, ['commercial', 'charge_relation_clients'], true)) {
            if (in_array($page, ['utilisateurs', 'parametres'], true)) {
                return false;
            }
            if (in_array($page, ['clients', 'factures', 'paiements', 'sav', 'livraison', 'espace_commercial', 'historique', 'stock'], true)) {
                return true;
            }
        }
        return true;
    }
}

/**
 * Vérifie l'accès à une page avec le système ACL.
 * Si l'utilisateur n'a pas accès, redirige vers la page d'accès interdit.
 *
 * @param string $page Nom de la page (ex: 'dashboard', 'historique', 'maps')
 * @param array $allowed_roles Liste des rôles autorisés par défaut (fallback)
 */
function authorize_page(string $page, array $allowed_roles = []): void {
    if (!checkPagePermission($page, $allowed_roles)) {
        header('Location: /redirection/acces_interdit.php', true, 302);
        exit;
    }
}
?>
