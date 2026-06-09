#!/bin/bash
# ================================================================
# Monthly Restore Test (config-driven wrapper)
# Runs via cron on 1st of each month — proves backups are RESTORABLE.
# Restores latest backup to a temp DB, verifies, drops it. Non-destructive.
# ================================================================

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] Starting monthly restore test..."
bash "${SCRIPT_DIR}/restore.sh" test
echo "[$(date '+%Y-%m-%d %H:%M:%S')] Monthly restore test completed."
