# Image mirror (pas d’auth Docker Hub nécessaire)
FROM mirror.gcr.io/library/php:8.3-apache

# Libs système + Composer
RUN apt-get update \
 && apt-get install -y git unzip curl \
 && rm -rf /var/lib/apt/lists/* \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo_mysql mysqli

# Activer mod_rewrite (utile pour routes propres)
RUN a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# On travaille dans /var/www/html
WORKDIR /var/www/html

# 1) Copier les manifests Composer en premier (cache build)
COPY composer.json composer.lock* ./

# 2) Installer les dépendances (génère /var/www/html/vendor)
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction

# 3) Copier le reste du code (y compris API/, docker-entrypoint.sh, etc.)
COPY . /var/www/html

# Entrypoint qui adapte le port Apache à $PORT (exigé par Railway pour le service web)
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
