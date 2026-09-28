#!/usr/bin/env bash
set -Eeuo pipefail
BASE="$HOME/Games/FFXI"
API_PORT="${GEAR_CENTER_API_PORT:-8765}"
echo '===== FFXI Gear Center 1.0.2 Status ====='
"$BASE/scripts/gear-center-services.sh" status
TMP="$(mktemp)"; trap 'rm -f "$TMP"' EXIT
if curl -fsS --max-time 1 "http://127.0.0.1:$API_PORT/health" >"$TMP" 2>/dev/null; then
  python3 - "$TMP" <<'PY'
import json,sys
x=json.load(open(sys.argv[1]));print('Version       :',x.get('release','—'));print('Datenmodus    :',x.get('mode','—'));print('Live-Snapshot :','JA' if x.get('snapshot_available') else 'NEIN');print('Points        :','JA' if x.get('points_available') else 'NEIN');print('Augments      :','JA' if x.get('augment_available') else 'NEIN')
PY
fi
