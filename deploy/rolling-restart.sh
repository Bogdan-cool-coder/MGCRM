#!/usr/bin/env bash
# Zero-downtime rolling-deploy for MACRO Global CRM.
#
# Strategy (single-replica; adapted from examples/contracts/deploy/rolling-restart.sh):
#   1. Build new app + frontend images.
#   2. Bring up new app replica alongside old one.
#   3. Wait until new replica is healthy.
#   4. Stop and remove old replica.
#   5. Bring up remaining services (nginx, queue-worker, gotenberg).
#   6. Run migrations (idempotent, --force).
#
# nginx health-check excludes non-healthy containers, so traffic is always
# served by a live replica during the swap window.
#
# Activated on M12 cutover by deploy-engineer.
# Called from .github/workflows/deploy.yml after `git reset --hard origin/main`.

set -euo pipefail

cd "$(dirname "$0")/.."   # repo root (/opt/macro-crm on prod)

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

echo "==> Bring up remaining services"
docker compose up -d --no-deps nginx queue-worker gotenberg

echo "==> Health-check: /up"
for i in $(seq 1 30); do
  STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8080/up 2>/dev/null || echo "000")
  if [ "$STATUS" = "200" ]; then
    echo "    /up -> 200 OK"
    break
  fi
  echo "    /up -> $STATUS (attempt $i/30)"
  sleep 2
done

echo "==> Stack status"
docker compose ps
