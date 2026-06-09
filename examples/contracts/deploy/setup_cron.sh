#!/usr/bin/env bash
# Устанавливает cron-задачи для backup и watchdog на VPS. Идемпотентен.
# Запускать НА VPS: bash /opt/macro-contracts/deploy/setup_cron.sh

set -euo pipefail

chmod +x /opt/macro-contracts/deploy/backup.sh /opt/macro-contracts/deploy/watchdog.sh

BACKUP_LINE="0 3 * * * /opt/macro-contracts/deploy/backup.sh >> /var/log/macro-contracts-backup.log 2>&1"
WATCHDOG_LINE="* * * * * /opt/macro-contracts/deploy/watchdog.sh >> /var/log/macro-contracts-watchdog.log 2>&1"
CATEGORIES_LINE="0 4 * * * /opt/macro-contracts/deploy/recompute_categories.sh >> /var/log/macro-contracts-categories.log 2>&1"

# Текущий crontab (или пусто)
current="$(crontab -l 2>/dev/null || true)"

new="$current"
# Полные пути — иначе grep ловит watchdog/backup других проектов на этом же VPS
echo "$current" | grep -qF "/opt/macro-contracts/deploy/backup.sh" || new="$new
$BACKUP_LINE"
echo "$current" | grep -qF "/opt/macro-contracts/deploy/watchdog.sh" || new="$new
$WATCHDOG_LINE"
echo "$current" | grep -qF "/opt/macro-contracts/deploy/recompute_categories.sh" || new="$new
$CATEGORIES_LINE"

echo "$new" | grep -v '^$' | crontab -

echo "Cron установлен:"
crontab -l | grep macro-contracts
