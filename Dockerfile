# Image mirror (pas d’auth Docker Hub nécessaire)
FROM mirror.gcr.io/library/php:8.3-apache

# Extensions PHP nécessaires
RUN docker-php-ext-install pdo_mysql mysqli

# Activer mod_rewrite + permettre .htaccess
RUN a2enmod rewrite \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# php.ini de prod + opcache
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && docker-php-ext-enable opcache \
 && { \
      echo "opcache.enable=1"; \
      echo "opcache.enable_cli=1"; \
      echo "opcache.validate_timestamps=0"; \
      echo "opcache.max_accelerated_files=32768"; \
      echo "opcache.memory_consumption=128"; \
      echo "opcache.interned_strings_buffer=16"; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Un peu de durcissement Apache
RUN { \
      echo 'ServerTokens Prod'; \
      echo 'ServerSignature Off'; \
    } > /etc/apache2/conf-available/hardening.conf \
 && a2enconf hardening

# Code de l’app
WORKDIR /var/www/html
COPY . /var/www/html

# Entrypoint qui adapte le port Apache à $PORT ET règle le ServerName
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
