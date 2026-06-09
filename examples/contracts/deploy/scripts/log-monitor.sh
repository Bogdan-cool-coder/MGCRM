#!/usr/bin/env bash
# MACRO CRM — log monitor. Run via cron every 15 min.
# Greps api + bot container logs for errors; deduplicates by MD5; sends Telegram alert.
#
# Cron entry (managed by deploy/cron/macro-monitor):
#   */15 * * * * root /opt/macro-contracts/deploy/scripts/log-monitor.sh >> /var/lib/macro-monitor/monitor.log 2>&1

# Guard: never let set -e kill the cron job — wrap everything.
set -uo pipefail

COMPOSE_FILE="/opt/macro-contracts/docker-compose.yml"
STATE_DIR="/var/lib/macro-monitor"
LAST_MD5_FILE="${STATE_DIR}/last-log-alert.md5"

# Source the alert helper (no-op if file missing — inert until installed properly).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/lib-telegram-alert.sh" ]; then
    # shellcheck source=lib-telegram-alert.sh
    source "${SCRIPT_DIR}/lib-telegram-alert.sh"
else
    send_alert() { :; }   # fallback no-op
fi

say() { echo "[$(date -u '+%H:%M:%S')] log-monitor: $*"; }

main() {
    mkdir -p "$STATE_DIR"

    # Collect logs from BOTH api replicas + bot for the last 16 min.
    # 16 min window (cron is every 15) avoids a 1-min blind spot on clock jitter.
    local raw_logs
    raw_logs="$(docker compose -f "$COMPOSE_FILE" logs --since 16m api bot 2>/dev/null || true)"

    if [ -z "$raw_logs" ]; then
        say "no logs collected (compose not running or empty window)"
        return 0
    fi

    # ── Error patterns to catch ─────────────────────────────────────────────
    local error_patterns=(
        "Traceback (most recent call last)"
        " ERROR "
        "CRITICAL"
        "Exception"
        "OperationalError"
        "ECONNREFUSED"
        "RuntimeError"
        "500 Internal Server Error"
        # Sentry init failures (not normal «Sentry enabled/disabled» info lines)
        "sentry_sdk.*error"
        "Failed to configure Sentry"
    )

    # ── Noise filter: lines to discard even if they match an error pattern ──
    # 1. Uvicorn access log for health probe   GET /api/health …
    # 2. "Sentry enabled" / "Sentry disabled" info lines
    # 3. Docker healthcheck pings (127.0.0.1 GET /api/health)
    # 4. Traefik / load-balancer liveness probes (often come from 172.x.x.x)
    local noise_patterns=(
        'GET /api/health'
        'Sentry (enabled|disabled|not configured)'
        'sentry_sdk\.errors\.SentryHttpError'   # spurious SDK noise, not app errors
        'HealthCheck'
        'healthcheck'
    )

    # Build grep for error patterns (extended regex, ignore-case for some)
    local pattern_arg
    pattern_arg="$(printf '%s\n' "${error_patterns[@]}" | paste -sd'|' -)"

    local noise_arg
    noise_arg="$(printf '%s\n' "${noise_patterns[@]}" | paste -sd'|' -)"

    # Filter: match errors, then remove noise lines
    local matched_lines
    matched_lines="$(
        printf '%s\n' "$raw_logs" \
        | grep -E "$pattern_arg" \
        | grep -vE "$noise_arg" \
        || true
    )"

    if [ -z "$matched_lines" ]; then
        say "no errors found"
        return 0
    fi

    local error_count
    error_count="$(printf '%s\n' "$matched_lines" | wc -l | tr -d ' ')"

    # ── MD5 dedup: skip alert if this exact block was already sent ──────────
    local current_md5
    current_md5="$(printf '%s' "$matched_lines" | md5sum | awk '{print $1}')"

    local prev_md5=""
    [ -f "$LAST_MD5_FILE" ] && prev_md5="$(cat "$LAST_MD5_FILE")"

    if [ "$current_md5" = "$prev_md5" ]; then
        say "same error block as last alert — skipping (dedup)"
        return 0
    fi

    # ── Format and send alert ────────────────────────────────────────────────
    local host
    host="$(hostname -s 2>/dev/null || echo 'vps')"

    local ts
    ts="$(date -u '+%Y-%m-%d %H:%M UTC')"

    # Limit to first 15 matched lines to keep message readable
    local preview
    preview="$(printf '%s\n' "$matched_lines" | head -15)"

    # HTML-escape the dynamic content
    local escaped_preview
    escaped_preview="$(_macro_html_escape "$preview")"

    local msg
    msg="$(cat <<EOF
🚨 <b>MG CRM api/bot: ${error_count} error(s) in last 15m</b>
<b>Host:</b> ${host}
<b>Time:</b> ${ts}

<pre>${escaped_preview}</pre>

<i>ssh root@153.80.193.132 'cd /opt/macro-contracts &amp;&amp; docker compose logs --since 1h api'</i>
EOF
)"

    say "sending alert: ${error_count} error(s)"
    send_alert "$msg"

    # Update dedup state only after a successful send attempt
    printf '%s' "$current_md5" > "$LAST_MD5_FILE"
}

# Wrap in a subshell so any unexpected exit doesn't kill the cron job
main || {
    echo "[$(date -u '+%H:%M:%S')] log-monitor: UNEXPECTED ERROR in main — check script" >&2
}
