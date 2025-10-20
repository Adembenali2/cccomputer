# Image mirror (pas d’auth Docker Hub nécessaire)
FROM mirror.gcr.io/library/php:8.3-apache

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo_mysql mysqli

# Activer mod_rewrite (utile pour routes propres)
RUN a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Code de l’app
WORKDIR /var/www/html
COPY . /var/www/html

# Entrypoint qui adapte le port Apache à $PORT (exigé par Railway)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
