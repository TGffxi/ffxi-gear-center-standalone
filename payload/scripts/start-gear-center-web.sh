#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${GEAR_CENTER_LIFECYCLE:-}" == "1" ]] || {
  echo "Dieser interne Gear-Center-Dienst wird ausschließlich vom FFXI-Center-Lifecycle gestartet." >&2
  exit 1
}
BASE="$HOME/Games/FFXI"
APP="$BASE/tools/ffxi-gear-center"
PORT="${GEAR_CENTER_WEB_PORT:-8787}"
export GEARCENTER_RUNTIME_OVERLAY="$BASE/data/Gearplaner/runtime-planning-overlay.json"
cd "$APP"
# Explicit document root is required: router.php returns false for static files.
exec php -S "127.0.0.1:$PORT" -t public public/router.php
