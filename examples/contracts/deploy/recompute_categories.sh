#!/usr/bin/env bash
# Ночной пересчёт категорий клиентов по обороту. Ставится в cron (setup_cron.sh).
set -euo pipefail
cd /opt/macro-contracts
docker compose exec -T api python -m app.jobs.recompute_categories
