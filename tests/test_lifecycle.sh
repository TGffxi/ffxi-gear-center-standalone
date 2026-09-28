#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TMP="$(mktemp -d -t gc101life.XXXXXX)"
OLDHOME="$HOME"
cleanup(){
  set +e
  if [[ -n "${HOME:-}" && -x "$HOME/Games/FFXI/scripts/gear-center-services.sh" ]]; then
    timeout -k 2 10 "$HOME/Games/FFXI/scripts/gear-center-services.sh" stop >/dev/null 2>&1 || true
  fi
  for p in "${EXT_API:-}" "${EXT_WEB:-}" "${FOREIGN:-}" "${WRAPPER_PID:-}" "${CENTER_PID:-}" "${CRASH_A:-}" "${CRASH_B:-}"; do
    [[ "$p" =~ ^[0-9]+$ ]] && kill "$p" >/dev/null 2>&1 || true
  done
  HOME="$OLDHOME"; rm -rf "$TMP"
}
trap cleanup EXIT
export HOME="$TMP/home"
BASE="$HOME/Games/FFXI"
mkdir -p "$BASE/tools/Gearplaner-1.0" "$BASE/tools/ffxi-gear-center" "$BASE/tools/FFXI-Center" "$BASE/scripts" "$BASE/data/Gearplaner"
cp -a "$ROOT/payload/runtime/." "$BASE/tools/Gearplaner-1.0/"
cp -a "$ROOT/payload/app/." "$BASE/tools/ffxi-gear-center/"
# Lifecycle isolation: runtime planning export requires host pdo_sqlite, which is
# tested separately; do not let this sandbox dependency block service lifetime QA.
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
SHELLPID="$$"

# 1) Installer/preparation is server-free and cleans positively identified legacy orphans.
mkdir -p "$BASE/tools/Gearplaner-0.8/bridge"
cat > "$BASE/tools/Gearplaner-0.8/bridge/server.py" <<'PY'
import argparse,socket,time
p=argparse.ArgumentParser();p.add_argument('--port',type=int,required=True);a=p.parse_args()
s=socket.socket();s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1);s.bind(('127.0.0.1',a.port));s.listen()
try: time.sleep(60)
finally: s.close()
PY
python3 "$BASE/tools/Gearplaner-0.8/bridge/server.py" --port "$GEAR_CENTER_API_PORT" >"$TMP/legacy-api.log" 2>&1 & EXT_API=$!
(
  cd "$BASE/tools/ffxi-gear-center"
  exec php -S "127.0.0.1:$GEAR_CENTER_WEB_PORT" -t public public/router.php
) >"$TMP/legacy-web.log" 2>&1 & EXT_WEB=$!
for _ in {1..80}; do
  ss -ltnp 2>/dev/null | grep -q ":$GEAR_CENTER_API_PORT " && ss -ltnp 2>/dev/null | grep -q ":$GEAR_CENTER_WEB_PORT " && break
  sleep .05
done
"$S" prepare-install >/dev/null
sleep .2
! kill -0 "$EXT_API" 2>/dev/null
! kill -0 "$EXT_WEB" 2>/dev/null
! ss -ltn 2>/dev/null | grep -q ":$GEAR_CENTER_API_PORT "
! ss -ltn 2>/dev/null | grep -q ":$GEAR_CENTER_WEB_PORT "
EXT_API=""; EXT_WEB=""

# 2) A foreign port occupant is never killed and blocks prepare-install/acquire.
python3 -m http.server "$GEAR_CENTER_API_PORT" --bind 127.0.0.1 >"$TMP/foreign.log" 2>&1 & FOREIGN=$!
for _ in {1..40}; do ss -ltn 2>/dev/null | grep -q ":$GEAR_CENTER_API_PORT " && break; sleep .05; done
! "$S" prepare-install >/dev/null 2>&1
kill -0 "$FOREIGN"
! "$S" acquire blocked-owner "$SHELLPID" >/dev/null 2>&1
kill -0 "$FOREIGN"
kill "$FOREIGN"; wait "$FOREIGN" 2>/dev/null || true; FOREIGN=""

# 3) Multi-Center ownership: first starts, intermediate release keeps, last stops.
mkdir -p "$BASE/run/gear-center/owners"
printf "pid=%s\nstarttime=1\nstarted=1\n" "$SHELLPID" > "$BASE/run/gear-center/owners/stale-reused-pid"
! "$BASE/scripts/open-gear-center.sh" >/dev/null 2>&1
"$S" acquire owner-a "$SHELLPID"
[[ ! -e "$BASE/run/gear-center/owners/stale-reused-pid" ]]
"$S" acquire owner-b "$SHELLPID"
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" | grep -q '"release":"1.0.2"'
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/assets/gp10/app.js" | grep -q 'embedded-panel'
"$S" release owner-a
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/assets/gp10/app.js" >/dev/null
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f | wc -l)" -eq 1 ]]
"$S" release owner-b
sleep .3
! curl -fsS --max-time 1 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time 1 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1

# 4) Internal service launchers reject normal/manual starts.
! "$BASE/scripts/start-gear-center-api.sh" >/dev/null 2>&1
! "$BASE/scripts/start-gear-center-web.sh" >/dev/null 2>&1

# 5) Actual ffxi_center.py process is the owner, even when launched directly.
cat > "$BASE/tools/FFXI-Center/ffxi_center.py" <<'PYCENTER'
import os
import subprocess
import time
import tkinter as tk
from pathlib import Path
SCRIPTS=Path.home()/"Games/FFXI/scripts"
STARTERS={
    "FFXI / Ashita": SCRIPTS/"start-ffxi-ashita.sh",
}
class Center(tk.Tk):
    def __init__(self):
        pass
    def mainloop(self):
        # Test fixture only: one second is long enough to prove owner lifetime
        # while keeping the full lifecycle regression below CI/tool timeouts.
        time.sleep(1.0)
if __name__ == "__main__":
    Center().mainloop()
PYCENTER
python3 "$ROOT/payload/center/patch_ffxi_center.py" "$BASE/tools/FFXI-Center/ffxi_center.py" >/dev/null
python3 "$BASE/tools/FFXI-Center/ffxi_center.py" >"$TMP/center-direct.log" 2>&1 & CENTER_PID=$!
for _ in {1..100}; do
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && \
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1 && break
  sleep .03
done
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null
OWNER_FILE="$BASE/run/gear-center/owners/ffxi-center-$CENTER_PID"
[[ -f "$OWNER_FILE" ]]
grep -q "pid=$CENTER_PID" "$OWNER_FILE"
wait "$CENTER_PID"; CENTER_PID=""
sleep .3
! curl -fsS --max-time .5 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .5 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]]

# 6) SIGKILL / hard-crash of the actual Center must be self-healed without any
# later status/install invocation. The detached watcher releases the exact
# PID/starttime owner token and stops services when it was the last owner.
python3 "$BASE/tools/FFXI-Center/ffxi_center.py" >"$TMP/center-crash.log" 2>&1 & CENTER_PID=$!
for _ in {1..100}; do
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && \
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1 && break
  sleep .03
done
[[ -f "$BASE/run/gear-center/owners/ffxi-center-$CENTER_PID" ]]
kill -9 "$CENTER_PID"; wait "$CENTER_PID" 2>/dev/null || true; CENTER_PID=""
for _ in {1..80}; do
  [[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]] && \
  ! curl -fsS --max-time .2 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && \
  ! curl -fsS --max-time .2 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1 && break
  sleep .05
done
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]]
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1

# 7) Multi-owner hard crash: losing one Center must not stop shared services;
# losing the last one must stop them without a manual cleanup call.
sleep 60 & CRASH_A=$!
sleep 60 & CRASH_B=$!
"$S" acquire crash-a "$CRASH_A"
"$S" acquire crash-b "$CRASH_B"
kill -9 "$CRASH_A"; wait "$CRASH_A" 2>/dev/null || true; CRASH_A=""
for _ in {1..60}; do
  [[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 1 ]] && break
  sleep .05
done
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 1 ]]
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null
curl -fsS "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null
kill -9 "$CRASH_B"; wait "$CRASH_B" 2>/dev/null || true; CRASH_B=""
for _ in {1..80}; do
  [[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]] && \
  ! curl -fsS --max-time .2 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && break
  sleep .05
done
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]]
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1

# 8) Compatibility wrapper does not own services itself; exec preserves the
# Python Center PID/token semantics.
"$BASE/scripts/start-ffxi-center-with-gear-center.sh" >"$TMP/wrapper.log" 2>&1 & WRAPPER_PID=$!
for _ in {1..100}; do
  curl -fsS --max-time .3 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1 && break
  sleep .03
done
curl -fsS "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null
[[ -f "$BASE/run/gear-center/owners/ffxi-center-$WRAPPER_PID" ]]
wait "$WRAPPER_PID"; WRAPPER_PID=""
sleep .3
! curl -fsS --max-time .5 "http://127.0.0.1:$GEAR_CENTER_API_PORT/health" >/dev/null 2>&1
! curl -fsS --max-time .5 "http://127.0.0.1:$GEAR_CENTER_WEB_PORT/" >/dev/null 2>&1
[[ "$(find "$BASE/run/gear-center/owners" -maxdepth 1 -type f 2>/dev/null | wc -l)" -eq 0 ]]

echo 'PASS: installer preparation server-free; legacy product orphans cleaned by positive identity; foreign ports untouched; normal and SIGKILL/hard-crash multi-owner lifecycle; direct ffxi_center.py owns API/Web and independent watchdog releases dead owners; wrapper is ownership-free compatibility only'
