#!/bin/bash
set -e

# ---------------------------------------------------------------------------
# Framework directories (storage/framework and storage/logs are named volumes;
# on a fresh volume the subdirectory tree does not exist yet).
# ---------------------------------------------------------------------------
mkdir -p /var/www/html/storage/framework/views
mkdir -p /var/www/html/storage/framework/cache/data
mkdir -p /var/www/html/storage/framework/sessions
mkdir -p /var/www/html/storage/framework/testing
mkdir -p /var/www/html/storage/logs
mkdir -p /var/www/html/bootstrap/cache

# ---------------------------------------------------------------------------
# Application storage (storage/app is a named volume; initialise the expected
# subdirectories when the volume is empty).
# ---------------------------------------------------------------------------
mkdir -p /var/www/html/storage/app/public
mkdir -p /var/www/html/storage/app/private

# ---------------------------------------------------------------------------
# public/storage symlink → storage/app/public. Required so files on disk
# `public` (e.g. user avatars, company branding logos) are served via
# APP_URL/storage.
# Idempotent: skip when the symlink already resolves to the right target.
# Must run each boot — public/ is baked into the image layer, so the symlink
# does not survive a rebuild, while storage/app/public is a named volume.
# ---------------------------------------------------------------------------
if [ ! -L /var/www/html/public/storage ]; then
    rm -rf /var/www/html/public/storage
    ln -s /var/www/html/storage/app/public /var/www/html/public/storage
fi

# ---------------------------------------------------------------------------
# Fix ownership — www-data must own all runtime directories.
# Runs as root (php:8.5-fpm default), then php-fpm drops to www-data.
# ---------------------------------------------------------------------------
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/html/public
chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# ---------------------------------------------------------------------------
# Clear compiled bootstrap cache so it is rebuilt from the vendor/ tree that
# was installed during the Docker build (--no-dev for production images).
# This prevents stale dev-only entries (e.g. Laravel\Pail) from surviving a
# rebuild where the named bootstrap_cache volume shadows the image copy.
#
# IMPORTANT: delete packages.php and services.php with plain rm BEFORE calling
# php artisan — artisan itself bootstraps Laravel which reads packages.php, so
# if packages.php is stale it will crash artisan before optimize:clear can run.
# ---------------------------------------------------------------------------
rm -f /var/www/html/bootstrap/cache/packages.php \
      /var/www/html/bootstrap/cache/services.php

php artisan optimize:clear --no-interaction 2>/dev/null || true
php artisan package:discover --ansi 2>/dev/null || true

exec "$@"
