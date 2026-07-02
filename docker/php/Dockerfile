# =============================================================================
# Stage 1 — vendor: install composer dependencies without app source
# (separate stage for layer-cache efficiency: rebuilds only on composer.lock change)
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY src/composer.json src/composer.lock ./

# Install prod dependencies only, skip autoloader (done after full COPY in stage 2).
# The composer base image lacks gd/intl, but stage 2 (php:8.5-fpm) installs them —
# so ignore those platform reqs here; the runtime image satisfies them.
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction \
    --ignore-platform-req=ext-gd \
    --ignore-platform-req=ext-intl

# =============================================================================
# Stage 2 — app: PHP-FPM runtime with baked-in vendor + source
# =============================================================================
FROM php:8.5-fpm AS app

# System dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions. Installed in separate, smaller invocations so a failure in
# one (and the Zend-extension opcache, which copies differently) does not abort
# the whole batch.
#
# pdo_pgsql  — PostgreSQL (MGCRM DB)
# zip        — composer, archive handling, phpspreadsheet xlsx
# intl       — i18n (date/number formatting), RU locale
# mbstring   — UTF-8 string handling
# bcmath     — money arithmetic (kopecks, integer-safe)
# gd         — image handling for phpword/phpspreadsheet, avatars (jpeg+freetype)
#
# NOTE: opcache is compiled statically into the php:8.5-fpm base image (it shows
# up in `php -m` already), so there is nothing to docker-php-ext-install — we
# only ship tuning ini for it below.
RUN docker-php-ext-install -j"$(nproc)" \
    pdo_pgsql \
    zip \
    intl \
    mbstring \
    bcmath \
    pcntl

RUN docker-php-ext-configure gd --with-jpeg --with-freetype \
    && docker-php-ext-install -j"$(nproc)" gd

# Redis extension (net-new vs Vizion) — Redis is the queue/cache backend.
RUN pecl install redis \
    && docker-php-ext-enable redis

# PHP overrides: AI tool cascades and document generation (PHPWord→Gotenberg)
# can run several minutes. nginx fastcgi_read_timeout is 600s; PHP must not
# bail earlier.
RUN { \
        echo "max_execution_time = 600"; \
        echo "max_input_time = 600"; \
        echo "memory_limit = 512M"; \
    } > /usr/local/etc/php/conf.d/macro-crm.ini

# OPcache tuning (opcache is built into php:8.5-fpm; enable + size it for prod).
RUN { \
        echo "opcache.enable = 1"; \
        echo "opcache.enable_cli = 0"; \
        echo "opcache.memory_consumption = 256"; \
        echo "opcache.interned_strings_buffer = 16"; \
        echo "opcache.max_accelerated_files = 20000"; \
        echo "opcache.validate_timestamps = 0"; \
    } > /usr/local/etc/php/conf.d/macro-crm-opcache.ini

# PHP-FPM: same 10-minute ceiling for the worker before it gets killed.
RUN { \
        echo "[www]"; \
        echo "request_terminate_timeout = 600s"; \
    } > /usr/local/etc/php-fpm.d/zz-macro-crm.conf

# Bring composer binary for dump-autoload in this stage
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy application source
COPY src/ /var/www/html/

# Copy vendor from stage 1
COPY --from=vendor /app/vendor /var/www/html/vendor

# Generate optimised autoloader (classmap-authoritative for production)
RUN cd /var/www/html && composer dump-autoload --optimize --classmap-authoritative --no-scripts

# Regenerate package manifest without dev packages.
# The src/ copy may include stale bootstrap/cache/*.php generated with --dev.
# Remove stale cache first so artisan doesn't try to load dev-only providers.
# Then regenerate with only the prod packages actually present in vendor/.
RUN rm -f /var/www/html/bootstrap/cache/packages.php \
          /var/www/html/bootstrap/cache/services.php \
    && cd /var/www/html \
    && APP_KEY=placeholder php artisan package:discover --ansi

# Initialise Laravel runtime directories. src/storage/ is git-ignored (runtime
# only) so the COPY above does not bring the tree along — create it here so
# image builds without docker-compose named volumes still boot.
# entrypoint.sh re-runs mkdir + chown after volumes are attached at runtime.
RUN mkdir -p \
        /var/www/html/storage/app/public \
        /var/www/html/storage/app/private \
        /var/www/html/storage/framework/cache/data \
        /var/www/html/storage/framework/sessions \
        /var/www/html/storage/framework/testing \
        /var/www/html/storage/framework/views \
        /var/www/html/storage/logs \
        /var/www/html/bootstrap/cache \
    && chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache \
    && chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

# Entrypoint initialises named-volume directories on first start
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["entrypoint.sh"]
CMD ["php-fpm"]
