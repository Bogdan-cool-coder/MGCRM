#!/bin/bash
# Dev entrypoint for MACRO Global CRM app container.
#
# Runs before php-fpm starts. Safe to re-run (idempotent).
# Source is bind-mounted at /var/www/html; vendor/ is a named volume.
set -e

# ---------------------------------------------------------------------------
# 1. Runtime directories (named volumes start empty on first boot)
# ---------------------------------------------------------------------------
mkdir -p \
    /var/www/html/storage/app/public \
    /var/www/html/storage/app/private \
    /var/www/html/storage/framework/cache/data \
    /var/www/html/storage/framework/sessions \
    /var/www/html/storage/framework/testing \
    /var/www/html/storage/framework/views \
    /var/www/html/storage/logs \
    /var/www/html/bootstrap/cache

# ---------------------------------------------------------------------------
# 2. public/storage symlink (same as prod entrypoint.sh)
# ---------------------------------------------------------------------------
if [ ! -L /var/www/html/public/storage ]; then
    rm -rf /var/www/html/public/storage
    ln -s /var/www/html/storage/app/public /var/www/html/public/storage
fi

# ---------------------------------------------------------------------------
# 3. Fix ownership
# ---------------------------------------------------------------------------
chown -R www-data:www-data \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache \
    /var/www/html/public
chmod -R 775 \
    /var/www/html/storage \
    /var/www/html/bootstrap/cache

# ---------------------------------------------------------------------------
# 4. composer install (all deps including --dev) if vendor/ is missing or stale.
#    Vendor is stored in a named volume; the lock file is in the bind-mounted
#    src/. We track the lock hash in a sentinel file to avoid re-running on
#    every container start.
# ---------------------------------------------------------------------------
SENTINEL=/var/www/html/vendor/.installed-lock-hash
LOCK_HASH=$(md5sum /var/www/html/composer.lock 2>/dev/null | cut -d' ' -f1 || echo "none")

if [ ! -f "$SENTINEL" ] || [ "$(cat "$SENTINEL")" != "$LOCK_HASH" ]; then
    echo "[entrypoint.dev] composer install (vendor/ missing or lock changed)"
    cd /var/www/html
    composer install \
        --no-interaction \
        --prefer-dist \
        --ignore-platform-req=ext-gd \
        --ignore-platform-req=ext-intl
    echo "$LOCK_HASH" > "$SENTINEL"
fi

# ---------------------------------------------------------------------------
# 5. Clear stale bootstrap caches so artisan uses the freshly installed vendor
# ---------------------------------------------------------------------------
rm -f /var/www/html/bootstrap/cache/packages.php \
      /var/www/html/bootstrap/cache/services.php

php artisan optimize:clear --no-interaction 2>/dev/null || true
php artisan package:discover --ansi 2>/dev/null || true

exec "$@"
