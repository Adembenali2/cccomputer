#!/bin/sh
set -e

# Railway fournit $PORT au runtime ; par défaut 8080
PORT="${PORT:-8080}"

# ServerName : si tu as un domaine, mets-le dans $SERVER_NAME via les variables Railway
SERVER_NAME="${SERVER_NAME:-localhost}"

# Fixe le ServerName proprement (supprime l’avertissement AH00558)
printf "ServerName %s\n" "$SERVER_NAME" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

# Adapte le port Apache à $PORT (idempotent)
if grep -qE '^Listen ' /etc/apache2/ports.conf; then
  sed -ri "s@^Listen .*@Listen ${PORT}@" /etc/apache2/ports.conf
else
  echo "Listen ${PORT}" >> /etc/apache2/ports.conf
fi

if grep -qE '^<VirtualHost \*:' /etc/apache2/sites-available/000-default.conf; then
  sed -ri "s@^<VirtualHost \*:[0-9]+>@<VirtualHost *:${PORT}>@" /etc/apache2/sites-available/000-default.conf
else
  sed -ri "s@^<VirtualHost \*:80>@<VirtualHost *:${PORT}>@" /etc/apache2/sites-available/000-default.conf || true
fi

exec "$@"
