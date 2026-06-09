#!/bin/bash
# ================================================================
# Vizion — развёртывание PROD окружения
# Запускать от skorpyone: bash scripts/setup-prod.sh
# ================================================================
set -euo pipefail

DEPLOY_DIR="$HOME/vizion/prod"
REPO_URL="git@github.com-vizion:Skorpyone/vizion.git"

echo "=== PROD: Клонирование ==="
mkdir -p ~/vizion
if [ -d "$DEPLOY_DIR" ]; then
    echo "  [SKIP] $DEPLOY_DIR already exists"
else
    git clone "$REPO_URL" "$DEPLOY_DIR"
    cd "$DEPLOY_DIR"
    git checkout master
    echo "  [OK] Cloned and checked out master"
fi

cd "$DEPLOY_DIR"

echo "=== PROD: Создание .env ==="
if [ -f .env ]; then
    echo "  [SKIP] .env already exists (не перезаписываем)"
else
    cat > .env <<'EOF'
# --- Docker ---
COMPOSE_PROJECT_NAME=vizion-prod
APP_BIND=127.0.0.1
APP_PORT=2021
FRONTEND_PORT=3031
POSTGRES_HOST_PORT=5421

# --- Laravel ---
APP_NAME=Vizion
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://vizion.macroglobal.tech

# --- PostgreSQL ---
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=vizion_prod
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
SANCTUM_STATEFUL_DOMAINS=vizion.macroglobal.tech
EOF
    echo "  [OK] .env created — ОТРЕДАКТИРУЙТЕ ПАРОЛИ И КЛЮЧИ!"
fi

echo "=== PROD: Запуск контейнеров ==="
docker compose up -d --build

echo "=== PROD: Настройка Laravel ==="
docker compose exec -T --user root app chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
docker compose exec -T app composer install --no-interaction --optimize-autoloader --no-dev

# Key generate (только если пустой APP_KEY)
if grep -q 'APP_KEY=$' .env || grep -q 'APP_KEY=$' .env; then
    docker compose exec -T app php artisan key:generate
    echo "  [OK] APP_KEY generated"
fi

docker compose exec -T app php artisan migrate --force
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache

echo ""
echo "  [OK] PROD окружение запущено!"
echo "  Frontend: http://127.0.0.1:3031"
echo "  API:      http://127.0.0.1:2021"
echo ""
