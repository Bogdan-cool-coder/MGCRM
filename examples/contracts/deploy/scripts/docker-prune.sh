#!/usr/bin/env bash
# MACRO CRM — docker-prune.sh
# Safely removes stale build cache and dangling images on the shared VPS.
# NEVER touches volumes (safe for FinFamily and other co-hosted projects).
# Run via cron daily at 04:30 — see deploy/cron/macro-monitor.
#
# Guards:
#   1. Bail out if any core MACRO container is not running (mid-deploy protection).
#   2. Alert on failure; alert if disk is still >85% after prune.
#   3. `set -uo pipefail` inside main(), wrapped in a trap so cron never gets a
#      non-zero exit that would suppress the next scheduled run.

STATE_DIR="/var/lib/macro-monitor"
LOG_FILE="${STATE_DIR}/prune.log"
DISK_WARN_THRESHOLD="${DISK_WARN_THRESHOLD:-85}"  # alert if disk still above this after prune

# Core MACRO containers — if ANY is absent/not running, abort.
MACRO_CORE_CONTAINERS=(
    "macro-contracts-api"
    "macro-contracts-db"
    "macro-contracts-web"
)

# Source the alert helper (same pattern as health-check.sh).
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "${SCRIPT_DIR}/lib-telegram-alert.sh" ]; then
    # shellcheck source=lib-telegram-alert.sh
    source "${SCRIPT_DIR}/lib-telegram-alert.sh"
else
    send_alert() { :; }
fi

say() { echo "[$(date -u '+%Y-%m-%d %H:%M:%S')] docker-prune: $*"; }

# ── Helpers ──────────────────────────────────────────────────────────────────

disk_pct() {
    df / | awk 'NR==2{gsub(/%/,""); print $5}'
}

# Returns 0 if the named container is running (any health state), 1 otherwise.
container_running() {
    local name="$1"
    local status
    # Prefix match (NO ^...$ anchor): with scale=2 the api container is named
    # macro-contracts-api-192/193, so an exact anchor never matches. Prefix is safe.
    status="$(docker ps --filter "name=${name}" --format '{{.Status}}' 2>/dev/null | head -1)"
    [ -n "$status" ]
}

# ── Main ──────────────────────────────────────────────────────────────────────

main() {
    set -uo pipefail

    mkdir -p "$STATE_DIR"
    say "=== docker-prune start ==="

    # ── 1. Bail-out guard ────────────────────────────────────────────────────
    # If any core MACRO container is missing or stopped, skip pruning.
    # This protects against pruning during a half-broken or mid-deploy state
    # where images in use might be incorrectly flagged as unused.
    local cname
    for cname in "${MACRO_CORE_CONTAINERS[@]}"; do
        if ! container_running "$cname"; then
            say "ABORT: container '$cname' is not running — skipping prune (mid-deploy or outage?)"
            local host ts
            host="$(hostname -s 2>/dev/null || echo 'vps')"
            ts="$(date -u '+%Y-%m-%d %H:%M UTC')"
            send_alert "$(cat <<EOF
⚠️ <b>MG CRM docker-prune ABORTED</b>
<b>Host:</b> ${host}
<b>Time:</b> ${ts}

Container <code>${cname}</code> is not running — prune skipped to protect a possibly degraded stack.

<i>Check: ssh root@153.80.193.132 'cd /opt/macro-contracts &amp;&amp; docker compose ps'</i>
EOF
)"
            return 0  # exit 0 so cron doesn't mark the job as failed
        fi
    done
    say "bail-out guard passed — all core containers running"

    # ── 2. Disk before ──────────────────────────────────────────────────────
    local before after
    before="$(disk_pct)"
    say "disk before: ${before}%"

    # ── 3. Prune build cache (cap at 3GB to keep recent layers for speed) ───
    # --keep-storage=3GB: docker builder prune keeps the most-recently-used
    # layers up to 3 GB, evicts the rest.  This is the main disk culprit.
    # NO --volumes, NO docker system prune.
    say "pruning build cache (keep-storage=3GB)..."
    local builder_out
    builder_out="$(docker builder prune -f --keep-storage=3GB 2>&1)" || true
    say "builder prune done: $(echo "$builder_out" | grep -E 'Total reclaimed|reclaimed' | tail -1 || echo 'see log')"

    # ── 4. Prune dangling/unused images ─────────────────────────────────────
    # -a: removes ALL images not referenced by any container (safe — running
    # containers keep their images referenced; only truly unused images go).
    # NO --volumes.
    say "pruning unused images..."
    local image_out
    image_out="$(docker image prune -af 2>&1)" || true
    say "image prune done: $(echo "$image_out" | grep -E 'Total reclaimed|reclaimed' | tail -1 || echo 'see log')"

    # ── 5. Disk after ───────────────────────────────────────────────────────
    after="$(disk_pct)"
    say "disk after: ${after}% (was ${before}%)"
    say "=== docker-prune complete ==="

    # ── 6. Post-prune disk alert (something else eating disk if still high) ─
    if [ -n "$after" ] && [ "$after" -gt "$DISK_WARN_THRESHOLD" ]; then
        local host ts
        host="$(hostname -s 2>/dev/null || echo 'vps')"
        ts="$(date -u '+%Y-%m-%d %H:%M UTC')"
        say "WARNING: disk still ${after}% after prune — sending alert"
        send_alert "$(cat <<EOF
⚠️ <b>MG CRM disk still high after prune</b>
<b>Host:</b> ${host}
<b>Time:</b> ${ts}

Disk was <b>${before}%</b> before prune, <b>${after}%</b> after — still above ${DISK_WARN_THRESHOLD}%.
Something else is consuming disk (logs, uploads, DB growth?).

<i>ssh root@153.80.193.132 'du -sh /opt/macro-contracts /var/lib/docker /var/log'</i>
EOF
)"
    fi
}

# ── Error handler ─────────────────────────────────────────────────────────────
# Wraps main() so an unexpected error sends an alert and exits 0
# (cron reschedules regardless of exit code, but exit 0 keeps syslog clean).
main || {
    local host ts
    host="$(hostname -s 2>/dev/null || echo 'vps')"
    ts="$(date -u '+%Y-%m-%d %H:%M UTC')"
    say "UNEXPECTED ERROR — check script output above"
    send_alert "$(cat <<EOF
🔴 <b>MG CRM docker-prune FAILED</b>
<b>Host:</b> ${host}
<b>Time:</b> ${ts}

An unexpected error occurred in docker-prune.sh. Check the log:
<code>tail -50 /var/lib/macro-monitor/prune.log</code>
EOF
)"
    exit 0
}
