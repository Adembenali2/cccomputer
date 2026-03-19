<?php
declare(strict_types=1);

/**
 * Configuration centralisée de l'application
 * Remplace les valeurs magiques dispersées dans le code
 */
return [
    // Fuseau horaire MySQL (UTC par défaut sur Railway). Les dates sont converties en ISO 8601 UTC pour le frontend.
    'mysql_timezone' => $_ENV['MYSQL_TIMEZONE'] ?? 'UTC',
    // Fuseau horaire utilisateur (saisie date/heure dans l'interface)
    'app_timezone' => $_ENV['APP_TIMEZONE'] ?? 'Europe/Paris',
    'limits' => [
        'clients_per_page' => 500,
        'users_per_page' => 300,
        'historique_per_page' => 100,
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
        'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? 'facture@camsongroup.fr',
        'from_name' => $_ENV['SMTP_FROM_NAME'] ?? 'Camson Group - Facturation',
        'reply_to_email' => $_ENV['SMTP_REPLY_TO'] ?? 'facture@camsongroup.fr',
    ],
    'auto_send_invoices' => (bool)($_ENV['AUTO_SEND_INVOICES'] ?? false),
    'company' => [
        'name' => $_ENV['COMPANY_NAME'] ?? 'CC Computer',
        'address' => trim($_ENV['COMPANY_ADDRESS'] ?? '7 Rue Fraizier, 93210 Saint-Denis'),
        'billing_contact_email' => trim($_ENV['BILLING_CONTACT_EMAIL'] ?? 'facturemail@cccomputer.fr'),
        'director_full_name' => trim($_ENV['DIRECTOR_FULL_NAME'] ?? ''),
    ],
    'import' => [
        'cron_secret_token' => $_ENV['CRON_SECRET_TOKEN'] ?? getenv('CRON_SECRET_TOKEN') ?: '',
        'sftp_max_files' => (int)($_ENV['SFTP_IMPORT_MAX_FILES'] ?? getenv('SFTP_IMPORT_MAX_FILES') ?: 10),
    ],
];

