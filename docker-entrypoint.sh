#!/bin/sh
set -e

# Railway fournit $PORT au runtime ; on l’applique à Apache
PORT="${PORT:-8080}"

# Remplace le port par défaut 80 par $PORT
sed -ri "s/^Listen 80/Listen ${PORT}/" /etc/apache2/ports.conf
sed -ri "s!<VirtualHost \*:80>!<VirtualHost *:${PORT}>!" /etc/apache2/sites-available/000-default.conf

exec "$@"
