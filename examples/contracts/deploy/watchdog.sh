#!/usr/bin/env bash
# Watchdog контейнеров MACRO Contracts. Запускается из cron каждую минуту.
# Проверяет состояние контейнеров, при ИЗМЕНЕНИИ состояния шлёт Telegram-алерт.
# Дебаунс: один алерт на смену состояния, не спамит.
#
# Setup:
#   echo 'WATCHDOG_ALERT_CHAT_ID=<твой_личный_chat_id>' >> /opt/macro-contracts/.env
#   bash /opt/macro-contracts/deploy/setup_cron.sh

set -uo pipefail

cd /opt/macro-contracts
# Безопасный парсинг .env (значения с пробелами ломают source)
get_env() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true; }
TELEGRAM_BOT_TOKEN="$(get_env TELEGRAM_BOT_TOKEN)"
TELEGRAM_APPROVAL_CHAT_ID="$(get_env TELEGRAM_APPROVAL_CHAT_ID)"
WATCHDOG_ALERT_CHAT_ID="$(get_env WATCHDOG_ALERT_CHAT_ID)"

STATE_DIR="/var/lib/macro-contracts"
mkdir -p "$STATE_DIR"
STATE_FILE="$STATE_DIR/watchdog.state"

# api — масштабируемый сервис (replicas), фикс-имени нет → мониторим через compose.
# Остальные — по фикс-именам контейнеров.
FIXED_CONTAINERS=("macro-contracts-db" "macro-contracts-web" "macro-contracts-bot")
OK_STATES=("running/healthy" "running/none" "running/starting")
API_MIN_HEALTHY="${API_MIN_HEALTHY:-1}"  # тревога, если живых api-реплик меньше

inspect_status() {
  docker inspect --format '{{.State.Status}}/{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$1" 2>/dev/null || echo 'missing/missing'
}

# "<healthy>/<total>" по репликам сервиса api
api_healthy_total() {
  local ids id st healthy=0 total=0
  ids="$(docker compose ps -q api 2>/dev/null || true)"
  for id in $ids; do
    total=$((total + 1))
    st="$(inspect_status "$id")"
    for s in "${OK_STATES[@]}"; do [ "$st" = "$s" ] && { healthy=$((healthy + 1)); break; }; done
  done
  echo "${healthy}/${total}"
}

tg_alert() {
  local text="$1"
  local chat="${WATCHDOG_ALERT_CHAT_ID:-${TELEGRAM_APPROVAL_CHAT_ID:-}}"
  [ -z "$chat" ] && return 0
  [ -z "${TELEGRAM_BOT_TOKEN:-}" ] && return 0
  curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    -d chat_id="$chat" \
    -d parse_mode=HTML \
    --data-urlencode text="$text" >/dev/null || true
}

# Собираем текущее состояние (фикс-контейнеры + сводка по api-репликам)
current=""
for c in "${FIXED_CONTAINERS[@]}"; do
  current="${current}${c}=$(inspect_status "$c") "
done
current="${current}api=$(api_healthy_total) "

prev=""
[ -f "$STATE_FILE" ] && prev="$(cat "$STATE_FILE")"

# Если состояние не изменилось — выходим
[ "$current" == "$prev" ] && exit 0

# Состояние изменилось — записываем и проверяем на проблемы
echo "$current" > "$STATE_FILE"

problems=""
for c in "${FIXED_CONTAINERS[@]}"; do
  status="$(inspect_status "$c")"
  ok=false
  for s in "${OK_STATES[@]}"; do
    [ "$status" == "$s" ] && ok=true
  done
  if ! $ok; then
    problems="${problems}%0A• <b>${c}</b>: ${status}"
  fi
done
# api: тревога, если живых реплик меньше порога
api_state="$(api_healthy_total)"
if [ "${api_state%/*}" -lt "$API_MIN_HEALTHY" ]; then
  problems="${problems}%0A• <b>api</b>: healthy ${api_state} (&lt; ${API_MIN_HEALTHY})"
fi

if [ -n "$problems" ]; then
  tg_alert "🔴 <b>MACRO Contracts: проблема с контейнерами</b>${problems}%0A%0A$(date -u '+%Y-%m-%d %H:%M UTC')"
else
  tg_alert "🟢 <b>MACRO Contracts: контейнеры восстановлены</b>%0A$(date -u '+%Y-%m-%d %H:%M UTC')"
fi
