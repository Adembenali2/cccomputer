# Image PHP officielle depuis Docker Hub (stable et accessible)
FROM php:8.3-apache

# Variables d'environnement pour optimiser le build
ENV DEBIAN_FRONTEND=noninteractive
ENV COMPOSER_ALLOW_SUPERUSER=1

# Mise à jour des paquets et installation de TOUTES les dépendances système nécessaires
# IMPORTANT: Installer toutes les dépendances en une seule commande pour optimiser le cache Docker
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    curl \
    # Dépendances pour GD (images)
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    # Dépendances pour ZIP
    libzip-dev \
    # Dépendances pour INTL
    libicu-dev \
    # Dépendances pour MBSTRING (oniguruma)
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# Installation de Composer
RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer

# Installation des extensions PHP nécessaires
# IMPORTANT: Installer dans l'ordre optimal pour le cache Docker

# 1. Extensions MySQL (PDO et MySQLi) - Pas de dépendances externes
RUN docker-php-ext-install pdo_mysql mysqli

# 2. Extension MBSTRING - Nécessite libonig-dev (déjà installé)
RUN docker-php-ext-install mbstring

# 3. Extension ZIP - Nécessite libzip-dev (déjà installé)
RUN docker-php-ext-install zip

# 4. Extension INTL - Nécessite libicu-dev (déjà installé)
RUN docker-php-ext-install intl

# 5. Extension GD - Nécessite libpng-dev, libjpeg-dev, libfreetype6-dev (déjà installés)
# Configuration avec freetype et jpeg
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) gd

# Nettoyage final (déjà fait dans le premier RUN, mais on s'assure)
RUN apt-get clean && rm -rf /var/lib/apt/lists/*

# Activer mod_rewrite pour Apache (routes propres)
RUN a2enmod rewrite \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Configuration Apache pour Railway/IONOS
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Définir le répertoire de travail
WORKDIR /var/www/html

# Copier les fichiers Composer en premier (pour le cache de build)
COPY composer.json composer.lock* ./

# Installer les dépendances Composer
# Utiliser install si lock existe, sinon update
RUN if [ -f composer.lock ]; then \
        composer install --no-dev --prefer-dist --no-progress --no-interaction --no-scripts; \
    else \
        composer update --no-dev --prefer-dist --no-progress --no-interaction --no-scripts || \
        composer install --no-dev --prefer-dist --no-progress --no-interaction; \
    fi

# Copier le reste du code source
COPY . /var/www/html

# Définir les permissions (si nécessaire)
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Exposer le port 80 (Railway/IONOS le mappera automatiquement)
EXPOSE 80

# Commande par défaut : démarrer Apache
CMD ["apache2-foreground"]
