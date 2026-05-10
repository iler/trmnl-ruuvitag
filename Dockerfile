FROM ghcr.io/serversideup/php:8.5-frankenphp AS laravel

WORKDIR /var/www/html

# Install dependencies first to leverage layer caching
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
        --optimize-autoloader \
        --no-interaction \
        --no-plugins \
        --no-scripts \
        --prefer-dist \
        --no-dev \
        --no-autoloader

# S6 service for the scheduler (webhook strategy needs this)
COPY --chown=www-data:www-data \
     ./docker/etc/s6-overlay/s6-rc.d/laravel-schedule \
     /etc/s6-overlay/s6-rc.d/laravel-schedule
COPY --chown=www-data:www-data \
     ./docker/etc/s6-overlay/s6-rc.d/user/contents.d \
     /etc/s6-overlay/s6-rc.d/user/contents.d

# Custom entrypoint scripts (key:generate, migrate, ruuvi:sync-sensors, caches).
# The base image runs anything executable in /etc/entrypoint.d/ in alphanumeric
# order before launching the main process.
COPY --chmod=755 ./docker/init.d/ /etc/entrypoint.d/

# App code
COPY --chown=www-data:www-data . .

# Finalise autoloader now that all source is present
RUN composer dump-autoload --optimize --no-dev

# Writable storage and SQLite database file
RUN mkdir -p storage/logs database \
    && touch storage/logs/laravel.log database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080
