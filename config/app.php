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
    'email' => [
        'smtp_enabled' => (bool)($_ENV['SMTP_ENABLED'] ?? false),
        'smtp_host' => $_ENV['SMTP_HOST'] ?? 'smtp.gmail.com',
        'smtp_port' => (int)($_ENV['SMTP_PORT'] ?? 587),
        'smtp_secure' => $_ENV['SMTP_SECURE'] ?? 'tls', // 'tls' ou 'ssl'
        'smtp_username' => $_ENV['SMTP_USERNAME'] ?? '',
        'smtp_password' => $_ENV['SMTP_PASSWORD'] ?? '',
        'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? 'noreply@cccomputer.fr',
        'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'CC Computer',
        'reply_to_email' => $_ENV['SMTP_REPLY_TO'] ?? 'noreply@cccomputer.fr',
    ],
];

