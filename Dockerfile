FROM php:8.2-apache

# Extensions PHP
RUN docker-php-ext-install pdo pdo_mysql

# Module rewrite
RUN a2enmod rewrite

# Définir un ServerName global pour supprimer la notice
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Copier config Apache (vhost)
COPY 000-default.conf /etc/apache2/sites-available/000-default.conf

# Copier config PHP (afficher/loguer les erreurs en dev)
COPY custom.ini /usr/local/etc/php/conf.d/custom.ini

# Copier l'application
COPY . /var/www/html/

# Préparer dossiers sessions & logs PHP
RUN mkdir -p /var/lib/php/sessions \
    && mkdir -p /var/log/php \
    && chown -R www-data:www-data /var/lib/php/sessions /var/www/html /var/log/php

# Si la plateforme (ex: Railway) fournit PORT, on adapte ports.conf et vhost au démarrage
ENV APACHE_RUN_PORT=80
CMD ["bash", "-lc", "if [ -n \"$PORT\" ]; then sed -i \"s/Listen 80/Listen $PORT/g\" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf; fi && exec apache2-foreground"]
