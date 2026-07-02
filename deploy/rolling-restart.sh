#!/usr/bin/env bash
# Rolling deploy for MACRO Global CRM (force-recreate app; short blip ~seconds).
#
# Strategy (single-replica; adapted from examples/contracts/deploy/rolling-restart.sh):
#   1. Ensure DB + Redis are up.
#   2. Build new app + frontend images.
#   3. Force-recreate app container (--force-recreate --no-deps); wait until healthy/running.
#      NOTE: app has a fixed container_name so true two-replica swap is impossible.
#      A short downtime (seconds) is accepted in exchange for correctness — the old
#      --no-recreate approach silently kept the stale image running.
#   4. Run migrations (idempotent, --force) + Laravel prod optimisations in NEW container.
#   5. Force-recreate remaining services (nginx, frontend, queue-worker, scheduler, gotenberg)
#      so they also pick up the freshly built images.
#   6. Bot is NOT started automatically (set START_BOT=true to override) — see step notes.
#
# Called from .github/workflows/deploy.yml after `git reset --hard origin/main`.

set -euo pipefail

cd "$(dirname "$0")/.."   # repo root (/opt/mgcrm on prod)

HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

# ---------------------------------------------------------------------------
# Sentry release — single git SHA for both api + web.
# Exported so it's available to: (a) docker compose build arg VITE_SENTRY_RELEASE,
# (b) SENTRY_RELEASE env var written to src/.env for the Laravel SDK.
# ---------------------------------------------------------------------------
SENTRY_RELEASE="$(git rev-parse HEAD)"
export SENTRY_RELEASE

# Inject SENTRY_RELEASE into backend env (idempotent: update or append).
ENV_FILE="src/.env"
if grep -q "^SENTRY_RELEASE=" "$ENV_FILE" 2>/dev/null; then
  sed -i "s|^SENTRY_RELEASE=.*|SENTRY_RELEASE=${SENTRY_RELEASE}|" "$ENV_FILE"
else
  printf '\nSENTRY_RELEASE=%s\n' "$SENTRY_RELEASE" >> "$ENV_FILE"
fi

# Source non-secret Sentry frontend build vars (VITE_SENTRY_DSN, SENTRY_ORG, SENTRY_PROJECT).
# File lives outside git at /opt/mgcrm/secrets/sentry_frontend_build.env.
# On local dev the file may be absent; build proceeds without Sentry upload.
SENTRY_FRONTEND_BUILD_ENV="${SENTRY_AUTH_TOKEN_FILE:+$(dirname "${SENTRY_AUTH_TOKEN_FILE}")/sentry_frontend_build.env}"
SENTRY_FRONTEND_BUILD_ENV="${SENTRY_FRONTEND_BUILD_ENV:-/opt/mgcrm/secrets/sentry_frontend_build.env}"
if [ -f "$SENTRY_FRONTEND_BUILD_ENV" ]; then
  # shellcheck source=/dev/null
  . "$SENTRY_FRONTEND_BUILD_ENV"
  export VITE_SENTRY_DSN SENTRY_ORG SENTRY_PROJECT
  echo "==> Sentry frontend build vars sourced from ${SENTRY_FRONTEND_BUILD_ENV}"
else
  echo "==> Sentry frontend build vars file not found (${SENTRY_FRONTEND_BUILD_ENV}); Sentry upload will be skipped"
fi

# Pass VITE_SENTRY_RELEASE as env var so docker-compose.yml build arg picks it up.
export VITE_SENTRY_RELEASE="${SENTRY_RELEASE}"

# Reverb build-time vars — non-secret, sourced from the server root .env
# (/opt/mgcrm/.env) which is loaded by the deploy shell before this script runs.
# Export them explicitly so docker-compose.yml build.args interpolation finds them.
# If not set in root .env, fall back to safe defaults (empty key disables Reverb client).
export VITE_REVERB_APP_KEY="${VITE_REVERB_APP_KEY:-}"
export VITE_REVERB_HOST="${VITE_REVERB_HOST:-}"
export VITE_REVERB_PORT="${VITE_REVERB_PORT:-8080}"
export VITE_REVERB_SCHEME="${VITE_REVERB_SCHEME:-https}"

# Point compose to the secrets token file (used by top-level `secrets:` declaration).
export SENTRY_AUTH_TOKEN_FILE="${SENTRY_AUTH_TOKEN_FILE:-/opt/mgcrm/secrets/sentry_auth_token}"

container_health() {
  docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null \
    || echo missing
}

wait_healthy() {
  local id="$1"
  local deadline=$(( SECONDS + HEALTH_TIMEOUT ))
  while true; do
    local status
    status="$(container_health "$id")"
    case "$status" in
      healthy)
        return 0 ;;
      none)
        # No healthcheck configured — container is running, treat as ready.
        echo "    (no healthcheck on container; skipping health-wait)"
        return 0 ;;
      missing)
        echo "ERROR: container $id not found"
        return 1 ;;
      starting)
        : ;;  # still initialising, keep waiting
      unhealthy)
        echo "ERROR: container $id is unhealthy. Logs:"
        docker logs --tail=60 "$id" || true
        return 1 ;;
    esac
    if [ "$SECONDS" -gt "$deadline" ]; then
      echo "ERROR: container $id did not become healthy within ${HEALTH_TIMEOUT}s. Logs:"
      docker logs --tail=60 "$id" || true
      return 1
    fi
    sleep 2
  done
}

echo "==> Ensure db + redis are up"
docker compose up -d postgres redis

echo "==> Build app image"
docker compose build app

echo "==> Build frontend image (SENTRY_RELEASE=${SENTRY_RELEASE})"
# Non-secret vars (VITE_SENTRY_DSN, SENTRY_ORG, SENTRY_PROJECT, VITE_SENTRY_RELEASE)
# are exported above and picked up via docker-compose.yml build.args interpolation.
# SENTRY_AUTH_TOKEN is injected via BuildKit secret-mount (never in image layer).
DOCKER_BUILDKIT=1 docker compose build frontend

echo "==> Rolling restart: app"
# app has a fixed container_name (macro-crm-app), so true zero-downtime
# two-replica swap is not possible.  We do an explicit force-recreate:
# the old container is replaced with the new image, then we wait until
# healthy before running migrations.  Downtime is a few seconds — acceptable
# and far safer than the previous --no-recreate approach that silently kept
# the old image running.
docker compose up -d --force-recreate --no-deps app

APP_ID="$(docker compose ps -q app 2>/dev/null || true)"
if [ -z "$APP_ID" ]; then
  echo "ERROR: app container did not start after force-recreate"
  exit 1
fi
echo "    waiting for app container to be healthy: $APP_ID"
wait_healthy "$APP_ID"
echo "    app container healthy ($APP_ID)"

echo "==> Run migrations"
docker compose exec -T app php artisan migrate --force

echo "==> Laravel prod optimisations"
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "==> Bring up remaining services (nginx, frontend, queue-worker, scheduler, gotenberg, reverb)"
# --force-recreate ensures workers and nginx also pick up the freshly built images.
# reverb shares the app image — must be recreated so it runs the new code.
docker compose up -d --force-recreate --no-deps nginx frontend queue-worker scheduler gotenberg reverb

# Bot is intentionally NOT started automatically during deploy.
# It is held back (nutgram:run exits with 409 Conflict when a second polling
# instance hits Telegram — e.g. a stale webhook or another container).
# Start manually when needed:  docker compose up -d bot
# To enable auto-start set START_BOT=true before running this script.
START_BOT="${START_BOT:-false}"
if [ "$START_BOT" = "true" ]; then
  echo "==> Starting bot (START_BOT=true)"
  docker compose up -d --no-deps --force-recreate bot
else
  echo "==> Bot skipped (START_BOT=${START_BOT}); start manually when ready"
fi

# Health-check runs entirely inside the Docker network — no host-port required.
# nginx sits in front of app (php-fpm) and listens on :80 inside the mgcrm_net network;
# we exec into the app container and curl the nginx upstream directly.
echo "==> Health-check: GET http://nginx/up (via app container, internal network)"
for i in $(seq 1 30); do
  STATUS=$(docker compose exec -T app curl -fsS -o /dev/null -w "%{http_code}" \
    http://nginx/up 2>/dev/null || echo "000")
  if [ "$STATUS" = "200" ]; then
    echo "    /up -> 200 OK"
    break
  fi
  echo "    /up -> $STATUS (attempt $i/30)"
  if [ "$i" -eq 30 ]; then
    echo "ERROR: health-check did not pass after 30 attempts"
    exit 1
  fi
  sleep 2
done

echo "==> Stack status"
docker compose ps
