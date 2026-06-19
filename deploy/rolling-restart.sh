#!/usr/bin/env bash
# Zero-downtime rolling-deploy for MACRO Global CRM.
#
# Strategy (single-replica; adapted from examples/contracts/deploy/rolling-restart.sh):
#   1. Ensure DB + Redis are up.
#   2. Build new app + frontend images.
#   3. Bring up new app replica alongside old one (--no-recreate).
#   4. Wait until new replica is healthy.
#   5. Stop and remove old replica.
#   6. Run migrations (idempotent, --force) + Laravel prod optimisations.
#   7. Bring up remaining services (nginx, frontend, queue-worker, scheduler, gotenberg).
#   8. Bot is NOT started automatically (set START_BOT=true to override) — see step notes.
#
# nginx/Traefik excludes non-healthy containers from routing, so traffic is
# always served by a live replica during the swap window.
#
# Called from .github/workflows/deploy.yml after `git reset --hard origin/main`.

set -euo pipefail

cd "$(dirname "$0")/.."   # repo root (/opt/mgcrm on prod)

HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

container_health() {
  docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null \
    || echo missing
}

wait_healthy() {
  local id="$1"
  local deadline=$(( SECONDS + HEALTH_TIMEOUT ))
  until [ "$(container_health "$id")" = "healthy" ]; do
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

echo "==> Build frontend image"
docker compose build frontend

echo "==> Rolling restart: app"
OLD_APP_ID="$(docker compose ps -q app 2>/dev/null || true)"

if [ -z "$OLD_APP_ID" ]; then
  echo "    no old container — starting fresh"
  docker compose up -d app
else
  echo "    starting new app container alongside old ($OLD_APP_ID)"
  # Bring up a second replica — compose will start a new container without
  # stopping the running one when --no-recreate is passed.
  docker compose up -d --no-deps --no-recreate app

  NEW_APP_ID="$(docker compose ps -q app | grep -vxF "$OLD_APP_ID" | head -1 || true)"
  if [ -n "$NEW_APP_ID" ]; then
    echo "    waiting for new container to be healthy: $NEW_APP_ID"
    wait_healthy "$NEW_APP_ID"
    echo "    new container healthy — stopping old: $OLD_APP_ID"
    docker stop "$OLD_APP_ID" >/dev/null
    docker rm   "$OLD_APP_ID" >/dev/null
  else
    echo "    single-container compose service — container recreated in place (already up)"
  fi
fi

echo "==> Run migrations"
docker compose exec -T app php artisan migrate --force

echo "==> Laravel prod optimisations"
docker compose exec -T app php artisan config:cache
docker compose exec -T app php artisan route:cache
docker compose exec -T app php artisan view:cache

echo "==> Bring up remaining services (nginx, frontend, queue-worker, scheduler, gotenberg)"
docker compose up -d --no-deps nginx frontend queue-worker scheduler gotenberg

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
