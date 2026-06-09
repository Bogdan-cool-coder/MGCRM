#!/bin/bash
# ================================================================
# MACRO CRM — Database + storage backup (config-driven)
# Daily via cron: pg_dump → gzip → storage tar → S3 (opt) → rotation → Telegram
# Config: backup.conf next to this script. Secrets read from ENV_FILE at runtime.
# Ported from FinFamily gold-standard + MACRO storage-volume backup.
# ================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/backup.conf"

DATE=$(date +%Y-%m-%d_%H-%M)
BACKUP_FILE="${PROJECT_NAME}_${DATE}.sql.gz"
STORAGE_FILE="${PROJECT_NAME}_storage_${DATE}.tar.gz"
LOG_FILE="${BACKUP_DIR}/backup.log"

mkdir -p "$BACKUP_DIR"

# ─── Read secrets/values from .env (robust: handles spaces, no xargs) ───
get_env() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true; }
TELEGRAM_BOT_TOKEN="$(get_env "${TG_TOKEN_ENV:-TELEGRAM_BOT_TOKEN}")"
TELEGRAM_CHAT_ID="$(get_env "${TG_CHAT_ENV:-TELEGRAM_CHAT_ID}")"
S3_BUCKET="$(get_env S3_BUCKET)"
S3_ENDPOINT="$(get_env S3_ENDPOINT)"
S3_ACCESS_KEY="$(get_env S3_ACCESS_KEY)"
S3_SECRET_KEY="$(get_env S3_SECRET_KEY)"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" | tee -a "$LOG_FILE"; }

send_telegram() {
  local MESSAGE="$1"
  if [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ]; then
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
      -d chat_id="${TELEGRAM_CHAT_ID}" -d parse_mode="HTML" \
      --data-urlencode text="${MESSAGE}" > /dev/null 2>&1 || true
  fi
}

# Fail-loud: any unexpected error → Telegram + exit 1
on_error() {
  log "❌ Backup FAILED (unexpected error)"
  send_telegram "🔴 <b>${PROJECT_NAME} Backup FAILED</b>
Дата: ${DATE}
Проверьте ${LOG_FILE}"
}
trap on_error ERR

upload_to_s3() {
  local FILE="$1"
  [ -z "$S3_BUCKET" ] || [ -z "$S3_ENDPOINT" ] || [ -z "$S3_ACCESS_KEY" ] || [ -z "$S3_SECRET_KEY" ] && return 2
  AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
    aws s3 cp "${BACKUP_DIR}/${FILE}" "s3://${S3_BUCKET}/${FILE}" \
    --endpoint-url "https://${S3_ENDPOINT}" --no-progress 2>&1 | tee -a "$LOG_FILE"
  return "${PIPESTATUS[0]}"
}

# ─── Main ───
log "========================================="
log "Starting backup..."

# Step 1: pg_dump (inside container, trust auth — no password needed)
log "Running pg_dump..."
DUMP_START=$(date +%s)
docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" -d "$DB_NAME" \
  --format=plain --no-owner --no-privileges --clean --if-exists \
  | gzip > "${BACKUP_DIR}/${BACKUP_FILE}"
DUMP_DURATION=$(( $(date +%s) - DUMP_START ))

# Step 1b: size guard — failed dump is tiny
BACKUP_SIZE=$(stat -c%s "${BACKUP_DIR}/${BACKUP_FILE}" 2>/dev/null || stat -f%z "${BACKUP_DIR}/${BACKUP_FILE}")
if [ "$BACKUP_SIZE" -lt 1000 ]; then
  log "ERROR: dump too small (${BACKUP_SIZE} bytes) — likely failed!"
  send_telegram "🔴 <b>${PROJECT_NAME} Backup FAILED</b>
Дамп слишком маленький: ${BACKUP_SIZE} bytes
Дата: ${DATE}"
  trap - ERR
  exit 1
fi
BACKUP_SIZE_MB=$(echo "scale=2; $BACKUP_SIZE / 1048576" | bc)
log "  Dump OK in ${DUMP_DURATION}s — ${BACKUP_SIZE_MB} MB"

# Step 2: storage volume tar (MACRO: договоры/аватары/шаблон)
STORAGE_SIZE_MB="0"
if [ "${STORAGE_BACKUP:-0}" = "1" ]; then
  log "Archiving storage volume..."
  if (cd "$DEPLOY_DIR" && docker compose exec -T "${STORAGE_SERVICE:-api}" tar czf - -C /data storage) > "${BACKUP_DIR}/${STORAGE_FILE}" 2>>"$LOG_FILE"; then
    SS=$(stat -c%s "${BACKUP_DIR}/${STORAGE_FILE}" 2>/dev/null || stat -f%z "${BACKUP_DIR}/${STORAGE_FILE}")
    STORAGE_SIZE_MB=$(echo "scale=2; $SS / 1048576" | bc)
    log "  Storage OK — ${STORAGE_SIZE_MB} MB"
  else
    log "  ⚠ storage tar failed/empty (возможно ещё нет файлов) — продолжаем"
    rm -f "${BACKUP_DIR}/${STORAGE_FILE}"
  fi
fi

# Step 3: S3 off-site (optional — TODO for MACRO)
S3_STATUS="не настроен"
if [ -n "$S3_BUCKET" ]; then
  if upload_to_s3 "$BACKUP_FILE"; then
    S3_STATUS="загружен"
    [ "${STORAGE_BACKUP:-0}" = "1" ] && [ -f "${BACKUP_DIR}/${STORAGE_FILE}" ] && upload_to_s3 "$STORAGE_FILE" || true
  else
    S3_STATUS="ОШИБКА загрузки"
  fi
fi

# Step 4: rotation (db + storage)
log "Cleaning local backups older than ${RETENTION_DAYS}d..."
find "$BACKUP_DIR" -name "${PROJECT_NAME}_*.sql.gz" -type f -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true
find "$BACKUP_DIR" -name "${PROJECT_NAME}_storage_*.tar.gz" -type f -mtime +"${RETENTION_DAYS}" -delete 2>/dev/null || true

LOCAL_COUNT=$(find "$BACKUP_DIR" -name "${PROJECT_NAME}_*.sql.gz" | wc -l | tr -d ' ')

log "Backup completed: db=${BACKUP_SIZE_MB}MB storage=${STORAGE_SIZE_MB}MB s3=${S3_STATUS} local=${LOCAL_COUNT}"
log "========================================="

trap - ERR
send_telegram "✅ <b>${PROJECT_NAME} Backup OK</b>
📦 ${BACKUP_FILE}
💾 БД ${BACKUP_SIZE_MB} MB | 📁 storage ${STORAGE_SIZE_MB} MB | ⏱ ${DUMP_DURATION}s
☁️ S3: ${S3_STATUS}
📂 Локальных дампов: ${LOCAL_COUNT} / ${RETENTION_DAYS}"
