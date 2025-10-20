FROM ghcr.io/dunglas/frankenphp:1-php8.3
RUN install-php-extensions pdo_mysql mysqli
WORKDIR /app
COPY . /app
COPY Caddyfile /etc/frankenphp/Caddyfile
ENV SERVER_NAME=:8080
