#!/usr/bin/env bash
# Zero-downtime rolling-деплой MACRO Contracts.
#
# Стратегия для api (несколько реплик, БЕЗ Swarm): поднять НОВЫЕ реплики рядом со
# старыми, дождаться их healthy, и только потом погасить старые. Traefik исключает
# не-healthy контейнеры из балансировки, поэтому в каждый момент трафик идёт на живые
# реплики → нет окна 404 на /api при деплое.
#
# Миграции: их прогоняют сами api-реплики (alembic в CMD), сериализуясь через
# pg_advisory_xact_lock (см. alembic/env.py) — двойного применения не будет.
#
# Вызывается из .github/workflows/deploy.yml после git reset --hard.
set -euo pipefail

cd "$(dirname "$0")/.."  # корень репо (на проде /opt/macro-contracts)

REPLICAS="${API_REPLICAS:-2}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-180}"

container_health() {
  docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo missing
}

wait_healthy() {
  local id="$1" deadline=$(( SECONDS + HEALTH_TIMEOUT ))
  until [ "$(container_health "$id")" = "healthy" ]; do
    if [ "$SECONDS" -gt "$deadline" ]; then
      echo "ОШИБКА: реплика $id не стала healthy за ${HEALTH_TIMEOUT}s. Логи:"
      docker logs --tail=60 "$id" || true
      return 1
    fi
    sleep 2
  done
}

echo "==> db (на случай если не поднята)"
docker compose up -d db

echo "==> build api + web"
docker compose pull db || true
docker compose build api web

echo "==> rolling api → ${REPLICAS} реплик"
OLD_IDS="$(docker compose ps -q api || true)"
OLD_COUNT="$(printf '%s\n' "$OLD_IDS" | grep -c . || true)"

if [ "$OLD_COUNT" -eq 0 ]; then
  echo "    старых реплик нет — обычный старт"
  docker compose up -d --scale api="$REPLICAS" api
else
  TARGET=$(( OLD_COUNT + REPLICAS ))
  echo "    поднимаю новые реплики поверх старых (scale ${OLD_COUNT} → ${TARGET})"
  docker compose up -d --no-deps --no-recreate --scale api="$TARGET" api

  NEW_IDS="$(docker compose ps -q api | grep -vF "$OLD_IDS" || true)"
  for id in $NEW_IDS; do
    echo "    жду healthy: $id"
    wait_healthy "$id"
    echo "    healthy: $id"
  done

  echo "    гашу старые реплики: $(echo "$OLD_IDS" | tr '\n' ' ')"
  # shellcheck disable=SC2086
  docker stop $OLD_IDS >/dev/null
  # shellcheck disable=SC2086
  docker rm $OLD_IDS >/dev/null

  echo "    фиксирую scale → ${REPLICAS}"
  docker compose up -d --no-deps --no-recreate --scale api="$REPLICAS" api
fi

echo "==> bot (single replica)"
docker compose up -d --no-deps bot

echo "==> web"
docker compose up -d --no-deps web

echo "==> итог"
docker compose ps
