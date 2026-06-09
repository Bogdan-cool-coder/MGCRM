#!/usr/bin/env bash
# Sourced helper — Telegram alert delivery for MACRO CRM monitoring scripts.
# Usage: source this file, then call: send_alert "<html-message>"
#
# Required env vars (read from /opt/macro-contracts/.env):
#   TELEGRAM_ALERT_BOT_TOKEN  — token of the dedicated alert bot (NOT the approval bot)
#   TELEGRAM_ALERT_CHAT_ID    — chat/group ID to receive alerts
#
# Behavior when vars are unset: prints a warning to stderr, returns 0 (never fails the caller).

# Safely extract a value from /opt/macro-contracts/.env without sourcing the whole file
# (sourcing breaks on values with spaces, newlines, or special chars).
_macro_get_env() {
    grep -E "^${1}=" /opt/macro-contracts/.env 2>/dev/null \
        | head -1 \
        | cut -d= -f2- \
        | sed 's/^"//;s/"$//' \
        || true
}

# HTML-escape &, <, > in a string — required for parse_mode=HTML payloads.
# Usage: _macro_html_escape "some & text <with> specials"
_macro_html_escape() {
    local s="$1"
    s="${s//&/&amp;}"
    s="${s//</&lt;}"
    s="${s//>/&gt;}"
    printf '%s' "$s"
}

# send_alert "<html-message>"
# Reads credentials fresh from .env on every call so hot-rotation of the token works.
# Truncates to 3900 chars before sending (TG hard limit is 4096; leave room for overhead).
send_alert() {
    local raw_text="${1:-}"

    local token
    token="$(_macro_get_env TELEGRAM_ALERT_BOT_TOKEN)"
    local chat_id
    chat_id="$(_macro_get_env TELEGRAM_ALERT_CHAT_ID)"

    if [ -z "$token" ] || [ -z "$chat_id" ]; then
        echo "[macro-alert] WARNING: TELEGRAM_ALERT_BOT_TOKEN or TELEGRAM_ALERT_CHAT_ID not set in .env — alert suppressed." >&2
        return 0
    fi

    # Truncate to 3900 chars (UTF-8 safe enough for bash substring)
    local text="${raw_text:0:3900}"
    if [ "${#raw_text}" -gt 3900 ]; then
        text="${text}
<i>... (truncated)</i>"
    fi

    # POST via curl; --data-urlencode handles arbitrary chars in the text body.
    # || true: never let a network error crash the calling script.
    curl -sS \
        -X POST "https://api.telegram.org/bot${token}/sendMessage" \
        -d "chat_id=${chat_id}" \
        -d "parse_mode=HTML" \
        -d "disable_web_page_preview=true" \
        --data-urlencode "text=${text}" \
        -o /dev/null \
        || true
}
