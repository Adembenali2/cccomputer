<?php
// includes/helpers.php
// Fonctions helper communes pour tout le site

/**
 * Échappe les données pour éviter les attaques XSS
 * Utilisé dans tous les templates PHP
 */
if (!function_exists('h')) {
    function h(?string $s): string {
        return htmlspecialchars((string)$s ?? '', ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Valide et nettoie un email
 * Retourne l'email nettoyé si valide, sinon lance une exception
 */
if (!function_exists('validateEmail')) {
    function validateEmail(string $email): string {
        $email = trim($email);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Email invalide');
        }
        return $email;
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
 * Formate une date pour l'affichage
 */
if (!function_exists('formatDate')) {
    function formatDate(?string $date, string $format = 'd/m/Y'): string {
        if (!$date) {
            return '—';
        }
        try {
            $dt = new DateTime($date);
            return $dt->format($format);
        } catch (Exception $e) {
            return $date;
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
 */
if (!function_exists('currentUserId')) {
    function currentUserId(): ?int {
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
 * Vérifie le token CSRF et lance une exception si invalide
 */
if (!function_exists('assertValidCsrf')) {
    function assertValidCsrf(string $token): void {
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
 */
if (!function_exists('validatePostalCode')) {
    function validatePostalCode(string $postal): bool {
        $pattern = '/^[0-9]{4,10}$/';
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

