<?php
declare(strict_types=1);

/**
 * Configuration centralisée de l'application
 * Remplace les valeurs magiques dispersées dans le code
 */
return [
    'limits' => [
        'clients_per_page' => 500,
        'users_per_page' => 300,
        'historique_per_page' => 1000,
        'cache_ttl' => 300, // 5 minutes
        'roles_cache_ttl' => 3600, // 1 heure
    ],
    'upload' => [
        'max_size' => 10 * 1024 * 1024, // 10 MB
        'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png'],
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png'
        ],
    ],
    'rate_limiting' => [
        'api_max_requests' => 60,
        'api_window_seconds' => 60,
    ],
    'session' => [
        'regenerate_interval' => 900, // 15 minutes
    ],
    'search' => [
        'max_length' => 120,
        'user_search_max_chars' => 80,
    ],
];

