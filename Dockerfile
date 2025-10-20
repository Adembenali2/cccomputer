# Image depuis Docker Hub (évite le 403 de ghcr.io)
FROM dunglas/frankenphp:1-php8.3

# Extensions PHP dont tu as besoin
RUN install-php-extensions pdo_mysql mysqli

# Dossier de travail
WORKDIR /app

# Copie du code de l’app
COPY . /app

# Copie du Caddyfile pour FrankenPHP
COPY Caddyfile /etc/frankenphp/Caddyfile

# Ne PAS fixer SERVER_NAME ici : Railway fournit $PORT au runtime
