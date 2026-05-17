#!/bin/sh
set -e
cd /var/www/html

# Only generate if no key is supplied via env or .env. Migration and the
# config/route/view caches are handled by serversideup's
# /etc/entrypoint.d/50-laravel-automations.sh — its AUTORUN_LARAVEL_* env
# vars decide what runs.
if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    php artisan key:generate --force
fi
