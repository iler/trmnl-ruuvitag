#!/bin/sh
set -e
cd /var/www/html

# Only generate if no key is supplied via env or .env
if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi

php artisan migrate --force
php artisan config:cache
php artisan route:cache
