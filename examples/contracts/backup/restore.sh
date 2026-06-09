#!/bin/bash
# ================================================================
# MACRO CRM — Database restore (config-driven)
# Usage:
#   ./restore.sh                 — restore latest local backup (DESTRUCTIVE, confirms)
#   ./restore.sh 2026-06-07      — restore specific date
#   ./restore.sh s3 2026-06-07   — download from S3 and restore
#   ./restore.sh test            — restore to test DB (non-destructive integrity check)
#   ./restore.sh test 2026-06-07 — specific date to test DB
# Config: backup.conf next to this script.
# ================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/backup.conf"

get_env() { grep -E "^$1=" "$ENV_FILE" 2>/dev/null | head -1 | cut -d= -f2- | sed 's/^"//;s/"$//' || true; }
TELEGRAM_BOT_TOKEN="$(get_env "${TG_TOKEN_ENV:-TELEGRAM_BOT_TOKEN}")"
TELEGRAM_CHAT_ID="$(get_env "${TG_CHAT_ENV:-TELEGRAM_CHAT_ID}")"
S3_BUCKET="$(get_env S3_BUCKET)"
S3_ENDPOINT="$(get_env S3_ENDPOINT)"
S3_ACCESS_KEY="$(get_env S3_ACCESS_KEY)"
S3_SECRET_KEY="$(get_env S3_SECRET_KEY)"

# ─── Parse args ───
MODE="restore"; TARGET_DATE=""
for arg in "$@"; do
  case "$arg" in
    test) MODE="test" ;;
    s3)   MODE="s3" ;;
    *)    TARGET_DATE="$arg" ;;
  esac
done

send_telegram() {
  local MESSAGE="$1"
  if [ -n "${TELEGRAM_BOT_TOKEN:-}" ] && [ -n "${TELEGRAM_CHAT_ID:-}" ]; then
    curl -s -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
      -d chat_id="${TELEGRAM_CHAT_ID}" -d parse_mode="HTML" \
      --data-urlencode text="${MESSAGE}" > /dev/null 2>&1 || true
  fi
}

find_backup() {
  local P="$1"
  if [ -n "$P" ]; then
    ls -t "${BACKUP_DIR}"/${PROJECT_NAME}_${P}*.sql.gz 2>/dev/null | grep -v '_storage_' | head -1
  else
    ls -t "${BACKUP_DIR}"/${PROJECT_NAME}_*.sql.gz 2>/dev/null | grep -v '_storage_' | head -1
  fi
}

download_from_s3() {
  local P="$1"
  [ -z "${S3_BUCKET:-}" ] || [ -z "${S3_ENDPOINT:-}" ] && { echo "ERROR: S3 not configured"; exit 1; }
  local F
  F=$(AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
    aws s3 ls "s3://${S3_BUCKET}/" --endpoint-url "https://${S3_ENDPOINT}" \
    | awk '{print $4}' | grep "${PROJECT_NAME}_${P}" | grep -v '_storage_' | sort -r | head -1)
  [ -z "$F" ] && { echo "ERROR: no S3 backup matching '${P}'"; exit 1; }
  mkdir -p "$BACKUP_DIR"
  AWS_ACCESS_KEY_ID="$S3_ACCESS_KEY" AWS_SECRET_ACCESS_KEY="$S3_SECRET_KEY" \
    aws s3 cp "s3://${S3_BUCKET}/${F}" "${BACKUP_DIR}/${F}" --endpoint-url "https://${S3_ENDPOINT}"
  echo "${BACKUP_DIR}/${F}"
}

echo "============================================"
echo "  ${PROJECT_NAME} restore | mode=${MODE} | date=${TARGET_DATE:-latest}"
echo "============================================"

if [ "$MODE" = "s3" ]; then
  BACKUP_FILE=$(download_from_s3 "$TARGET_DATE"); MODE="restore"
else
  BACKUP_FILE=$(find_backup "$TARGET_DATE")
fi

if [ -z "${BACKUP_FILE:-}" ] || [ ! -f "$BACKUP_FILE" ]; then
  echo "ERROR: no backup file found. Available:"
  ls -lh "${BACKUP_DIR}"/${PROJECT_NAME}_*.sql.gz 2>/dev/null | grep -v '_storage_' || echo "  (none)"
  exit 1
fi

BACKUP_SIZE=$(stat -c%s "$BACKUP_FILE" 2>/dev/null || stat -f%z "$BACKUP_FILE")
BACKUP_SIZE_MB=$(echo "scale=2; $BACKUP_SIZE / 1048576" | bc)
BACKUP_NAME=$(basename "$BACKUP_FILE")
echo "File: ${BACKUP_NAME} (${BACKUP_SIZE_MB} MB)"

RESTORE_DB="$DB_NAME"
if [ "$MODE" = "test" ]; then
  RESTORE_DB="$TEST_DB_NAME"
  echo "TEST MODE → DB '${RESTORE_DB}' (non-destructive)"
  docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS ${TEST_DB_NAME};" \
    -c "CREATE DATABASE ${TEST_DB_NAME} OWNER ${DB_USER};" 2>/dev/null
else
  echo "⚠️  This OVERWRITES production DB '${DB_NAME}'!"
  read -p "Type 'RESTORE' to confirm: " CONFIRM
  [ "$CONFIRM" != "RESTORE" ] && { echo "Aborted."; exit 0; }
  echo "Stopping backend (${BACKEND_SERVICES:-})..."
  (cd "$DEPLOY_DIR" && docker compose stop ${BACKEND_SERVICES:-} ) 2>/dev/null || true
fi

echo "Restoring → ${RESTORE_DB}..."
RESTORE_START=$(date +%s)
gunzip -c "$BACKUP_FILE" | docker exec -i "$DB_CONTAINER" psql \
  -U "$DB_USER" -d "$RESTORE_DB" --single-transaction --set ON_ERROR_STOP=off 2>&1 | tail -5
RESTORE_DURATION=$(( $(date +%s) - RESTORE_START ))

TABLE_COUNT=$(docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d "$RESTORE_DB" -t \
  -c "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE';" | tr -d ' ')
echo "Tables: ${TABLE_COUNT}"

if [ "$MODE" = "test" ]; then
  docker exec "$DB_CONTAINER" psql -U "$DB_USER" -d postgres \
    -c "DROP DATABASE IF EXISTS ${TEST_DB_NAME};" 2>/dev/null
  echo "Test DB dropped."
  send_telegram "🧪 <b>${PROJECT_NAME} Restore Test OK</b>
📦 ${BACKUP_NAME} (${BACKUP_SIZE_MB} MB)
⏱ ${RESTORE_DURATION}s | 📊 ${TABLE_COUNT} таблиц
✅ Целостность подтверждена"
else
  echo "Starting backend..."
  (cd "$DEPLOY_DIR" && docker compose start ${BACKEND_SERVICES:-} ) 2>/dev/null || true
  sleep 3
  send_telegram "🔄 <b>${PROJECT_NAME} Database Restored</b>
📦 ${BACKUP_NAME} (${BACKUP_SIZE_MB} MB)
⏱ ${RESTORE_DURATION}s | 📊 ${TABLE_COUNT} таблиц"
fi

echo "============================================"
echo "  Done in ${RESTORE_DURATION}s — ${TABLE_COUNT} tables"
echo "============================================"
