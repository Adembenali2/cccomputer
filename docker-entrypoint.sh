#!/usr/bin/env sh
set -e

# Adapter Apache au port Railway si défini
if [ -n "$PORT" ]; then
  echo "Listen $PORT" > /etc/apache2/ports.conf
fi

exec "$@"
