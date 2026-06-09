#!/usr/bin/env bash
# Ежедневный бэкап БД + storage в /opt/macro-contracts/deploy/backups/
# Ставится в cron: 0 3 * * * /opt/macro-contracts/deploy/backup.sh >> /var/log/macro-contracts-backup.log 2>&1
# Хранит последние 14 дней.

set -euo pipefail

cd /opt/macro-contracts
DUMP_DIR="$(pwd)/deploy/backups"
mkdir -p "$DUMP_DIR"

# Безопасный парсинг .env (значения могут содержать пробелы — source ломается)
get_env() { grep -E "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true; }
DB_USER="$(get_env DB_USER)"
DB_NAME="$(get_env DB_NAME)"
TELEGRAM_BOT_TOKEN="$(get_env TELEGRAM_BOT_TOKEN)"
TELEGRAM_APPROVAL_CHAT_ID="$(get_env TELEGRAM_APPROVAL_CHAT_ID)"
WATCHDOG_ALERT_CHAT_ID="$(get_env WATCHDOG_ALERT_CHAT_ID)"

TS="$(date -u +%Y%m%d-%H%M%S)"
DB_FILE="$DUMP_DIR/db-$TS.sql.gz"
STORAGE_FILE="$DUMP_DIR/storage-$TS.tar.gz"

say() { echo "[$(date -u +%H:%M:%S)] $*"; }

tg_alert() {
  # Шлёт алерт в личку администратора (WATCHDOG_ALERT_CHAT_ID) или в чат согласований
  local text="$1"
  local chat="${WATCHDOG_ALERT_CHAT_ID:-${TELEGRAM_APPROVAL_CHAT_ID:-}}"
  [ -z "$chat" ] && return 0
  [ -z "${TELEGRAM_BOT_TOKEN:-}" ] && return 0
  curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
    -d chat_id="$chat" \
    -d parse_mode=HTML \
    --data-urlencode text="$text" >/dev/null || true
}

on_error() {
  say "❌ Backup FAILED"
  tg_alert "🔴 <b>MACRO Contracts: бэкап НЕ удался</b>%0A$(date -u '+%Y-%m-%d %H:%M UTC')%0AПроверьте /var/log/macro-contracts-backup.log"
}
trap on_error ERR

# 1. Дамп БД
say "pg_dump → $DB_FILE"
docker compose exec -T db pg_dump -U "$DB_USER" -d "$DB_NAME" --clean --if-exists | gzip -9 > "$DB_FILE"

# 2. Бэкап storage volume (договоры .docx/.pdf, аватары, кастомный шаблон)
say "tar storage → $STORAGE_FILE"
docker compose exec -T api tar czf - -C /data storage 2>/dev/null > "$STORAGE_FILE" || {
  say "⚠ storage tar пустой или ошибка (возможно ещё нет файлов)"
}

# 3. Ротация: удаляем старше 14 дней
find "$DUMP_DIR" -name 'db-*.sql.gz' -type f -mtime +14 -delete
find "$DUMP_DIR" -name 'storage-*.tar.gz' -type f -mtime +14 -delete

DB_SIZE="$(du -h "$DB_FILE" | cut -f1)"
STORAGE_SIZE="$(du -h "$STORAGE_FILE" 2>/dev/null | cut -f1 || echo '0')"
say "✅ OK: db=$DB_SIZE, storage=$STORAGE_SIZE"

# Снимаем trap (успех)
trap - ERR
