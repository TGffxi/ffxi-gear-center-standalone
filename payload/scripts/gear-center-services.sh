#!/usr/bin/env bash
set -Eeuo pipefail
BASE="$HOME/Games/FFXI"
APP="$BASE/tools/ffxi-gear-center"
RUNTIME="$BASE/tools/Gearplaner-1.0"
RUN="$BASE/run/gear-center"
OWNERS="$RUN/owners"
LOG="$BASE/logs/gear-center"
API_PORT="${GEAR_CENTER_API_PORT:-8765}"
WEB_PORT="${GEAR_CENTER_WEB_PORT:-8787}"
API_OWNED="$RUN/api.owned.pid"
WEB_OWNED="$RUN/web.owned.pid"
STANDALONE_PIDFILE="$RUN/standalone.owner.pid"
LOCK="$RUN/lock"
SELF="$(readlink -f "${BASH_SOURCE[0]}")"
mkdir -p "$OWNERS" "$LOG"

lock(){ exec 9>"$LOCK"; flock 9; }
api_ok(){ python3 - "$API_PORT" <<'PY' >/dev/null 2>&1
import json,sys,urllib.request
p=int(sys.argv[1])
try:
 d=json.load(urllib.request.urlopen(f'http://127.0.0.1:{p}/health',timeout=.8))
 raise SystemExit(0 if d.get('release')=='1.0.2' else 1)
except Exception: raise SystemExit(1)
PY
}
web_ok(){ curl -fsS --max-time 1 "http://127.0.0.1:$WEB_PORT/" 2>/dev/null | grep -q '<ffxi-gear-center' && curl -fsS --max-time 1 "http://127.0.0.1:$WEB_PORT/assets/gp10/app.js" 2>/dev/null | grep -q 'embedded-panel'; }
port_pid(){ local p="$1"; ss -ltnp 2>/dev/null | awk -v p=":$p" '$4 ~ p"$" {if(match($0,/pid=[0-9]+/)){x=substr($0,RSTART+4,RLENGTH-4); print x; exit}}'; }
cmdline(){ tr '\0' ' ' < "/proc/$1/cmdline" 2>/dev/null || true; }
proc_cwd(){ readlink "/proc/$1/cwd" 2>/dev/null || true; }

# Positive product identity only; ports alone are never sufficient to kill a process.
product_process(){
  local pid="$1" kind="$2" c cwd
  [[ "$pid" =~ ^[0-9]+$ && -r "/proc/$pid/cmdline" ]] || return 1
  c="$(cmdline "$pid")"
  case "$kind" in
    api) [[ "$c" == *"$BASE/tools/Gearplaner-"*"/bridge/server.py"* && "$c" == *"--port $API_PORT"* ]] ;;
    web)
      cwd="$(proc_cwd "$pid")"
      [[ "$c" == *"php -S 127.0.0.1:$WEB_PORT"* ]] || return 1
      [[ "$cwd" == "$APP" || "$cwd" == "$APP (deleted)" || "$c" == *"$APP/public/router.php"* ]]
      ;;
    *) return 1 ;;
  esac
}
owned_process(){
  local pid="$1" kind="$2" c cwd
  [[ "$pid" =~ ^[0-9]+$ && -r "/proc/$pid/cmdline" ]] || return 1
  c="$(cmdline "$pid")"
  case "$kind" in
    api) [[ "$c" == *"$RUNTIME/bridge/server.py"* && "$c" == *"--port $API_PORT"* ]] ;;
    web) cwd="$(proc_cwd "$pid")"; [[ "$cwd" == "$APP" && "$c" == *"php -S 127.0.0.1:$WEB_PORT"* ]] ;;
    *) return 1 ;;
  esac
}
terminate_pid(){ local pid="$1"; kill "$pid" 2>/dev/null || true; for _ in {1..40}; do kill -0 "$pid" 2>/dev/null || return 0; sleep .1; done; kill -9 "$pid" 2>/dev/null || true; }
stop_owned_one(){ local file="$1" kind="$2" pid; [[ -f "$file" ]] || return 0; pid="$(cat "$file" 2>/dev/null || true)"; if owned_process "$pid" "$kind"; then terminate_pid "$pid"; fi; rm -f "$file"; }
stop_owned(){ stop_owned_one "$WEB_OWNED" web; stop_owned_one "$API_OWNED" api; }
proc_starttime(){ awk '{print $22}' "/proc/$1/stat" 2>/dev/null || true; }
proc_state(){ awk '{print $3}' "/proc/$1/stat" 2>/dev/null || true; }
owner_alive(){
  local f="$1" pid expected actual
  pid="$(awk -F= '$1=="pid"{print $2;exit}' "$f" 2>/dev/null || true)"
  expected="$(awk -F= '$1=="starttime"{print $2;exit}' "$f" 2>/dev/null || true)"
  [[ "$pid" =~ ^[0-9]+$ && "$expected" =~ ^[0-9]+$ ]] || return 1
  kill -0 "$pid" 2>/dev/null || return 1
  [[ "$(proc_state "$pid")" != "Z" ]] || return 1
  actual="$(proc_starttime "$pid")"
  [[ -n "$actual" && "$actual" == "$expected" ]]
}
watch_owner(){
  # Independent, ownership-free crash watcher. The actual ffxi_center.py PID is
  # still the lifecycle owner; this helper only releases its token when that
  # exact PID/starttime disappears (including SIGKILL and zombie state).
  local token="${1:-}" pid="${2:-}" expected="${3:-}" file
  [[ "$token" =~ ^[A-Za-z0-9._-]+$ && "$pid" =~ ^[0-9]+$ && "$expected" =~ ^[0-9]+$ ]] || return 2
  file="$OWNERS/$token"
  while [[ -f "$file" ]]; do
    if ! owner_alive "$file"; then
      if [[ "$token" == standalone-* && -f "$STANDALONE_PIDFILE" ]]; then
        [[ "$(cat "$STANDALONE_PIDFILE" 2>/dev/null || true)" != "$pid" ]] || rm -f "$STANDALONE_PIDFILE"
      fi
      "$SELF" release "$token" >/dev/null 2>&1 || true
      return 0
    fi
    sleep .25
  done
}
start_owner_watchdog(){
  local token="$1" pid="$2" starttime="$3"
  nohup "$SELF" _watch-owner "$token" "$pid" "$starttime" >/dev/null 2>&1 9>&- &
}
cleanup_stale_owners(){
  local f
  for f in "$OWNERS"/*; do
    [[ -f "$f" ]] || continue
    owner_alive "$f" || rm -f "$f"
  done
}
live_owner_count(){ cleanup_stale_owners; find "$OWNERS" -maxdepth 1 -type f -printf '.' 2>/dev/null | wc -c; }

cleanup_product_orphans(){
  # Migration/self-heal for legacy 0.5-0.8/1.0.x processes that predate owner
  # tokens. Only processes positively identified as this product are stopped.
  stop_owned
  local spec kind p pid
  for spec in "web:$WEB_PORT" "api:$API_PORT"; do
    kind="${spec%%:*}"; p="${spec##*:}"
    pid="$(port_pid "$p" || true)"
    if [[ -n "$pid" ]] && product_process "$pid" "$kind"; then
      echo "[CLEAN] verwaister Gear-Center-$kind Prozess PID $pid auf :$p" >&2
      terminate_pid "$pid"
    fi
  done
  rm -f "$API_OWNED" "$WEB_OWNED"
}

ports_free_or_foreign(){
  local p pid
  for p in "$API_PORT" "$WEB_PORT"; do
    pid="$(port_pid "$p" || true)"
    [[ -z "$pid" ]] || { echo "Port $p ist durch einen fremden Prozess belegt (PID $pid)." >&2; return 1; }
  done
}

prepare_install(){
  lock; cleanup_stale_owners
  local n; n="$(live_owner_count)"
  [[ "$n" -eq 0 ]] || { echo "Gear Center wird noch von $n Owner(n) verwendet. Vor Installation Standalone/FFXI-Center-Lifecycle beenden." >&2; return 1; }
  cleanup_product_orphans
  ports_free_or_foreign || { echo 'Installation abgebrochen; fremde Prozesse werden nicht beendet.' >&2; return 1; }
  rm -f "$OWNERS"/* 2>/dev/null || true
  echo '[OK] Kein Gear-Center-Server aktiv; Installer bleibt serverfrei.'
}

start_services(){
  if ! api_ok; then
    local pid; pid="$(port_pid "$API_PORT" || true)"
    [[ -z "$pid" ]] || { echo "Port $API_PORT ist durch einen anderen/alten Prozess belegt (PID $pid)." >&2; return 1; }
    GEAR_CENTER_LIFECYCLE=1 nohup "$BASE/scripts/start-gear-center-api.sh" >"$LOG/api.log" 2>&1 9>&- & pid=$!; echo "$pid" > "$API_OWNED"
    for _ in {1..80}; do api_ok && break; sleep .1; done
  fi
  api_ok || { echo "Gear-Center-API konnte nicht starten. Log: $LOG/api.log" >&2; return 1; }
  if ! web_ok; then
    local pid; pid="$(port_pid "$WEB_PORT" || true)"
    [[ -z "$pid" ]] || { echo "Port $WEB_PORT ist durch einen anderen/alten Prozess belegt (PID $pid)." >&2; return 1; }
    GEAR_CENTER_LIFECYCLE=1 nohup "$BASE/scripts/start-gear-center-web.sh" >"$LOG/web.log" 2>&1 9>&- & pid=$!; echo "$pid" > "$WEB_OWNED"
    for _ in {1..80}; do web_ok && break; sleep .1; done
  fi
  web_ok || { echo "Gear-Center-Webserver konnte nicht starten. Log: $LOG/web.log" >&2; return 1; }
}
acquire(){
  local token="${1:-}" owner_pid="${2:-}"
  [[ "$token" =~ ^[A-Za-z0-9._-]+$ ]] || { echo 'Ungültiger Owner-Token.' >&2; return 2; }
  [[ "$owner_pid" =~ ^[0-9]+$ ]] || { echo 'Ungültige Owner-PID.' >&2; return 2; }
  local owner_starttime; owner_starttime="$(proc_starttime "$owner_pid")"
  [[ "$owner_starttime" =~ ^[0-9]+$ ]] || { echo 'Owner-Prozess ist nicht mehr aktiv.' >&2; return 2; }
  lock; cleanup_stale_owners
  # No live owner means any positively recognized Gear-Center process is an
  # orphan from an older/crashed lifecycle. Never adopt it silently.
  if [[ "$(live_owner_count)" -eq 0 ]]; then
    cleanup_product_orphans
    ports_free_or_foreign || return 1
  fi
  # Register the actual Center process BEFORE bringing sockets up. This closes
  # the race where API/Web could already be reachable without any owner token.
  # If startup fails, remove this token and clean only product-owned processes.
  printf 'pid=%s\nstarttime=%s\nstarted=%s\n' "$owner_pid" "$owner_starttime" "$(date +%s)" > "$OWNERS/$token"
  start_owner_watchdog "$token" "$owner_pid" "$owner_starttime"
  if ! start_services; then
    rm -f "$OWNERS/$token"
    cleanup_stale_owners
    [[ "$(live_owner_count)" -gt 0 ]] || cleanup_product_orphans
    return 1
  fi
  # The Center may have died while service readiness was being checked.
  if ! owner_alive "$OWNERS/$token"; then
    rm -f "$OWNERS/$token"
    cleanup_stale_owners
    [[ "$(live_owner_count)" -gt 0 ]] || cleanup_product_orphans
    echo 'Owner-Prozess endete während des Gear-Center-Starts.' >&2
    return 1
  fi
}
release(){
  local token="${1:-}"
  [[ "$token" =~ ^[A-Za-z0-9._-]+$ ]] || return 0
  lock; rm -f "$OWNERS/$token"; cleanup_stale_owners
  if [[ "$(live_owner_count)" -eq 0 ]]; then cleanup_product_orphans; fi
}
standalone_owner_active(){
  local pid token
  [[ -s "$STANDALONE_PIDFILE" ]] || return 1
  pid="$(cat "$STANDALONE_PIDFILE" 2>/dev/null || true)"
  [[ "$pid" =~ ^[0-9]+$ ]] || return 1
  token="standalone-$pid"
  owner_alive "$OWNERS/$token"
}
standalone_owner(){
  local token="standalone-$$"
  printf '%s\n' "$$" > "$STANDALONE_PIDFILE"
  cleanup_standalone_owner(){
    rm -f "$STANDALONE_PIDFILE"
    "$SELF" release "$token" >/dev/null 2>&1 || true
  }
  stop_standalone_owner(){
    trap - TERM INT HUP
    cleanup_standalone_owner
    trap - EXIT
    exit 0
  }
  trap cleanup_standalone_owner EXIT
  trap stop_standalone_owner TERM INT HUP
  "$SELF" acquire "$token" "$$" || return 1
  while :; do sleep 3600 & wait $! || true; done
}
manual_start(){
  mkdir -p "$RUN"
  if standalone_owner_active; then
    api_ok && web_ok && return 0
    start_services
    return
  fi
  rm -f "$STANDALONE_PIDFILE"
  nohup "$SELF" _standalone-owner >"$LOG/standalone-owner.log" 2>&1 9>&- &
  local pid=$!
  printf '%s\n' "$pid" > "$STANDALONE_PIDFILE"
  for _ in {1..100}; do
    standalone_owner_active && api_ok && web_ok && return 0
    kill -0 "$pid" 2>/dev/null || break
    sleep .05
  done
  echo "Standalone-Lifecycle konnte nicht starten. Log: $LOG/standalone-owner.log" >&2
  return 1
}
manual_stop(){
  local pid=""
  if [[ -s "$STANDALONE_PIDFILE" ]]; then pid="$(cat "$STANDALONE_PIDFILE" 2>/dev/null || true)"; fi
  if [[ "$pid" =~ ^[0-9]+$ ]] && kill -0 "$pid" 2>/dev/null; then
    kill "$pid" 2>/dev/null || true
    for _ in {1..80}; do kill -0 "$pid" 2>/dev/null || break; sleep .05; done
    kill -0 "$pid" 2>/dev/null && kill -9 "$pid" 2>/dev/null || true
  fi
  rm -f "$STANDALONE_PIDFILE"
  lock; cleanup_stale_owners
  if [[ "$(live_owner_count)" -eq 0 ]]; then
    cleanup_product_orphans
    for _ in {1..40}; do ! api_ok && ! web_ok && break; sleep .05; done
  fi
}
manual_restart(){ "$SELF" stop; "$SELF" start; }
status(){
  local n; lock; n="$(live_owner_count)"
  if [[ "$n" -eq 0 ]]; then cleanup_product_orphans; fi
  api_ok && echo "API :$API_PORT  RUNNING" || echo "API :$API_PORT  STOPPED"
  web_ok && echo "Web :$WEB_PORT  RUNNING" || echo "Web :$WEB_PORT  STOPPED"
  standalone_owner_active && echo "Standalone-Owner: RUNNING" || echo "Standalone-Owner: STOPPED"
  echo "Owner gesamt: $n"
}
case "${1:-status}" in
  _watch-owner) watch_owner "${2:-}" "${3:-}" "${4:-}" ;;
  _standalone-owner) standalone_owner ;;
  acquire) acquire "${2:-}" "${3:-}" ;;
  release) release "${2:-}" ;;
  prepare-install) prepare_install ;;
  start) manual_start ;;
  stop) manual_stop ;;
  restart) manual_restart ;;
  status) status ;;
  open) lock; n="$(live_owner_count)"; [[ "$n" -gt 0 ]] && web_ok || { echo 'Gear Center läuft nicht. Erst gear-center-services.sh start ausführen.' >&2; exit 1; }; "$BASE/scripts/open-gear-center.sh" ;;
  *) echo "Usage: $0 {acquire TOKEN PID|release TOKEN|prepare-install|start|stop|restart|status|open}"; exit 2 ;;
esac
