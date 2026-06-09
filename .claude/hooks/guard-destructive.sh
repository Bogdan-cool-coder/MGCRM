#!/usr/bin/env bash
# PreToolUse guard (matcher: Bash) — жёсткий блок критичного деструктива.
# Работает ДАЖЕ под permissionMode: bypassPermissions и для всех субагентов,
# т.к. PreToolUse-хуки выполняются независимо от режима разрешений.
#
# Логика: читаем команду из hook-JSON на stdin, если она матчит критичный
# паттерн — печатаем permissionDecision:"deny" с причиной (hard block).
# Иначе печатаем пустоту → команда проходит как обычно (тихо под bypass).
#
# Критичный список (железные правила CLAUDE.md — деструктив только вручную + бэкап):
#   - docker compose down -v / --volumes   (удаление volumes = потеря данных)
#   - docker volume rm / volume prune
#   - docker {image|system|builder} prune -a/--all/--volumes
#   - rm -rf по данным/системным/домашним путям
#   - SQL DROP DATABASE|SCHEMA|TABLE / TRUNCATE

input="$(cat)"

cmd="$(printf '%s' "$input" | python3 -c 'import sys,json
try:
    d=json.load(sys.stdin); print(d.get("tool_input",{}).get("command",""))
except Exception:
    print("")')"

[ -z "$cmd" ] && exit 0

lc="$(printf '%s' "$cmd" | tr "[:upper:]" "[:lower:]")"
m() { printf '%s' "$lc" | grep -Eq "$1"; }

reason=""

if m 'compose[^|;&]*\bdown\b[^|;&]*(-v\b|--volumes?\b)'; then
  reason="docker compose down -v/--volumes — удаление volumes (потеря данных БД)"
elif m '\bdocker\b[^|;&]*\bvolume\b[^|;&]*\b(rm|prune)\b'; then
  reason="docker volume rm/prune — удаление docker volumes"
elif m '\bdocker\b[^|;&]*\b(image|system|builder)\b[^|;&]*\bprune\b[^|;&]*(-a\b|--all\b|--volumes\b)'; then
  reason="docker prune -a/--all/--volumes — массовое удаление образов/кэша/данных"
elif m '\b(drop[[:space:]]+(database|schema|table)|truncate[[:space:]]+(table|only|"|[a-z]))'; then
  reason="SQL DROP DATABASE/SCHEMA/TABLE или TRUNCATE — удаление БД/таблиц"
elif m '\brm\b[[:space:]]+(-[a-z]*r[a-z]*f[a-z]*|-[a-z]*f[a-z]*r[a-z]*|-r[[:space:]]+-f|-f[[:space:]]+-r|--recursive[^|;&]*--force|--force[^|;&]*--recursive)'; then
  # rm -rf обнаружен; блокируем только по чувствительным путям (данные/система/home), /tmp не трогаем
  if m '(backup|volume|postgres|mysql|mariadb|redis|pgdata|_data\b|/var/lib/docker|[[:space:]]/(home|etc|var|usr|root|boot|srv|opt|bin|lib)([[:space:]]|/|$)|[[:space:]]/([[:space:]]|$)|[[:space:]]~|\$home)'; then
    reason="rm -rf по данным/системным/домашним путям"
  fi
fi

if [ -n "$reason" ]; then
  python3 -c 'import json,sys
print(json.dumps({"hookSpecificOutput":{
  "hookEventName":"PreToolUse",
  "permissionDecision":"deny",
  "permissionDecisionReason":"⛔ GUARD: критичная операция заблокирована — "+sys.argv[1]+". Деструктив только вручную по явной просьбе и после бэкапа (CLAUDE.md). Чтобы выполнить осознанно: запусти сам в терминале или временно отключи правило в ~/.claude/hooks/guard-destructive.sh."
}}))' "$reason"
fi

exit 0
