# Étape 1: On part d'une image officielle PHP avec le serveur Apache
FROM php:8.2-apache

# Étape 2: On installe les extensions PHP nécessaires
RUN docker-php-ext-install pdo pdo_mysql

# Étape 3: On active le module Apache 'rewrite'
RUN a2enmod rewrite

# Étape 4: On copie notre configuration Apache personnalisée
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Étape 5 (NOUVELLE LIGNE) : On copie notre configuration PHP personnalisée pour voir les erreurs
COPY custom.ini /usr/local/etc/php/conf.d/custom.ini

# Étape 6: On copie les fichiers de notre application
COPY . /var/www/html/

# Étape 7: On prépare le dossier des sessions
RUN mkdir -p /var/lib/php/sessions && chown -R www-data:www-data /var/lib/php/sessions

# Étape 8: On donne les permissions sur les fichiers de l'application
RUN chown -R www-data:www-data /var/www/html