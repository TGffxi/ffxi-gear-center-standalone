#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d -t gc102standalone.XXXXXX)"
OLDHOME="$HOME"
cleanup(){
  set +e
  if [[ -n "${HOME:-}" && -x "$HOME/Games/FFXI/scripts/gear-center-services.sh" ]]; then
    timeout -k 2 10 "$HOME/Games/FFXI/scripts/gear-center-services.sh" stop >/dev/null 2>&1 || true
  fi
  [[ "${FOREIGN:-}" =~ ^[0-9]+$ ]] && kill "$FOREIGN" >/dev/null 2>&1 || true
  HOME="$OLDHOME"; if [[ "${KEEP_TMP:-0}" == "1" ]]; then echo "KEEP_TMP=$TMP" >&2; else rm -rf "$TMP"; fi
}
trap cleanup EXIT
export HOME="$TMP/home"
BASE="$HOME/Games/FFXI"
mkdir -p "$BASE/tools/Gearplaner-1.0" "$BASE/tools/ffxi-gear-center" "$BASE/scripts" "$BASE/data/Gearplaner"
cp -a "$ROOT/payload/runtime/." "$BASE/tools/Gearplaner-1.0/"
cp -a "$ROOT/payload/app/." "$BASE/tools/ffxi-gear-center/"
chmod -x "$BASE/tools/ffxi-gear-center/shared/bin/php" 2>/dev/null || true
cp -a "$ROOT/payload/scripts/." "$BASE/scripts/"
chmod +x "$BASE/scripts/"*.sh
printf '{"schema":"gearplaner.requirements/0.6","tasks":[]}\n' > "$BASE/data/Gearplaner/requirements.json"
printf '{"schema":"gearplaner.goals/0.6","goals":[]}\n' > "$BASE/data/Gearplaner/goals.json"
read -r GEAR_CENTER_API_PORT GEAR_CENTER_WEB_PORT < <(python3 - <<'PY'
import socket
socks=[]; ports=[]
for _ in range(2):
 s=socket.socket(); s.bind(('127.0.0.1',0)); socks.append(s); ports.append(s.getsockname()[1])
print(*ports)
for s in socks:s.close()
PY
)
export GEAR_CENTER_API_PORT GEAR_CENTER_WEB_PORT
S="$BASE/scripts/gear-center-services.sh"

# No FFXI-Center directory exists at all. Standalone must still work.
[[ ! -e "$BASE/tools/FFXI-Center/ffxi_center.py" ]]

# 1) start is a real, idempotent standalone lifecycle owner.
"$S" start
for _ in {1..100}; do
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && \
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1 && break
  sleep .03
done
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" | grep -q '"release":"1.0.2"'
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/assets/gp10/app.js" | grep -q 'embedded-panel'
PIDFILE="$BASE/run/gear-center/standalone.owner.pid"
[[ -s "$PIDFILE" ]]
STANDALONE_PID="$(cat "$PIDFILE")"
kill -0 "$STANDALONE_PID"
"$S" start
[[ "$(cat "$PIDFILE")" == "$STANDALONE_PID" ]]

# 2) restart replaces the standalone owner while restoring both services.
"$S" restart
NEW_PID="$(cat "$PIDFILE")"
[[ "$NEW_PID" != "$STANDALONE_PID" ]]
for _ in {1..100}; do curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && break; sleep .03; done
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null
STANDALONE_PID="$NEW_PID"

# 3) hard crash of the standalone owner is self-healed by the existing watchdog.
kill -9 "$STANDALONE_PID"; wait "$STANDALONE_PID" 2>/dev/null || true
for _ in {1..300}; do
  [[ ! -e "$PIDFILE" ]] && \
  ! curl -fsS --max-time .2 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && \
  ! curl -fsS --max-time .2 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1 && break
  sleep .05
done
[[ ! -e "$PIDFILE" ]]
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1

# 4) foreign port occupancy blocks standalone start and is never killed.
python3 -m http.server "$GEAR_CENTER_API_PORT" --bind 127.0.0.1 >"$TMP/foreign.log" 2>&1 & FOREIGN=$!
for _ in {1..100}; do ss -ltn 2>/dev/null | grep -q ":$GEAR_CENTER_API_PORT " && break; sleep .03; done
ss -ltn 2>/dev/null | grep -q ":$GEAR_CENTER_API_PORT "
! "$S" start >/dev/null 2>&1
kill -0 "$FOREIGN"
kill "$FOREIGN"; wait "$FOREIGN" 2>/dev/null || true; FOREIGN=""

# 5) normal stop is clean and idempotent.
"$S" start
for _ in {1..100}; do curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && break; sleep .03; done
"$S" stop
"$S" stop
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1

echo 'PASS: standalone lifecycle works without FFXI Center; start/restart/stop idempotent; crash cleanup and foreign-port safety preserved'
