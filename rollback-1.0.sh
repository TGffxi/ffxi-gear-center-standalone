#!/usr/bin/env bash
set -Eeuo pipefail
BASE="$HOME/Games/FFXI"
BACKUPS="$BASE/backups"
BK="${1:-}"
if [[ -z "$BK" ]]; then
  BK="$(find "$BACKUPS" -maxdepth 1 -type d \( -name 'ffxi-gear-center-v1.0.2-before-*' -o -name 'ffxi-gear-center-v1.0.1-before-*' -o -name 'ffxi-gear-center-v1-before-*' \) -printf '%T@ %p\n' 2>/dev/null | sort -nr | head -1 | cut -d' ' -f2-)"
fi
[[ -n "$BK" && -d "$BK" ]] || { echo 'Kein Gear-Center-1.0.x-Backup gefunden.' >&2; exit 1; }

echo "Restore aus: $BK"
CENTER="$BASE/tools/FFXI-Center/ffxi_center.py"
python3 - "$CENTER" <<'PY'
from pathlib import Path
import os,sys
center=Path(sys.argv[1]).resolve(); found=[]
for e in os.scandir('/proc'):
    if not e.name.isdigit(): continue
    try: args=[x.decode(errors='ignore') for x in Path(f'/proc/{e.name}/cmdline').read_bytes().split(b'\0') if x]
    except OSError: continue
    if len(args)>=2:
        try: target=Path(args[1]).resolve()
        except Exception: continue
        if target==center: found.append(e.name)
if found: raise SystemExit('[ERR] FFXI Center läuft noch. Vor Rollback alle Center-Fenster schließen.')
PY

# Stop only services that the current Gear-Center lifecycle owns. Older service
# scripts are tolerated during rollback.
if [[ -x "$BASE/scripts/gear-center-services.sh" ]]; then
  "$BASE/scripts/gear-center-services.sh" stop >/dev/null 2>&1 || true
fi

APP="$BASE/tools/ffxi-gear-center"
RUNTIME="$BASE/tools/Gearplaner-1.0"
STATE="$BASE/data/Gearplaner"
PRESENCE="$BK/presence.txt"
was_present(){
  local key="$1"
  [[ -f "$PRESENCE" ]] || return 2
  grep -Fqx -- "$key=1" "$PRESENCE"
}
has_presence(){ [[ -f "$PRESENCE" ]]; }

# App/runtime: with a presence manifest, restore predecessor or remove the
# component if 1.0.1 created it. Older backups retain legacy behavior.
if has_presence; then
  if was_present app; then
    [[ -f "$BK/ffxi-gear-center-code.tar.gz" ]] || { echo '[ERR] App-Backup fehlt trotz Presence-Marker.' >&2; exit 1; }
    rm -rf "$APP"
    tar -C "$BASE/tools" -xzf "$BK/ffxi-gear-center-code.tar.gz"
  else
    rm -rf "$APP"
  fi
  if was_present runtime; then
    [[ -f "$BK/Gearplaner-1.0.tar.gz" ]] || { echo '[ERR] Runtime-Backup fehlt trotz Presence-Marker.' >&2; exit 1; }
    rm -rf "$RUNTIME"
    tar -C "$BASE/tools" -xzf "$BK/Gearplaner-1.0.tar.gz"
  else
    rm -rf "$RUNTIME"
  fi
else
  if [[ -f "$BK/ffxi-gear-center-code.tar.gz" ]]; then
    rm -rf "$APP"
    tar -C "$BASE/tools" -xzf "$BK/ffxi-gear-center-code.tar.gz"
  fi
  if [[ -f "$BK/Gearplaner-1.0.tar.gz" ]]; then
    rm -rf "$RUNTIME"
    tar -C "$BASE/tools" -xzf "$BK/Gearplaner-1.0.tar.gz"
  else
    rm -rf "$RUNTIME"
  fi
fi

[[ -f "$BK/ffxi_center.py" ]] && cp -a "$BK/ffxi_center.py" "$CENTER"
if has_presence; then
  if was_present center_start; then
    cp -a "$BK/start-ffxi-center.sh" "$BASE/scripts/start-ffxi-center.sh"
  else
    rm -f "$BASE/scripts/start-ffxi-center.sh"
  fi
else
  [[ -f "$BK/start-ffxi-center.sh" ]] && cp -a "$BK/start-ffxi-center.sh" "$BASE/scripts/start-ffxi-center.sh"
fi

# Lifecycle scripts are exact by construction: backed-up predecessor wins;
# otherwise the 1.0.1-only file is removed.
for f in gear-center-services.sh gear-center-status.sh open-gear-center.sh start-ffxi-center-with-gear-center.sh start-gear-center-api.sh start-gear-center-web.sh; do
  if [[ -f "$BK/scripts/$f" ]]; then
    cp -a "$BK/scripts/$f" "$BASE/scripts/$f"
  else
    rm -f "$BASE/scripts/$f"
  fi
done

if [[ -f "$BK/boot-script.txt" && -f "$BK/boot-path.txt" ]]; then
  BOOT_DEST="$(tr -d '\r\n' < "$BK/boot-path.txt")"
  case "$BOOT_DEST" in
    "$BASE/ashita/scripts/"*) mkdir -p "$(dirname "$BOOT_DEST")"; cp -a "$BK/boot-script.txt" "$BOOT_DEST" ;;
    *) echo "[ERR] Ungültiger Boot-Restore-Pfad im Backup: $BOOT_DEST" >&2; exit 1 ;;
  esac
fi
POINTS="$BASE/ashita/addons/points/points.lua"
[[ -f "$POINTS" ]] || POINTS="$BASE/ashita/addons/Points/points.lua"
[[ -f "$BK/points.lua" ]] && cp -a "$BK/points.lua" "$POINTS"

BRIDGE="$BASE/ashita/addons/GearplanerBridge"
LACBRIDGE="$BASE/ashita/config/addons/LuAshitacast/common/gearcenter_bridge.lua"
GCINCLUDE="$BASE/ashita/config/addons/LuAshitacast/common/gcinclude.lua"
if has_presence; then
  if was_present gearplaner_bridge; then
    rm -rf "$BRIDGE"
    cp -a "$BK/GearplanerBridge" "$BRIDGE"
  else
    rm -rf "$BRIDGE"
  fi
  if was_present lac_bridge; then
    mkdir -p "$(dirname "$LACBRIDGE")"
    cp -a "$BK/gearcenter_bridge.lua" "$LACBRIDGE"
  else
    rm -f "$LACBRIDGE"
  fi
  if was_present gcinclude; then
    [[ -f "$BK/gcinclude.lua" ]] || { echo '[ERR] gcinclude.lua-Backup fehlt trotz Presence-Marker.' >&2; exit 1; }
    mkdir -p "$(dirname "$GCINCLUDE")"
    cp -a "$BK/gcinclude.lua" "$GCINCLUDE"
  fi
else
  if [[ -d "$BK/GearplanerBridge" ]]; then
    rm -rf "$BRIDGE"
    cp -a "$BK/GearplanerBridge" "$BRIDGE"
  fi
  [[ -f "$BK/gearcenter_bridge.lua" ]] && cp -a "$BK/gearcenter_bridge.lua" "$LACBRIDGE"
  [[ -f "$BK/gcinclude.lua" ]] && cp -a "$BK/gcinclude.lua" "$GCINCLUDE"
fi

CAPTURE_ADDON="$BASE/ashita/addons/gearlab_capture"
CAPTURE_CFG="$BASE/ashita/config/addons/gearlab_capture"
if has_presence; then
  if was_present capture_addon; then
    rm -rf "$CAPTURE_ADDON"
    cp -a "$BK/retired-combat-capture/addon" "$CAPTURE_ADDON"
  else
    rm -rf "$CAPTURE_ADDON"
  fi
  if was_present capture_config; then
    rm -rf "$CAPTURE_CFG"
    cp -a "$BK/retired-combat-capture/config" "$CAPTURE_CFG"
  else
    rm -rf "$CAPTURE_CFG"
  fi
else
  if [[ -d "$BK/retired-combat-capture/addon" ]]; then
    rm -rf "$CAPTURE_ADDON"
    cp -a "$BK/retired-combat-capture/addon" "$CAPTURE_ADDON"
  fi
  if [[ -d "$BK/retired-combat-capture/config" ]]; then
    rm -rf "$CAPTURE_CFG"
    cp -a "$BK/retired-combat-capture/config" "$CAPTURE_CFG"
  fi
fi

mkdir -p "$STATE"
if has_presence; then
  for spec in 'state_requirements:requirements.json' 'state_goals:goals.json' 'state_performance:performance-cache.json'; do
    key="${spec%%:*}"
    f="${spec#*:}"
    if was_present "$key"; then
      cp -a "$BK/state/$f" "$STATE/$f"
    else
      rm -f "$STATE/$f"
    fi
  done
else
  for f in requirements.json goals.json performance-cache.json; do
    [[ ! -f "$BK/state/$f" ]] || cp -a "$BK/state/$f" "$STATE/$f"
  done
fi

# 0.5–0.8 runtimes and old helper scripts are restored to the exact paths they
# occupied before the upgrade.
if [[ -d "$BK/legacy-runtimes" ]]; then
  for d in "$BK/legacy-runtimes"/*; do
    [[ -e "$d" ]] || continue
    dest="$BASE/tools/$(basename "$d")"
    [[ -e "$dest" ]] || mv "$d" "$dest"
  done
fi
if [[ -d "$BK/legacy-scripts" ]]; then
  for f in "$BK/legacy-scripts"/*; do
    [[ -e "$f" ]] || continue
    dest="$BASE/scripts/$(basename "$f")"
    [[ -e "$dest" ]] || mv "$f" "$dest"
  done
fi

echo 'Rollback abgeschlossen. FFXI Center kann wieder normal gestartet werden.'
