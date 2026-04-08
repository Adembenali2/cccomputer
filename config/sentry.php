<?php

/**
 * Configuration Sentry (optionnelle)
 * 
 * Pour activer Sentry, renseignez votre DSN dans ce fichier
 * ou via la variable d'environnement SENTRY_DSN
 * 
 * Exemple de DSN : https://xxxxx@xxxxx.ingest.sentry.io/xxxxx
 */

return [
    'dsn' => env('SENTRY_DSN', null),
    'environment' => env('APP_ENV', 'production'),
    'traces_sample_rate' => 0.1, // 10% des transactions
];

/**
 * Helper pour récupérer les variables d'environnement
 * 
 * @param string $key
 * @param mixed $default
 * @return mixed
 */
function env(string $key, $default = null)
{
    return $_ENV[$key] ?? $default;
}

