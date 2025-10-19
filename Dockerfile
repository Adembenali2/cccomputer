# Image FrankenPHP officielle
FROM dunglas/frankenphp:1-php8.4

# Ajoute les extensions nécessaires à MySQL
RUN install-php-extensions pdo_mysql mysqli

# (optionnel) si tu utilises intl, zip, gd, etc. : RUN install-php-extensions intl zip gd

WORKDIR /app
COPY . /app

# Si tu as un Caddyfile personnalisé, copie-le :
# COPY Caddyfile /Caddyfile

ENV SERVER_NAME=:8080
