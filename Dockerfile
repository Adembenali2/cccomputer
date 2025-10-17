# Utilise l'image de base FrankenPHP que vous voyez dans vos logs
FROM dunglas/frankenphp:latest

# --- Les lignes cruciales pour votre erreur ---
# 1. Mise à jour des paquets et installation de la librairie MySQL/MariaDB nécessaire
#    (libmariadb-dev ou mariadb-client, la dernière est souvent suffisante et plus simple)
# 2. Installation de l'extension pdo_mysql pour PHP
RUN apt-get update && apt-get install -y \
    mariadb-client \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo pdo_mysql
# ---------------------------------------------

# Copie tout votre code PHP dans le répertoire de l'application
COPY . /app