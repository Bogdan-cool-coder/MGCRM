# MACRO CRM — Monitoring install guide

Run all steps on the VPS as root (`ssh root@153.80.193.132`).

---

## 1. Add required env vars to `.env`

Main session adds these to `/opt/macro-contracts/.env`.
Do NOT reuse `TELEGRAM_BOT_TOKEN` (that is the contracts approval bot):

```
TELEGRAM_ALERT_BOT_TOKEN=<token of the new alert bot>
TELEGRAM_ALERT_CHAT_ID=<chat or group ID to receive alerts>
```

---

## 2. Create state directory

```bash
mkdir -p /var/lib/macro-monitor
```

---

## 3. Make scripts executable

```bash
chmod +x /opt/macro-contracts/deploy/scripts/log-monitor.sh
chmod +x /opt/macro-contracts/deploy/scripts/health-check.sh
chmod +x /opt/macro-contracts/deploy/scripts/docker-prune.sh
# lib-telegram-alert.sh is sourced, not executed directly — no chmod needed
```

---

## 4. Install cron.d file

```bash
cp /opt/macro-contracts/deploy/cron/macro-monitor /etc/cron.d/macro-monitor
chmod 644 /etc/cron.d/macro-monitor
```

Verify cron picked it up (give it a few seconds):

```bash
grep macro-monitor /var/log/syslog | tail -5
```

---

## 5. Smoke test — log monitor

Seed a fake error into the api log, then run the script manually:

```bash
# Inject a fake error line
docker exec $(docker compose -f /opt/macro-contracts/docker-compose.yml ps -q api | head -1) \
    sh -c 'echo "CRITICAL fake test error for monitoring smoke test" >&2'

# Wait ~5s for log to flush, then run
bash /opt/macro-contracts/deploy/scripts/log-monitor.sh
```

You should receive a Telegram message. Run it a second time immediately — it should be suppressed (dedup).

---

## 6. Smoke test — health check

```bash
bash /opt/macro-contracts/deploy/scripts/health-check.sh
```

When healthy: no alert, output ends with "all healthy". To force an alert, temporarily stop a container and re-run.

---

## 7. Docker prune (daily disk housekeeping)

`deploy/scripts/docker-prune.sh` is covered by the same `/etc/cron.d/macro-monitor` file (added automatically when you copy it in step 4 above). No separate cron file needed.

The script runs daily at **04:30** (server local time), before FinFamily's weekly host-wide prune at Sunday 05:00, and:
- **Bails out silently** (with a Telegram warning) if any of `macro-contracts-api`, `macro-contracts-db`, or `macro-contracts-web` is not running — protects a degraded or mid-deploy stack.
- Runs `docker builder prune -f --keep-storage=3GB` — caps build cache at 3 GB, keeps recent layers for faster rebuilds.
- Runs `docker image prune -af` — removes all images not referenced by any running container.
- **Never uses `--volumes`, `docker system prune`, or anything that touches named volumes** — safe for FinFamily and MACRO data.
- Alerts on failure and if disk is still above `DISK_WARN_THRESHOLD` (default 85%) after pruning (indicates a non-Docker disk culprit).
- Logs to `/var/lib/macro-monitor/prune.log` (separate from `monitor.log`).

The only VPS step needed after deploy:

```bash
chmod +x /opt/macro-contracts/deploy/scripts/docker-prune.sh
# Then re-copy the cron file if it was already installed before this script was added:
cp /opt/macro-contracts/deploy/cron/macro-monitor /etc/cron.d/macro-monitor
chmod 644 /etc/cron.d/macro-monitor
```

To run manually (dry-run behavior check, no real prune triggered unless you confirm):

```bash
bash /opt/macro-contracts/deploy/scripts/docker-prune.sh
```

Optional env override (set in shell before running, not in .env):

```bash
DISK_WARN_THRESHOLD=80 bash /opt/macro-contracts/deploy/scripts/docker-prune.sh
```

---

## State files (dedup)

| File | Purpose |
|------|---------|
| `/var/lib/macro-monitor/last-log-alert.md5` | MD5 of last sent error block — prevents repeat log alerts for the same burst |
| `/var/lib/macro-monitor/last-health-alert.state` | `<md5> <epoch>` — suppresses health re-alerts for `RENOTIFY_HOURS` (default 4h) |
| `/var/lib/macro-monitor/monitor.log` | Combined stdout/stderr from health-check and log-monitor cron runs |
| `/var/lib/macro-monitor/prune.log` | Stdout/stderr from docker-prune cron runs (separate file, written by both cron redirect and the script itself) |

To reset dedup and force a re-alert on next run:

```bash
rm -f /var/lib/macro-monitor/last-log-alert.md5
rm -f /var/lib/macro-monitor/last-health-alert.state
```

---

## Env vars summary

| Var | Set in | Purpose |
|-----|--------|---------|
| `TELEGRAM_ALERT_BOT_TOKEN` | `/opt/macro-contracts/.env` | Dedicated alert bot token |
| `TELEGRAM_ALERT_CHAT_ID` | `/opt/macro-contracts/.env` | Target chat/group for all monitoring alerts |
| `RENOTIFY_HOURS` | optional, shell env | Hours before re-alerting on same health problem (default: 4) |
| `DISK_THRESHOLD` | optional, shell env | Root disk % threshold for disk alert (default: 90) |
