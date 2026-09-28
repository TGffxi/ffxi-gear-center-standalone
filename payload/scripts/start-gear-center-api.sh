#!/usr/bin/env bash
set -Eeuo pipefail
[[ "${GEAR_CENTER_LIFECYCLE:-}" == "1" ]] || {
  echo "Dieser interne Gear-Center-Dienst wird ausschließlich vom FFXI-Center-Lifecycle gestartet." >&2
  exit 1
}
BASE="$HOME/Games/FFXI"
RUNTIME="$BASE/tools/Gearplaner-1.0"
STATE="$BASE/data/Gearplaner"
APP="$BASE/tools/ffxi-gear-center"
OVERLAY="$STATE/runtime-planning-overlay.json"
PORT="${GEAR_CENTER_API_PORT:-8765}"
LOGDIR="$BASE/logs/gear-center"
mkdir -p "$STATE" "$LOGDIR"
[[ -f "$STATE/requirements.json" ]] || printf '{"schema":"gearplaner.requirements/0.6","tasks":[]}\n' > "$STATE/requirements.json"
[[ -f "$STATE/goals.json" ]] || printf '{"schema":"gearplaner.goals/0.6","goals":[]}\n' > "$STATE/goals.json"
# First synchronize the latest complete local LuAshitacast v2 snapshot into the
# historical SQLite world used by item details/material holdings.  This is local,
# server-free and last-known-good safe: an empty/partial/older frame is preserved
# as a warning and never clears the previous valid SQLite inventory.
if [[ -x "$APP/shared/bin/php" && -f "$APP/bin/sync-live-sqlite.php" ]]; then
  "$APP/shared/bin/php" "$APP/bin/sync-live-sqlite.php" >>"$LOGDIR/live-sqlite-sync.log" 2>&1 ||     echo "Live SQLite sync fehlgeschlagen; letzter gültiger DB-Stand bleibt erhalten. Log: $LOGDIR/live-sqlite-sync.log" >&2
fi

# Synchronize the existing SQLite goal/To-Do world before the API starts. This
# command only reads/writes local data; it does not open any listening socket.
if [[ -x "$APP/shared/bin/php" && -f "$APP/bin/export-runtime-planning-overlay.php" ]]; then
  GEARCENTER_RUNTIME_OVERLAY="$OVERLAY" "$APP/shared/bin/php" "$APP/bin/export-runtime-planning-overlay.php" --output="$OVERLAY" \
    >>"$LOGDIR/overlay.log" 2>&1 || {
      echo "Runtime planning overlay konnte nicht synchronisiert werden. Log: $LOGDIR/overlay.log" >&2
      exit 1
    }
fi
CHARACTER_ARGS=()
if [[ -s "$STATE/character.txt" ]]; then
  CHARACTER="$(tr -d '\r\n' < "$STATE/character.txt")"
  [[ -z "$CHARACTER" ]] || CHARACTER_ARGS=(--character "$CHARACTER")
fi

exec python3 "$RUNTIME/bridge/server.py" \
  --requirements "$STATE/requirements.json" \
  --goals "$STATE/goals.json" \
  --performance-cache "$STATE/performance-cache.json" \
  --catalog-db "$BASE/tools/ffxi-gear-center/data/gear-center.sqlite" \
  --runtime-overlay "$OVERLAY" \
  --gearlab-root "$BASE/tools/FFXI-Gear-Lab" \
  --kastra-python "$BASE/tools/IzaKastra-wsdist/.venv/bin/python" \
  "${CHARACTER_ARGS[@]}" \
  --port "$PORT"
