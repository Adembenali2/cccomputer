<?php
declare(strict_types=1);

/**
 * Helper pour la gestion du cache (APCu ou fallback fichier)
 * Remplace les caches basés sur fichiers pour de meilleures performances
 */
class CacheHelper
{
    /**
     * Récupère une valeur du cache
     * 
     * @param string $key Clé du cache
     * @param mixed $default Valeur par défaut si non trouvée
     * @return mixed Valeur en cache ou valeur par défaut
     */
    public static function get(string $key, $default = null)
    {
        // Priorité 1: APCu (plus performant, partagé entre processus)
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($key);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // Fallback: Cache fichier
        $cacheFile = self::getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['value'], $data['expires'])) {
                    if ($data['expires'] > time()) {
                        return $data['value'];
                    }
                    // Expiré, supprimer le fichier
                    @unlink($cacheFile);
                }
            }
        }
        
        return $default;
    }
    
    /**
     * Stocke une valeur dans le cache
     * 
     * @param string $key Clé du cache
     * @param mixed $value Valeur à stocker
     * @param int $ttl Durée de vie en secondes (défaut: 300)
     * @return bool true si succès, false sinon
     */
    public static function set(string $key, $value, int $ttl = 300): bool
    {
        // Priorité 1: APCu
        if (function_exists('apcu_store')) {
            return apcu_store($key, $value, $ttl);
        }
        
        // Fallback: Cache fichier
        $cacheFile = self::getCacheFilePath($key);
        $cacheDir = dirname($cacheFile);
        
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        $data = [
            'value' => $value,
            'expires' => time() + $ttl
        ];
        
        return @file_put_contents($cacheFile, json_encode($data), LOCK_EX) !== false;
    }
    
    /**
     * Supprime une valeur du cache
     * 
     * @param string $key Clé du cache
     * @return bool true si succès, false sinon
     */
    public static function delete(string $key): bool
    {
        $deleted = false;
        
        if (function_exists('apcu_delete')) {
            $deleted = apcu_delete($key) || $deleted;
        }
        
        $cacheFile = self::getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            $deleted = @unlink($cacheFile) || $deleted;
        }
        
        return $deleted;
    }
    
    /**
     * Génère le chemin du fichier de cache
     * 
     * @param string $key Clé du cache
     * @return string Chemin du fichier
     */
    private static function getCacheFilePath(string $key): string
    {
        $cacheDir = __DIR__ . '/../cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }
        
        return $cacheDir . '/' . md5($key) . '.json';
    }
}

