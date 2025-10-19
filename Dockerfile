# Utilise une image de base officielle pour FrankenPHP avec PHP 8.3
# Vous pouvez changer la version de PHP si besoin (ex: php8.2)
FROM dunglas/frankenphp:1-php8.3

# Commande pour installer les extensions PHP nécessaires.
# Ici, on installe le pilote pour MySQL.
# Vous pouvez en ajouter d'autres sur la même ligne si besoin (ex: mysqli, gd, intl).
RUN docker-php-ext-install pdo_mysql