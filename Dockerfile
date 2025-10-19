# Étape 1: On part d'une image officielle PHP avec le serveur Apache pré-installé
FROM php:8.2-apache

# Étape 2: On installe les extensions PHP nécessaires. C'est la ligne la plus importante.
# Elle installe le "traducteur" pour PDO et pour MySQL.
RUN docker-php-ext-install pdo pdo_mysql

# Étape 3: On copie tous les fichiers de notre projet (votre code) dans le dossier public du serveur web
COPY . /var/www/html/