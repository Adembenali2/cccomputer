# Image mirror (pas d’auth Docker Hub nécessaire)
FROM mirror.gcr.io/library/php:8.3-apache

# Libs système + Composer
RUN apt-get update \
 && apt-get install -y git unzip curl \
 && rm -rf /var/lib/apt/lists/* \
 && curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin --filename=composer

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo_mysql mysqli \
 && apt-get update \
 && apt-get install -y libpng-dev libjpeg-dev libfreetype6-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) gd \
 && apt-get clean \
 && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite (utile pour routes propres)
RUN a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# On travaille dans /var/www/html
WORKDIR /var/www/html

# 1) Copier les manifests Composer en premier (cache build)
COPY composer.json composer.lock* ./

# 2) Installer les dépendances (génère /var/www/html/vendor)
# Utiliser update si le lock file n'est pas à jour (plus flexible pour Railway)
RUN COMPOSER_ALLOW_SUPERUSER=1 composer update --no-dev --prefer-dist --no-progress --no-interaction --no-scripts || \
    COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --prefer-dist --no-progress --no-interaction

# 3) Copier le reste du code (y compris API/)
COPY . /var/www/html

# Lancement par défaut de Apache (le port est adapté par Start Command dans Railway)
CMD ["apache2-foreground"]
