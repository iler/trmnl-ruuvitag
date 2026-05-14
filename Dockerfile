# ============================================================
# Multi-stage build.
#
# Default target is `prod` (the last stage), so `docker build .` and
# `docker compose build` (with no explicit target) continue to produce
# the shipping image.
#
# The `dev` target is consumed by docker-compose.dev.yml — it carries
# the vendor + storage scaffolding the framework needs, but expects the
# project source to be bind-mounted at runtime so host edits land in the
# container without a `docker compose cp` round-trip.
# ============================================================

# ----- base: shared FrankenPHP image + S6 service + entrypoints -----
FROM ghcr.io/serversideup/php:8.5-frankenphp AS base
WORKDIR /var/www/html

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

# ----- composer-prod: vendor without dev deps -----
FROM base AS composer-prod
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
        --optimize-autoloader \
        --no-interaction \
        --no-plugins \
        --no-scripts \
        --prefer-dist \
        --no-dev \
        --no-autoloader

# ----- composer-dev: vendor with dev deps (Pest, Larastan, Pint, …) -----
# Note: no `--no-autoloader` here. App source isn't in the image (bind-mounted
# at runtime), but we still want vendor/autoload.php to exist so artisan can
# boot. We skip `--optimize-autoloader` because the App\* classmap would be
# empty at build time — the standard PSR-4 loader resolves classes at runtime
# from the bind-mounted source. Run `composer dump-autoload -o` inside the
# container if you want the optimized classmap.
FROM base AS composer-dev
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install \
        --no-interaction \
        --no-plugins \
        --no-scripts \
        --prefer-dist

# ===== dev: bind-mount target =====
# Carries vendor + writable scaffolding only. Application source is provided
# by the bind mount in docker-compose.dev.yml so iterating doesn't require
# rebuilding the image.
FROM base AS dev
COPY --from=composer-dev --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

RUN mkdir -p \
        storage/logs \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        bootstrap/cache \
        database \
    && touch storage/logs/laravel.log database/database.sqlite \
    && chown -R www-data:www-data storage bootstrap/cache database \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

# ===== prod (default): shipping image =====
FROM base AS prod
COPY --from=composer-prod --chown=www-data:www-data /var/www/html/vendor /var/www/html/vendor

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
