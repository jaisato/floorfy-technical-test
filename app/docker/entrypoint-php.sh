#!/usr/bin/env sh
set -eu

mkdir -p /var/www/html/public/videos /var/www/html/var/work

APP_UID="${APP_UID:-1000}"
APP_GID="${APP_GID:-1000}"

chown -R "$APP_UID:$APP_GID" /var/www/html/public/videos /var/www/html/var/work 2>/dev/null || true
chmod -R 775 /var/www/html/public/videos /var/www/html/var/work 2>/dev/null || true

# php-fpm en foreground (importante para contenedor)
exec php-fpm -F
