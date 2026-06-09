#!/usr/bin/env bash
# MACRO CRM — health check. Run via cron every 30 min.
# Checks: disk usage, container states, /api/health endpoint.
# Silent when everything is healthy; alerts only on problems.
# Deduplicates persistent problems: re-alerts after RENOTIFY_HOURS (default 4h).
#
# Cron entry (managed by deploy/cron/macro-monitor):
#   */30 * * * * root /opt/macro-contracts/deploy/scripts/health-check.sh >> /var/lib/macro-monitor/monitor.log 2>&1

set -uo pipefail

COMPOSE_FILE="/opt/macro-contracts/docker-compose.yml"
STATE_DIR="/var/lib/macro-monitor"
HEALTH_STATE_FILE="${STATE_DIR}/last-health-alert.state"  # stores: "<md5> <epoch>"
RENOTIFY_HOURS="${RENOTIFY_HOURS:-4}"   # re-alert on a persistent unchanged problem after N hours
DISK_THRESHOLD="${DISK_THRESHOLD:-90}"  # percent

# Source the alert helper
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/lib-telegram-alert.sh" ]; then
    # shellcheck source=lib-telegram-alert.sh
    source "${SCRIPT_DIR}/lib-telegram-alert.sh"
else
    send_alert() { :; }
fi

say() { echo "[$(date -u '+%H:%M:%S')] health-check: $*"; }

# ── Helpers ─────────────────────────────────────────────────────────────────

# Returns "running" / "unhealthy" / "exited" / "missing" for a named container.
container_status() {
    local name="$1"
    local id
    id="$(docker ps -a --filter "name=^${name}$" --format '{{.ID}}' 2>/dev/null | head -1)"
    if [ -z "$id" ]; then
        echo "missing"
        return
    fi
    docker inspect --format \
        '{{.State.Status}}{{if .State.Health}}/{{.State.Health.Status}}{{end}}' \
        "$id" 2>/dev/null || echo "inspect-error"
}

# Returns "<healthy>/<total>" for the api compose service (scale=2, no fixed container_name).
api_replica_summary() {
    local ids healthy=0 total=0
    ids="$(docker compose -f "$COMPOSE_FILE" ps -q api 2>/dev/null || true)"
    local id
    for id in $ids; do
        total=$((total + 1))
        local st
        st="$(docker inspect --format \
            '{{.State.Status}}{{if .State.Health}}/{{.State.Health.Status}}{{end}}' \
            "$id" 2>/dev/null || echo 'inspect-error')"
        # Accept running/healthy, running/none (no healthcheck), running/starting
        case "$st" in
            running/healthy|running/none|running/starting|running) healthy=$((healthy + 1)) ;;
        esac
    done
    echo "${healthy}/${total}"
}

main() {
    mkdir -p "$STATE_DIR"

    local problems=""

    # ── 1. Disk usage ────────────────────────────────────────────────────────
    local disk_pct
    disk_pct="$(df / | awk 'NR==2{gsub(/%/,""); print $5}')"
    if [ -n "$disk_pct" ] && [ "$disk_pct" -gt "$DISK_THRESHOLD" ]; then
        problems="${problems}\n• <b>Disk /</b>: ${disk_pct}% used (threshold ${DISK_THRESHOLD}%)"
    fi

    # ── 2. Fixed-name containers ─────────────────────────────────────────────
    # api is handled separately below (replicas, no fixed name).
    local fixed_containers=(
        "macro-contracts-db"
        "macro-contracts-redis"
        "macro-contracts-web"
        "macro-contracts-bot"
        "macro-contracts-onlyoffice"
    )

    local cname cst
    for cname in "${fixed_containers[@]}"; do
        cst="$(container_status "$cname")"
        case "$cst" in
            running/healthy|running/none|running/starting|running)
                # healthy — silent
                ;;
            *)
                problems="${problems}\n• <b>${cname}</b>: ${cst}"
                ;;
        esac
    done

    # ── 3. API replicas ──────────────────────────────────────────────────────
    local api_summary
    api_summary="$(api_replica_summary)"
    local api_healthy api_total
    api_healthy="${api_summary%/*}"
    api_total="${api_summary#*/}"

    if [ "$api_total" -eq 0 ]; then
        problems="${problems}\n• <b>api</b>: no containers found"
    elif [ "$api_healthy" -lt 1 ]; then
        problems="${problems}\n• <b>api</b>: 0 healthy replicas (${api_summary})"
    elif [ "$api_healthy" -lt "$api_total" ]; then
        problems="${problems}\n• <b>api</b>: degraded — ${api_summary} healthy"
    fi

    # ── 4. /api/health endpoint ──────────────────────────────────────────────
    local http_code
    http_code="$(curl -sS -o /dev/null -w '%{http_code}' \
        --max-time 10 \
        https://contracts.macroglobal.tech/api/health 2>/dev/null || echo '000')"
    if [ "$http_code" != "200" ]; then
        problems="${problems}\n• <b>/api/health</b>: HTTP ${http_code} (expected 200)"
    fi

    # ── Nothing wrong ────────────────────────────────────────────────────────
    if [ -z "$problems" ]; then
        say "all healthy (disk=${disk_pct}%, api=${api_summary})"
        return 0
    fi

    # ── Dedup: suppress re-alert if same problem block within RENOTIFY_HOURS ─
    local current_md5
    current_md5="$(printf '%s' "$problems" | md5sum | awk '{print $1}')"

    local prev_md5="" prev_epoch=0
    if [ -f "$HEALTH_STATE_FILE" ]; then
        prev_md5="$(awk '{print $1}' "$HEALTH_STATE_FILE")"
        prev_epoch="$(awk '{print $2}' "$HEALTH_STATE_FILE")"
    fi

    local now_epoch
    now_epoch="$(date +%s)"
    local renotify_secs=$(( RENOTIFY_HOURS * 3600 ))

    if [ "$current_md5" = "$prev_md5" ]; then
        local elapsed=$(( now_epoch - prev_epoch ))
        if [ "$elapsed" -lt "$renotify_secs" ]; then
            say "same problem block, last alerted ${elapsed}s ago — suppressing (renotify after ${renotify_secs}s)"
            return 0
        fi
        say "same problem block but ${elapsed}s elapsed — re-alerting"
    fi

    # ── Format and send ──────────────────────────────────────────────────────
    local host
    host="$(hostname -s 2>/dev/null || echo 'vps')"
    local ts
    ts="$(date -u '+%Y-%m-%d %H:%M UTC')"

    local msg
    # Use printf to expand \n in problems
    local problems_expanded
    problems_expanded="$(printf '%b' "$problems")"

    msg="$(cat <<EOF
🔴 <b>MG CRM health check FAILED</b>
<b>Host:</b> ${host}
<b>Time:</b> ${ts}

${problems_expanded}

<i>ssh root@153.80.193.132 'cd /opt/macro-contracts &amp;&amp; docker compose ps'</i>
EOF
)"

    say "sending health alert"
    send_alert "$msg"

    # Update dedup state
    printf '%s %s\n' "$current_md5" "$now_epoch" > "$HEALTH_STATE_FILE"
}

main || {
    echo "[$(date -u '+%H:%M:%S')] health-check: UNEXPECTED ERROR in main — check script" >&2
}
