<?php
declare(strict_types=1);

/**
 * Helper pour la gestion du cache (APCu ou fallback fichier)
 * Pattern remember, clés par tags, invalidation groupée
 */
class CacheHelper
{
    private const TAG_INDEX_PREFIX = 'CacheHelper:tagIdx:';

    /**
     * Préfixe des fichiers d’index de tags (hors md5 des clés de données)
     */
    private static function getTagIndexFilePath(string $tag): string
    {
        $cacheDir = __DIR__ . '/../cache';
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        return $cacheDir . '/tagidx_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $tag) . '.json';
    }

    /**
     * Indique si une clé est présente et non expirée (fichier)
     */
    public static function has(string $key): bool
    {
        if (function_exists('apcu_exists') && apcu_exists($key)) {
            return true;
        }

        $cacheFile = self::getCacheFilePath($key);
        if (!file_exists($cacheFile)) {
            return false;
        }
        $content = @file_get_contents($cacheFile);
        if ($content === false) {
            return false;
        }
        $data = @json_decode($content, true);
        if (!is_array($data) || !isset($data['expires'])) {
            return false;
        }

        return (int) $data['expires'] > time();
    }

    /**
     * Cache-aside : retourne la valeur en cache ou exécute le callback.
     *
     * @template T
     * @param callable(): T $callback
     * @param list<string>|null $registerTags Tags pour invalidateTag()
     * @return T
     */
    public static function remember(string $key, callable $callback, int $ttl = 300, ?array $registerTags = null)
    {
        if (self::has($key)) {
            return self::get($key);
        }

        $value = $callback();
        self::set($key, $value, $ttl, $registerTags);

        return $value;
    }

    /**
     * Clé composite pour regroupement / invalidation (ex. clients:stats:dashboard_count)
     *
     * @param list<string> $tags
     */
    public static function tags(array $tags, string $key): string
    {
        $parts = [];
        foreach ($tags as $t) {
            $s = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $t);
            $parts[] = $s !== '' ? $s : 'x';
        }

        return implode(':', $parts) . ':' . $key;
    }

    /**
     * Invalide toutes les clés enregistrées sous ce tag (index APCu + fichier).
     */
    public static function invalidateTag(string $tag): void
    {
        $tag = preg_replace('/[^a-zA-Z0-9_-]/', '', $tag);
        if ($tag === '') {
            return;
        }

        $idxKey = self::TAG_INDEX_PREFIX . $tag;
        $keys = self::get($idxKey, []);
        if (is_array($keys)) {
            foreach ($keys as $k) {
                if (is_string($k) && $k !== '') {
                    self::delete($k);
                }
            }
        }

        self::delete($idxKey);

        $tagFile = self::getTagIndexFilePath($tag);
        if (file_exists($tagFile)) {
            $raw = @file_get_contents($tagFile);
            if ($raw !== false) {
                $list = @json_decode($raw, true);
                if (is_array($list)) {
                    foreach ($list as $k) {
                        if (is_string($k) && $k !== '') {
                            self::delete($k);
                        }
                    }
                }
            }
            @unlink($tagFile);
        }
    }

    /**
     * @param list<string>|null $registerTags
     */
    private static function registerKeysForTags(string $key, ?array $registerTags): void
    {
        if ($registerTags === null || $registerTags === []) {
            return;
        }

        foreach ($registerTags as $tag) {
            $t = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $tag);
            if ($t === '') {
                continue;
            }

            $idxKey = self::TAG_INDEX_PREFIX . $t;
            $keys = self::get($idxKey, []);
            if (!is_array($keys)) {
                $keys = [];
            }
            if (!in_array($key, $keys, true)) {
                $keys[] = $key;
            }
            self::setWithoutTagRegistration($idxKey, $keys, 86400 * 365);

            $tagFile = self::getTagIndexFilePath($t);
            @file_put_contents($tagFile, json_encode($keys), LOCK_EX);
        }
    }

    /**
     * @param mixed $value
     */
    private static function setWithoutTagRegistration(string $key, $value, int $ttl): bool
    {
        if (function_exists('apcu_store') && apcu_store($key, $value, $ttl)) {
            return true;
        }

        return self::setFile($key, $value, $ttl);
    }

    /**
     * Récupère une valeur du cache
     *
     * @param mixed $default Valeur par défaut si non trouvée
     * @return mixed Valeur en cache ou valeur par défaut
     */
    public static function get(string $key, $default = null)
    {
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch($key);
            if ($cached !== false) {
                return $cached;
            }
        }

        $cacheFile = self::getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            $content = @file_get_contents($cacheFile);
            if ($content !== false) {
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['expires'])) {
                    if ((int) $data['expires'] > time()) {
                        if (!empty($data['ser'])) {
                            $raw = base64_decode((string) ($data['value'] ?? ''), true);
                            if ($raw === false) {
                                return $default;
                            }
                            $un = @unserialize($raw, ['allowed_classes' => true]);
                            if ($un === false && $raw !== serialize(false)) {
                                return $default;
                            }

                            return $un;
                        }

                        return $data['value'] ?? $default;
                    }
                    @unlink($cacheFile);
                }
            }
        }

        return $default;
    }

    /**
     * Stocke une valeur dans le cache
     *
     * @param mixed $value Valeur à stocker
     * @param list<string>|null $registerTags Enregistre la clé pour invalidateTag()
     * @return bool true si succès, false sinon
     */
    public static function set(string $key, $value, int $ttl = 300, ?array $registerTags = null): bool
    {
        $ok = false;
        if (function_exists('apcu_store')) {
            $ok = apcu_store($key, $value, $ttl);
        }
        if (!$ok) {
            $ok = self::setFile($key, $value, $ttl);
        }

        if ($ok && $registerTags !== null && $registerTags !== []) {
            self::registerKeysForTags($key, $registerTags);
        }

        return $ok;
    }

    /**
     * @param mixed $value
     */
    private static function setFile(string $key, $value, int $ttl): bool
    {
        $cacheFile = self::getCacheFilePath($key);
        $cacheDir = dirname($cacheFile);

        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0755, true);
        }

        $payload = [
            'value' => $value,
            'expires' => time() + $ttl,
            'ser' => false,
        ];
        $encoded = @json_encode($payload);
        if ($encoded === false) {
            $payload = [
                'value' => base64_encode(serialize($value)),
                'expires' => time() + $ttl,
                'ser' => true,
            ];
            $encoded = @json_encode($payload);
            if ($encoded === false) {
                return false;
            }
        }

        return @file_put_contents($cacheFile, $encoded, LOCK_EX) !== false;
    }

    /**
     * Supprime une valeur du cache
     */
    public static function delete(string $key): bool
    {
        $deleted = false;

        if (function_exists('apcu_delete')) {
            if (apcu_delete($key)) {
                $deleted = true;
            }
        }

        $cacheFile = self::getCacheFilePath($key);
        if (file_exists($cacheFile)) {
            $deleted = @unlink($cacheFile) || $deleted;
        }

        return $deleted;
    }

    /**
     * Vide le cache utilisateur APCu (toutes les clés du pool).
     */
    public static function clearApcuUserCache(): bool
    {
        if (!function_exists('apcu_clear_cache')) {
            return false;
        }

        return apcu_clear_cache();
    }

    /**
     * Génère le chemin du fichier de cache
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
