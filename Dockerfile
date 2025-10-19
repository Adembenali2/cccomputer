# Image FrankenPHP officielle
FROM dunglas/frankenphp:1-php8.4

# Extensions PHP nécessaires pour MySQL
RUN install-php-extensions pdo_mysql mysqli

# Dossier de travail et code
WORKDIR /app
COPY . /app

# Copier le Caddyfile du repo vers l'endroit attendu par l'image
COPY Caddyfile /Caddyfile

# Caddy/FrankenPHP écoutera sur 8080 (proxy Railway)
ENV SERVER_NAME=:8080
