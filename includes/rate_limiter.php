<?php
// includes/rate_limiter.php
// Système de rate limiting simple basé sur APCu ou fichiers

/**
 * Vérifie si une requête respecte la limite de taux
 * 
 * @param string $key Clé unique pour identifier le client (ex: IP + user_id)
 * @param int $maxRequests Nombre maximum de requêtes
 * @param int $window Fenêtre de temps en secondes
 * @return bool true si la requête est autorisée, false sinon
 */
function checkRateLimit(string $key, int $maxRequests = 60, int $window = 60): bool {
    // Nettoyer la clé pour éviter les caractères spéciaux
    $key = preg_replace('/[^a-zA-Z0-9_]/', '_', $key);
    $cacheKey = "ratelimit_{$key}";
    
    // Essayer d'utiliser APCu si disponible (plus performant)
    if (function_exists('apcu_fetch')) {
        $current = (int)apcu_fetch($cacheKey);
        if ($current >= $maxRequests) {
            return false;
        }
        apcu_inc($cacheKey, 1, $window);
        return true;
    }
    
    // Fallback : utiliser un cache fichier
    $cacheDir = __DIR__ . '/../cache/ratelimit';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    
    $cacheFile = $cacheDir . '/' . md5($key) . '.json';
    $now = time();
    
    // Lire le cache existant
    $data = ['count' => 0, 'reset' => $now];
    if (file_exists($cacheFile)) {
        $content = @file_get_contents($cacheFile);
        if ($content !== false) {
            $decoded = @json_decode($content, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }
    }
    
    // Vérifier si la fenêtre est expirée
    if ($now >= $data['reset']) {
        $data = ['count' => 0, 'reset' => $now + $window];
    }
    
    // Vérifier la limite
    if ($data['count'] >= $maxRequests) {
        return false;
    }
    
    // Incrémenter le compteur
    $data['count']++;
    @file_put_contents($cacheFile, json_encode($data), LOCK_EX);
    
    return true;
}

/**
 * Génère une clé de rate limiting basée sur l'IP et l'utilisateur
 * 
 * @return string Clé unique
 */
function getRateLimitKey(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userId = $_SESSION['user_id'] ?? 'anonymous';
    return "ip_{$ip}_user_{$userId}";
}

/**
 * Vérifie le rate limit et renvoie une erreur JSON si dépassé
 * 
 * @param int $maxRequests Nombre maximum de requêtes
 * @param int $window Fenêtre de temps en secondes
 * @return void Sort avec jsonResponse si la limite est dépassée
 */
function requireRateLimit(int $maxRequests = 60, int $window = 60): void {
    if (!function_exists('jsonResponse')) {
        require_once __DIR__ . '/api_helpers.php';
    }
    
    $key = getRateLimitKey();
    if (!checkRateLimit($key, $maxRequests, $window)) {
        jsonResponse([
            'ok' => false,
            'error' => 'Trop de requêtes. Veuillez patienter avant de réessayer.'
        ], 429);
    }
}

