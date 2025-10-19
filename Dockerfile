FROM dunglas/frankenphp:1-php8.4

# Drivers MySQL
RUN install-php-extensions pdo_mysql mysqli

WORKDIR /app
COPY . /app

# ⬇️ Chemin correct d'après tes logs
COPY Caddyfile /etc/frankenphp/Caddyfile

ENV SERVER_NAME=:8080
