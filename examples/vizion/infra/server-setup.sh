#!/bin/bash
# =============================================================================
# Vizion — idempotent bootstrap for a fresh server
# Run ONCE on the new server: bash infra/server-setup.sh dev|prod
#
# Prerequisites on the server:
#   - Docker + Docker Compose v2 already installed
#   - User has passwordless sudo (or will be prompted)
#   - Domain DNS already points to this server IP
#   - GHCR PAT or gh CLI authenticated (for docker login)
#
# Usage:
#   bash infra/server-setup.sh dev    # sets up ~/vizion/dev
#   bash infra/server-setup.sh prod   # sets up ~/vizion/prod
# =============================================================================
set -euo pipefail

# ---------------------------------------------------------------------------
# Argument check
# ---------------------------------------------------------------------------
ENV="${1:-}"
if [[ "$ENV" != "dev" && "$ENV" != "prod" ]]; then
    echo "Usage: $0 dev|prod"
    exit 1
fi

# ---------------------------------------------------------------------------
# Config
# ---------------------------------------------------------------------------
DEPLOY_DIR="$HOME/vizion/$ENV"

if [[ "$ENV" == "dev" ]]; then
    DOMAIN="devizion.macroglobal.tech"
    APP_PORT="2020"
    FRONTEND_PORT="3030"
    POSTGRES_HOST_PORT="5420"
    COMPOSE_PROJECT="vizion-dev"
    IMAGE_TAG="dev-latest"
    APP_URL="https://devizion.macroglobal.tech"
    APP_ENV_VALUE="local"
    APP_DEBUG_VALUE="true"
    DB_DATABASE="vizion_dev"
else
    DOMAIN="vizion.macroglobal.tech"
    APP_PORT="2021"
    FRONTEND_PORT="3031"
    POSTGRES_HOST_PORT="5421"
    COMPOSE_PROJECT="vizion-prod"
    IMAGE_TAG="prod-latest"
    APP_URL="https://vizion.macroglobal.tech"
    APP_ENV_VALUE="production"
    APP_DEBUG_VALUE="false"
    DB_DATABASE="vizion_prod"
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

echo "=== [1/7] Creating deployment directory: $DEPLOY_DIR ==="
mkdir -p "$DEPLOY_DIR"
mkdir -p "$DEPLOY_DIR/data/backups"

# ---------------------------------------------------------------------------
# Copy docker-compose.yml and nginx config
# ---------------------------------------------------------------------------
echo "=== [2/7] Copying docker-compose.yml and nginx config ==="
cp "$REPO_DIR/docker-compose.yml" "$DEPLOY_DIR/docker-compose.yml"
mkdir -p "$DEPLOY_DIR/docker/nginx"
cp "$REPO_DIR/docker/nginx/default.conf" "$DEPLOY_DIR/docker/nginx/default.conf"
cp "$REPO_DIR/docker/nginx/frontend.conf" "$DEPLOY_DIR/docker/nginx/frontend.conf"
echo "  [OK] docker-compose.yml and nginx config copied"

# ---------------------------------------------------------------------------
# Create .env skeleton (never overwrites existing file)
# ---------------------------------------------------------------------------
echo "=== [3/7] Creating .env (skips if already exists) ==="
ENV_FILE="$DEPLOY_DIR/.env"
if [[ -f "$ENV_FILE" ]]; then
    echo "  [SKIP] $ENV_FILE already exists — not overwriting"
else
    cat > "$ENV_FILE" <<EOF
# =============================================================================
# Vizion $ENV environment — fill in all CHANGE_ME values before docker compose up
# =============================================================================

# --- Docker / Compose ---
COMPOSE_PROJECT_NAME=${COMPOSE_PROJECT}
IMAGE_TAG=${IMAGE_TAG}
APP_BIND=127.0.0.1
APP_PORT=${APP_PORT}
FRONTEND_PORT=${FRONTEND_PORT}
POSTGRES_HOST_PORT=${POSTGRES_HOST_PORT}

# --- Laravel ---
APP_NAME=Vizion
APP_ENV=${APP_ENV_VALUE}
APP_KEY=
APP_DEBUG=${APP_DEBUG_VALUE}
APP_URL=${APP_URL}
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

LOG_CHANNEL=stack
LOG_STACK=single
LOG_LEVEL=debug

# --- PostgreSQL (Vizion DB) ---
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=${DB_DATABASE}
DB_USERNAME=vizion
DB_PASSWORD=CHANGE_ME

# --- MacroData (external MySQL, read-only) ---
# Credentials are stored per-company in the companies table, not here.
# Leave blank unless you need a global fallback connection.

# --- AI provider ---
# AI_PROVIDER=glm          # GLM-5 via Z.AI (default)
# AI_PROVIDER=anthropic    # Claude Sonnet fallback
AI_PROVIDER=glm
AI_MODEL=glm-5.1
PRISM_REQUEST_TIMEOUT=120
Z_API_KEY=CHANGE_ME
Z_URL=https://api.z.ai/api/coding/paas/v4
ANTHROPIC_API_KEY=

# --- Sanctum ---
SESSION_DRIVER=database
SESSION_LIFETIME=120
SANCTUM_STATEFUL_DOMAINS=${DOMAIN}

# --- Cache / Queue ---
CACHE_STORE=database
QUEUE_CONNECTION=database

# --- Filesystem ---
FILESYSTEM_DISK=local

# --- Backup (spatie/laravel-backup) ---
# Backups are written to /var/www/backups (bind-mounted from ./data/backups)
EOF
    echo "  [OK] $ENV_FILE created — EDIT all CHANGE_ME values before starting"
fi

# ---------------------------------------------------------------------------
# Docker login to GHCR
# ---------------------------------------------------------------------------
echo "=== [4/7] GHCR login ==="
if command -v gh &>/dev/null; then
    gh auth token | docker login ghcr.io -u skorpyone --password-stdin \
        && echo "  [OK] Logged into GHCR via gh CLI token"
else
    echo "  [!] gh CLI not found. Log in to GHCR manually:"
    echo "      echo YOUR_PAT | docker login ghcr.io -u skorpyone --password-stdin"
fi

# ---------------------------------------------------------------------------
# Sudoers NOPASSWD for CI/CD (nginx timeout config reload)
# ---------------------------------------------------------------------------
echo "=== [4b/7] Sudoers NOPASSWD for deploy user ==="
SUDOERS_FILE="/etc/sudoers.d/vizion-deploy"
DEPLOY_USER="${USER}"
if [[ -f "$SUDOERS_FILE" ]]; then
    echo "  [SKIP] $SUDOERS_FILE already exists"
else
    sudo tee "$SUDOERS_FILE" > /dev/null <<SUDOEOF
# Allow vizion CI/CD deploy user to write nginx timeout config and reload nginx
${DEPLOY_USER} ALL=(ALL) NOPASSWD: /usr/bin/tee /etc/nginx/conf.d/vizion-timeouts.conf
${DEPLOY_USER} ALL=(ALL) NOPASSWD: /usr/sbin/nginx -t
${DEPLOY_USER} ALL=(ALL) NOPASSWD: /bin/systemctl reload nginx
SUDOEOF
    sudo chmod 0440 "$SUDOERS_FILE"
    echo "  [OK] sudoers NOPASSWD rules added for $DEPLOY_USER"
fi

# ---------------------------------------------------------------------------
# Host nginx vhost
# ---------------------------------------------------------------------------
echo "=== [5/7] Host nginx vhost ==="
NGINX_CONF_SRC="$REPO_DIR/infra/nginx/${DOMAIN}.conf"
NGINX_CONF_DST="/etc/nginx/sites-available/${DOMAIN}"

if [[ -f "$NGINX_CONF_SRC" ]]; then
    sudo cp "$NGINX_CONF_SRC" "$NGINX_CONF_DST"
    sudo ln -sf "$NGINX_CONF_DST" "/etc/nginx/sites-enabled/${DOMAIN}"
    sudo nginx -t && sudo systemctl reload nginx
    echo "  [OK] nginx vhost installed and reloaded"
else
    echo "  [SKIP] $NGINX_CONF_SRC not found — install vhost manually"
fi

# ---------------------------------------------------------------------------
# SSL via certbot
# ---------------------------------------------------------------------------
echo "=== [6/7] SSL (certbot) ==="
if sudo certbot certificates 2>/dev/null | grep -q "$DOMAIN"; then
    echo "  [SKIP] Certificate for $DOMAIN already exists"
else
    echo "  [!] Running certbot — DNS for $DOMAIN must already resolve to this server"
    sudo certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos \
        --email admin@macroglobal.tech --redirect \
        && echo "  [OK] SSL certificate issued" \
        || echo "  [WARN] certbot failed — run manually: sudo certbot --nginx -d $DOMAIN"
fi

# ---------------------------------------------------------------------------
# Cron for Laravel scheduler
# ---------------------------------------------------------------------------
echo "=== [7/7] Cron (Laravel scheduler) ==="
CRON_LINE="* * * * * cd $DEPLOY_DIR && docker compose exec -T app php artisan schedule:run >/dev/null 2>&1"
if crontab -l 2>/dev/null | grep -qF "vizion/$ENV"; then
    echo "  [SKIP] Cron entry for vizion/$ENV already present"
else
    (crontab -l 2>/dev/null; echo "$CRON_LINE") | crontab -
    echo "  [OK] Cron entry added"
fi

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
echo ""
echo "============================================================"
echo "  server-setup.sh ($ENV) complete"
echo "============================================================"
echo ""
echo "Next steps:"
echo "  1. Edit $ENV_FILE — fill APP_KEY, DB_PASSWORD, AI keys"
echo "     (generate APP_KEY: docker run --rm php:8.3-cli php -r \"echo base64_encode(random_bytes(32));\")"
echo "  2. docker compose -f $DEPLOY_DIR/docker-compose.yml --env-file $ENV_FILE pull"
echo "  3. docker compose -f $DEPLOY_DIR/docker-compose.yml --env-file $ENV_FILE up -d"
echo "  4. docker compose exec -T app php artisan key:generate  # if APP_KEY is blank"
echo "  5. docker compose exec -T app php artisan migrate --force"
echo "  6. docker compose exec -T app php artisan db:seed --force"
if [[ "$ENV" == "dev" ]]; then
    echo "  7. docker compose exec -T app php artisan db:seed --class=ClientsSeeder --force"
fi
echo ""
