#!/bin/bash
# ================================================================
# Vizion — развёртывание DEV окружения
# Запускать от skorpyone: bash scripts/setup-dev.sh
# ================================================================
set -euo pipefail

DEPLOY_DIR="$HOME/vizion/dev"
REPO_URL="git@github.com-vizion:Skorpyone/vizion.git"

echo "=== DEV: Клонирование ==="
mkdir -p ~/vizion
if [ -d "$DEPLOY_DIR" ]; then
    echo "  [SKIP] $DEPLOY_DIR already exists"
else
    git clone "$REPO_URL" "$DEPLOY_DIR"
    cd "$DEPLOY_DIR"
    git checkout dev
    echo "  [OK] Cloned and checked out dev"
fi

cd "$DEPLOY_DIR"

echo "=== DEV: Создание .env ==="
if [ -f .env ]; then
    echo "  [SKIP] .env already exists (не перезаписываем)"
else
    cat > .env <<'EOF'
# --- Docker ---
COMPOSE_PROJECT_NAME=vizion-dev
APP_BIND=127.0.0.1
APP_PORT=2020
FRONTEND_PORT=3030
POSTGRES_HOST_PORT=5420

# --- Laravel ---
APP_NAME=Vizion
APP_ENV=local
APP_KEY=
APP_DEBUG=true
APP_URL=https://devizion.macroglobal.tech

# --- PostgreSQL ---
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=vizion_dev
DB_USERNAME=vizion
DB_PASSWORD=CHANGE_ME

# --- MacroData (MySQL) ---
MACRODATA_CONNECTION=mysql
MACRODATA_HOST=
MACRODATA_PORT=3306
MACRODATA_DATABASE=
MACRODATA_USERNAME=
MACRODATA_PASSWORD=

# --- AI ---
AI_PROVIDER=gemini
GEMINI_API_KEY=
DEEPSEEK_API_KEY=

# --- Sanctum ---
SANCTUM_STATEFUL_DOMAINS=devizion.macroglobal.tech
EOF
    echo "  [OK] .env created — ОТРЕДАКТИРУЙТЕ ПАРОЛИ И КЛЮЧИ!"
fi

echo "=== DEV: Запуск контейнеров ==="
docker compose up -d --build

echo "=== DEV: Настройка Laravel ==="
docker compose exec -T --user root app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec -T app composer install --no-interaction

# Key generate (только если пустой APP_KEY)
if grep -q 'APP_KEY=$' .env || grep -q 'APP_KEY=$' .env; then
    docker compose exec -T app php artisan key:generate
    echo "  [OK] APP_KEY generated"
fi

docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan db:seed --force

echo ""
echo "  [OK] DEV окружение запущено!"
echo "  Frontend: http://127.0.0.1:3030"
echo "  API:      http://127.0.0.1:2020"
echo ""
