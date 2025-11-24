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
 */
function validateEmail(string $email): string {
    $email = trim($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Email invalide');
    }
    return $email;
}

/**
 * Valide un ID numérique
 */
function validateId($id, string $name = 'ID'): int {
    $id = (int)$id;
    if ($id <= 0) {
        throw new InvalidArgumentException("{$name} invalide");
    }
    return $id;
}

/**
 * Valide une chaîne avec longueur min/max
 */
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

/**
 * Formate une date pour l'affichage
 */
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
function verifyCsrfToken(?string $token = null): bool {
    $token = $token ?? ($_POST['csrf_token'] ?? $_GET['csrf_token'] ?? '');
    $sessionToken = $_SESSION['csrf_token'] ?? '';
    return !empty($token) && !empty($sessionToken) && hash_equals($sessionToken, $token);
}

/**
 * Requête SQL sécurisée avec gestion d'erreurs
 */
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

/**
 * Requête SQL sécurisée pour une seule ligne
 */
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

/**
 * Requête SQL sécurisée pour une valeur unique
 */
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

