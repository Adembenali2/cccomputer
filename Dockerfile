# Étape 1: On part d'une image officielle PHP avec le serveur Apache pré-installé
FROM php:8.2-apache

# Étape 2: On installe les extensions PHP nécessaires pour la base de données
RUN docker-php-ext-install pdo pdo_mysql

# Étape 3 (NOUVELLE LIGNE) : On active le module Apache 'rewrite'
RUN a2enmod rewrite

# Étape 4: On copie tous les fichiers de notre projet dans le dossier public du serveur
COPY . /var/www/html/

# Étape 5: On crée le dossier des sessions et on donne les permissions à Apache
RUN mkdir -p /var/lib/php/sessions && chown -R www-data:www-data /var/lib/php/sessions

# Étape 6: On donne la permission au serveur Apache de lire et écrire les fichiers de l'application
RUN chown -R www-data:www-data /var/www/html