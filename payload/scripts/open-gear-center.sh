#!/usr/bin/env bash
set -Eeuo pipefail
BASE="$HOME/Games/FFXI"
RUN="$BASE/run/gear-center"
OWNERS="$RUN/owners"
PORT="${GEAR_CENTER_WEB_PORT:-8787}"
proc_starttime(){ awk '{print $22}' "/proc/$1/stat" 2>/dev/null || true; }
owner_alive(){
  local f="$1" pid expected actual
  pid="$(awk -F= '$1=="pid"{print $2;exit}' "$f" 2>/dev/null || true)"
  expected="$(awk -F= '$1=="starttime"{print $2;exit}' "$f" 2>/dev/null || true)"
  [[ "$pid" =~ ^[0-9]+$ && "$expected" =~ ^[0-9]+$ ]] || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  actual="$(proc_starttime "$pid")"
  [[ -n "$actual" && "$actual" == "$expected" ]]
}
owner_active(){
  local f
  [[ -d "$OWNERS" ]] || return 1
  for f in "$OWNERS"/*; do [[ -f "$f" ]] || continue; owner_alive "$f" && return 0; done
  return 1
}
web_ok(){ curl -fsS --max-time 1 "http://127.0.0.1:$PORT/assets/gp10/app.js" 2>/dev/null | grep -q 'embedded-panel'; }
if ! owner_active || ! web_ok; then
  msg='Gear Center läuft nicht. Starte zuerst den Standalone-Lifecycle oder FFXI Center.'
  echo "$msg" >&2
  command -v notify-send >/dev/null 2>&1 && notify-send 'FFXI Gear Center' "$msg" >/dev/null 2>&1 || true
  exit 1
fi
xdg-open "http://127.0.0.1:$PORT/" >/dev/null 2>&1 &
