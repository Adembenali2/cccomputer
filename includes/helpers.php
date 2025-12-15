<?php
declare(strict_types=1);

/**
 * includes/helpers.php
 * Fonctions helper communes pour tout le site
 * 
 * Ce fichier contient les fonctions utilitaires utilisées dans tout le projet :
 * - Échappement HTML (XSS protection)
 * - Validation de données (email, téléphone, SIRET, etc.)
 * - Formatage de dates
 * - Gestion CSRF
 * - Requêtes SQL sécurisées
 * - Helpers de session
 */

/**
 * Échappe les données pour éviter les attaques XSS
 * Utilisé dans tous les templates PHP
 * 
 * @param string|null $s Chaîne à échapper
 * @return string Chaîne échappée
 */
if (!function_exists('h')) {
    function h(?string $s): string
    {
        return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Valide et nettoie un email
 * Retourne l'email nettoyé si valide, sinon lance une exception
 * Utilise la classe Validator centralisée
 */
if (!function_exists('validateEmail')) {
    function validateEmail(string $email): string {
        if (!class_exists('Validator')) {
            require_once __DIR__ . '/Validator.php';
        }
        return Validator::email($email);
    }
}

/**
 * Valide un email (version bool pour compatibilité)
 * Utilisée dans clients.php pour validation simple
 */
if (!function_exists('validateEmailBool')) {
    function validateEmailBool(string $email): bool {
        return (bool)filter_var(trim($email), FILTER_VALIDATE_EMAIL);
    }
}

/**
 * Valide un ID numérique
 */
if (!function_exists('validateId')) {
    function validateId($id, string $name = 'ID'): int {
        $id = (int)$id;
        if ($id <= 0) {
            throw new InvalidArgumentException("{$name} invalide");
        }
        return $id;
    }
}

/**
 * Valide une chaîne avec longueur min/max
 */
if (!function_exists('validateString')) {
    function validateString(string $value, string $name, int $minLength = 1, int $maxLength = 1000): string {
        $value = trim($value);
        if (strlen($value) < $minLength) {
            throw new InvalidArgumentException("{$name} trop court (min {$minLength} caractères)");
        }
        if (strlen($value) > $maxLength) {
            throw new InvalidArgumentException("{$name} trop long (max {$maxLength} caractères)");
        }
        return $value;
    }
}

/**
 * Formate une date pour l'affichage (version robuste avec gestion d'erreurs)
 */
if (!function_exists('formatDate')) {
    function formatDate(?string $date, string $format = 'd/m/Y'): string {
        if (!$date || trim($date) === '') {
            return '—';
        }
        try {
            $dt = new DateTime($date);
            return $dt->format($format);
        } catch (Exception $e) {
            error_log('formatDate error: ' . $e->getMessage() . ' | Date: ' . $date);
            return '—';
        }
    }
}

/**
 * Formate une date avec heure pour l'affichage
 */
if (!function_exists('formatDateTime')) {
    function formatDateTime(?string $date, string $format = 'd/m/Y H:i'): string {
        if (!$date || trim($date) === '') {
            return '—';
        }
        try {
            $dt = new DateTime($date);
            return $dt->format($format);
        } catch (Exception $e) {
            error_log('formatDateTime error: ' . $e->getMessage() . ' | Date: ' . $date);
            return '—';
        }
    }
}

/**
 * Génère un token CSRF si manquant
 */
if (!function_exists('ensureCsrfToken')) {
    function ensureCsrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

/**
 * Vérifie le token CSRF
 */
if (!function_exists('verifyCsrfToken')) {
    function verifyCsrfToken(?string $token = null): bool {
        $token = $token ?? ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        return !empty($token) && !empty($sessionToken) && hash_equals($sessionToken, $token);
    }
}

/**
 * Requête SQL sécurisée avec gestion d'erreurs
 */
if (!function_exists('safeFetchAll')) {
    function safeFetchAll(PDO $pdo, string $sql, array $params = [], string $context = 'query'): array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return is_array($rows) ? $rows : [];
        } catch (PDOException $e) {
            error_log("Erreur SQL ({$context}) : " . $e->getMessage());
            return [];
        }
    }
}

/**
 * Requête SQL sécurisée pour une seule ligne
 */
if (!function_exists('safeFetch')) {
    function safeFetch(PDO $pdo, string $sql, array $params = [], string $context = 'query'): ?array {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false ? $row : null;
        } catch (PDOException $e) {
            error_log("Erreur SQL ({$context}) : " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Requête SQL sécurisée pour une valeur unique
 */
if (!function_exists('safeFetchColumn')) {
    function safeFetchColumn(PDO $pdo, string $sql, array $params = [], $default = null, string $context = 'query') {
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchColumn() ?: $default;
        } catch (PDOException $e) {
            error_log("Erreur SQL ({$context}) : " . $e->getMessage());
            return $default;
        }
    }
}

/**
 * Récupère l'ID de l'utilisateur actuel depuis la session
 * 
 * @return int|null ID de l'utilisateur ou null si non connecté
 */
if (!function_exists('currentUserId')) {
    function currentUserId(): ?int
    {
        if (isset($_SESSION['user']['id'])) {
            return (int)$_SESSION['user']['id'];
        }
        if (isset($_SESSION['user_id'])) {
            return (int)$_SESSION['user_id'];
        }
        return null;
    }
}

/**
 * Récupère le rôle de l'utilisateur actuel depuis la session
 * 
 * @return string|null Rôle de l'utilisateur ou null si non connecté
 */
if (!function_exists('currentUserRole')) {
    function currentUserRole(): ?string
    {
        if (isset($_SESSION['emploi'])) {
            return $_SESSION['emploi'];
        }
        if (isset($_SESSION['user']['Emploi'])) {
            return $_SESSION['user']['Emploi'];
        }
        if (isset($_SESSION['user']['emploi'])) {
            return $_SESSION['user']['emploi'];
        }
        return null;
    }
}

/**
 * Vérifie le token CSRF et lance une exception si invalide
 * 
 * @param string $token Token CSRF à vérifier
 * @return void
 * @throws RuntimeException Si le token est invalide
 */
if (!function_exists('assertValidCsrf')) {
    function assertValidCsrf(string $token): void
    {
        if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            throw new RuntimeException("Session expirée. Veuillez recharger la page.");
        }
    }
}

/**
 * Valide un numéro de téléphone (optionnel)
 */
if (!function_exists('validatePhone')) {
    function validatePhone(?string $phone): bool {
        if ($phone === null || $phone === '') {
            return true; // Optionnel
        }
        $pattern = '/^[0-9+\-.\s]{6,}$/';
        return (bool)preg_match($pattern, $phone);
    }
}

/**
 * Valide un code postal
 * Accepte les codes postaux avec lettres, chiffres, tirets et espaces (pour gérer les formats internationaux)
 */
if (!function_exists('validatePostalCode')) {
    function validatePostalCode(string $postal): bool {
        if (empty($postal)) {
            return false;
        }
        $pattern = '/^[0-9A-Za-z\-\s]{4,10}$/';
        return (bool)preg_match($pattern, $postal);
    }
}

/**
 * Valide un numéro SIRET
 */
if (!function_exists('validateSiret')) {
    function validateSiret(string $siret): bool {
        $pattern = '/^[0-9]{14}$/';
        return (bool)preg_match($pattern, $siret);
    }
}

/**
 * Formate un pourcentage ou retourne "—"
 */
if (!function_exists('pctOrDash')) {
    function pctOrDash($v): string {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return '—';
        }
        $v = max(0, min(100, (int)$v));
        return $v . '%';
    }
}

/**
 * Récupère une valeur POST avec fallback (pour les formulaires)
 */
if (!function_exists('old')) {
    function old(string $key, string $default = ''): string {
        return htmlspecialchars($_POST[$key] ?? $default, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Génère un badge HTML pour l'état d'un produit (A, B, C)
 * Utilisé dans stock.php pour afficher l'état des LCD et PC
 */
if (!function_exists('stateBadge')) {
    function stateBadge(?string $etat): string {
        $e = strtoupper(trim((string)$etat));
        if (!in_array($e, ['A', 'B', 'C'], true)) {
            return '<span class="state state-na">—</span>';
        }
        return '<span class="state state-' . h($e) . '">' . h($e) . '</span>';
    }
}

/**
 * Normalise une adresse MAC au format 12 hex sans séparateurs
 * Retourne un tableau avec 'norm' (12 hex) et 'colon' (format avec ':')
 * Si la MAC n'est pas valide, retourne ['norm' => null, 'colon' => null]
 * 
 * @param string|null $mac Adresse MAC brute (peut contenir des séparateurs)
 * @return array ['norm' => string|null, 'colon' => string|null]
 */
if (!function_exists('normalizeMac')) {
    function normalizeMac(?string $mac): array {
        if (empty($mac)) {
            return ['norm' => null, 'colon' => null];
        }
        $raw = strtoupper(trim((string)$mac));
        $hex = preg_replace('~[^0-9A-F]~', '', $raw);
        if (strlen($hex) !== 12) {
            return ['norm' => null, 'colon' => null];
        }
        return [
            'norm' => $hex,
            'colon' => implode(':', str_split($hex, 2))
        ];
    }
}

/**
 * Normalise une adresse MAC pour utilisation dans les URLs
 * Retourne la version normalisée (12 hex) ou null si invalide
 * 
 * @param string|null $mac Adresse MAC brute
 * @return string|null MAC normalisée (12 hex) ou null
 */
if (!function_exists('normalizeMacForUrl')) {
    function normalizeMacForUrl(?string $mac): ?string {
        $result = normalizeMac($mac);
        return $result['norm'];
    }
}

/**
 * Configure les paramètres d'erreurs PHP de manière sécurisée
 * Respecte l'environnement (production vs développement)
 * 
 * @param bool $forceDev Si true, force le mode développement (pour scripts de debug)
 * @return void
 */
if (!function_exists('configureErrorReporting')) {
    function configureErrorReporting(bool $forceDev = false): void {
        $isDevelopment = $forceDev || (getenv('APP_ENV') ?: 'production') === 'development';
        
        // Toujours activer le reporting complet pour les logs
        error_reporting(E_ALL);
        
        // En production : masquer les erreurs à l'utilisateur, les logger uniquement
        // En développement : afficher les erreurs pour faciliter le debug
        ini_set('display_errors', $isDevelopment ? '1' : '0');
        ini_set('html_errors', '0'); // Pas d'HTML dans les erreurs (sécurité)
        ini_set('log_errors', '1');  // Toujours logger les erreurs
    }
}

/**
 * Récupère l'instance PDO unique (source de vérité)
 * Utilise DatabaseConnection::getInstance() pour une gestion unifiée
 * 
 * @return PDO Instance PDO unique
 * @throws RuntimeException Si la connexion PDO n'est pas disponible
 */
if (!function_exists('getPdo')) {
    function getPdo(): PDO {
        // Charger DatabaseConnection depuis son fichier isolé (évite la dépendance vers api_helpers.php)
        if (!class_exists('DatabaseConnection')) {
            require_once __DIR__ . '/db_connection.php';
        }
        
        // Utiliser DatabaseConnection comme source de vérité unique
        return DatabaseConnection::getInstance();
    }
}

