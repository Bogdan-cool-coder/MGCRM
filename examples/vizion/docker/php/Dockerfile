# =============================================================================
# Stage 1 — vendor: install composer dependencies without app source
# (separate stage for layer-cache efficiency: rebuilds only on composer.lock change)
# =============================================================================
FROM composer:2 AS vendor

WORKDIR /app

COPY src/composer.json src/composer.lock ./

# Install prod dependencies only, skip autoloader (done after full COPY in stage 2)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --no-interaction

# =============================================================================
# Stage 2 — app: PHP-FPM runtime with baked-in vendor + source
# =============================================================================
FROM php:8.3-fpm AS app

# System dependencies
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libicu-dev \
    libonig-dev \
    postgresql-client \
    && rm -rf /var/lib/apt/lists/*

# PHP extensions
# pdo_pgsql  — PostgreSQL (Vizion DB)
# pdo_mysql  — MySQL (MacroData, read-only)
# zip        — composer, archive handling
# intl       — i18n (date/number formatting)
# mbstring   — UTF-8 string handling
# opcache    — bytecode cache (performance)
RUN docker-php-ext-install \
    pdo_pgsql \
    pdo_mysql \
    zip \
    intl \
    mbstring \
    opcache

# PHP overrides for AI chat: tool cascades on slow GLM responses can run 5+ minutes.
# nginx fastcgi_read_timeout is 600s; PHP must not bail earlier.
RUN { \
        echo "max_execution_time = 600"; \
        echo "max_input_time = 600"; \
        echo "memory_limit = 512M"; \
    } > /usr/local/etc/php/conf.d/vizion-ai.ini

# PHP-FPM: same 10-minute ceiling for the worker before it gets killed.
RUN { \
        echo "[www]"; \
        echo "request_terminate_timeout = 600s"; \
    } > /usr/local/etc/php-fpm.d/zz-vizion-ai.conf

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
