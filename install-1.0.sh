#!/usr/bin/env bash
set -Eeuo pipefail
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BASE="$HOME/Games/FFXI"; APP="$BASE/tools/ffxi-gear-center"; RUNTIME="$BASE/tools/Gearplaner-1.0"
CENTER="$BASE/tools/FFXI-Center/ffxi_center.py"; CENTER_START="$BASE/scripts/start-ffxi-center.sh"
ASHITA="$BASE/ashita"; SCRIPTS="$BASE/scripts"; BACKUPS="$BASE/backups"
STATE="$BASE/data/Gearplaner"; STAMP="$(date +%Y%m%d-%H%M%S)"; BK="$BACKUPS/ffxi-gear-center-v1.0.2-before-$STAMP"
CENTER_AVAILABLE=0; [[ -f "$CENTER" ]] && CENTER_AVAILABLE=1

# Public build: never hard-code a player name. Prefer an explicit environment
# override, otherwise auto-detect exactly one LuAshitacast character directory.
CHARACTER="${GEAR_CENTER_CHARACTER:-}"
if [[ -z "$CHARACTER" && -s "$APP/data/character.txt" ]]; then
  CHARACTER="$(tr -d '\r\n' < "$APP/data/character.txt")"
fi
if [[ -z "$CHARACTER" ]]; then
  mapfile -t _chars < <(python3 - "$ASHITA/config/addons/LuAshitacast" <<'PYCHAR'
from pathlib import Path
import re,sys
base=Path(sys.argv[1])
names=set()
if base.is_dir():
    for p in base.iterdir():
        if not p.is_dir() or p.name.lower()=='common':
            continue
        m=re.match(r'^(.+?)_(\d+)$',p.name)
        if m:
            names.add(m.group(1))
for n in sorted(names,key=str.casefold):
    print(n)
PYCHAR
  )
  if (( ${#_chars[@]} == 1 )); then
    CHARACTER="${_chars[0]}"
  elif (( ${#_chars[@]} == 0 )); then
    echo '[ERR] Kein LuAshitacast-Charakter gefunden.' >&2
    echo '      Setze vor dem Installer z.B.: export GEAR_CENTER_CHARACTER="PlayerName"' >&2
    exit 1
  else
    echo '[ERR] Mehrere LuAshitacast-Charaktere gefunden:' >&2
    printf '      %s\n' "${_chars[@]}" >&2
    echo '      Bitte auswählen: export GEAR_CENTER_CHARACTER="PlayerName"' >&2
    exit 1
  fi
fi
[[ "$CHARACTER" =~ ^[A-Za-z0-9._-]+$ ]] || { echo "[ERR] Ungültiger Charaktername: $CHARACTER" >&2; exit 1; }

if [[ -n "${GEAR_CENTER_BOOT:-}" ]]; then
  BOOT="$GEAR_CENTER_BOOT"
else
  mapfile -t _boots < <(find "$ASHITA/scripts" -maxdepth 1 -type f -iname "$CHARACTER.txt" -print 2>/dev/null | sort)
  if (( ${#_boots[@]} != 1 )); then
    echo "[ERR] Ashita-Bootskript für $CHARACTER nicht eindeutig gefunden." >&2
    printf '      Kandidaten: %s\n' "${_boots[*]:-keine}" >&2
    echo '      Optional explizit setzen: export GEAR_CENTER_BOOT="/pfad/zum/script.txt"' >&2
    exit 1
  fi
  BOOT="${_boots[0]}"
fi
POINTS="$ASHITA/addons/points/points.lua"; [[ -f "$POINTS" ]] || POINTS="$ASHITA/addons/Points/points.lua"
BRIDGE="$ASHITA/addons/GearplanerBridge"; LAC_COMMON="$ASHITA/config/addons/LuAshitacast/common"; LACBRIDGE="$LAC_COMMON/gearcenter_bridge.lua"; GCINCLUDE="$LAC_COMMON/gcinclude.lua"
CAPTURE_ADDON="$ASHITA/addons/gearlab_capture"; CAPTURE_CFG="$ASHITA/config/addons/gearlab_capture"
TMP="$(mktemp -d -t gearcenter10.XXXXXX)"
MUTATION_STARTED=0
INSTALL_COMPLETE=0
cleanup(){
  local rc=$?
  rm -rf "$TMP"
  if [[ "$rc" -ne 0 && "$MUTATION_STARTED" -eq 1 && "$INSTALL_COMPLETE" -eq 0 && -d "$BK" ]]; then
    echo >&2
    echo '[ERR] Installation fehlgeschlagen. Der gesicherte Vorzustand wird automatisch wiederhergestellt.' >&2
    if "$HERE/rollback-1.0.sh" "$BK" >&2; then
      echo '[OK] Automatischer Rollback abgeschlossen.' >&2
    else
      echo "[ERR] Automatischer Rollback ist fehlgeschlagen. Backup bleibt erhalten: $BK" >&2
    fi
  fi
  exit "$rc"
}
trap cleanup EXIT

echo '===== FFXI Gear Center 1.0.2 STANDALONE ====='
echo "Basis  : $BASE"; echo "Charakter: $CHARACTER"; echo "Boot   : $BOOT"; echo "Backup : $BK"; echo

echo '===== 1/9 VORABPRÜFUNG ====='
for f in "$BOOT" "$POINTS"; do [[ -f "$f" ]] || { echo "[ERR] fehlt: $f" >&2; exit 1; }; done
if [[ "$CENTER_AVAILABLE" -eq 1 ]]; then
  python3 - "$CENTER" <<'PYRUN'
from pathlib import Path
import os,sys
center=Path(sys.argv[1]).resolve()
found=[]
for e in os.scandir('/proc'):
    if not e.name.isdigit(): continue
    try:
        raw=Path(f'/proc/{e.name}/cmdline').read_bytes().split(b'\0')
        args=[x.decode(errors='ignore') for x in raw if x]
    except OSError:
        continue
    if len(args) < 2: continue
    try: target=Path(args[1]).resolve()
    except Exception: continue
    if target == center:
        found.append(e.name)
if found:
    raise SystemExit('[ERR] FFXI Center läuft noch (PID '+', '.join(found)+'). Bitte alle Center-Fenster schließen und Installer erneut starten.')
PYRUN
else
  echo '[OK] FFXI Center nicht gefunden; Standalone-Lifecycle wird installiert.'
fi
[[ -d "$ASHITA" ]] || { echo "[ERR] Ashita fehlt: $ASHITA" >&2; exit 1; }
[[ -f "$GCINCLUDE" ]] || { echo "[ERR] LuAshitacast gcinclude.lua fehlt: $GCINCLUDE" >&2; exit 1; }
command -v python3 >/dev/null; command -v php >/dev/null; command -v curl >/dev/null; command -v ss >/dev/null; command -v flock >/dev/null
php -r 'exit(version_compare(PHP_VERSION,"8.2.0",">=")?0:1);' || { echo '[ERR] PHP >= 8.2 erforderlich.' >&2; exit 1; }
php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' || { echo '[ERR] PHP-Erweiterung pdo_sqlite ist erforderlich (Item-Katalog, BG-Wiki, Bezugswege, Progression und To-Dos).' >&2; exit 1; }
python3 "$HERE/payload/points/patch_points_export.py" --file "$POINTS" --hook "$HERE/payload/points/gearplaner_points_export.lua.inc" --check
python3 - "$HERE/payload/runtime/bridge" "$HERE/payload/points/patch_points_export.py" <<'PYSYNTAX'
from pathlib import Path
import sys
files=list(Path(sys.argv[1]).glob('*.py'))+[Path(sys.argv[2])]
for p in files: compile(p.read_text(encoding='utf-8'), str(p), 'exec')
PYSYNTAX
for f in "$HERE/payload/scripts/"*.sh; do bash -n "$f"; done
if command -v node >/dev/null; then node --check "$HERE/payload/app/public/assets/gp10/character-state.js" >/dev/null; node --check "$HERE/payload/app/public/assets/gp10/embedded-panel.js" >/dev/null; node --check "$HERE/payload/app/public/assets/gp10/app.js" >/dev/null; fi
python3 - "$HERE/payload/center/patch_ffxi_center.py" <<'PYSYNTAX'
from pathlib import Path
import sys
p=Path(sys.argv[1]); compile(p.read_text(encoding='utf-8'), str(p), 'exec')
PYSYNTAX
if [[ "$CENTER_AVAILABLE" -eq 1 ]]; then
  python3 - "$CENTER" <<'PY'
from pathlib import Path
import sys
s=Path(sys.argv[1]).read_text(encoding='utf-8',errors='ignore')
need=['class Center(tk.Tk):','def refresh_all(self):']
miss=[x for x in need if x not in s]
if miss: raise SystemExit('Center-Struktur nicht kompatibel: '+', '.join(miss))
if 'STARTERS' not in s: raise SystemExit('Center STARTERS-Dictionary nicht gefunden')
PY
fi

echo '--- Paket-Regressionen 0.1–0.8 / 1.0.2 ---'
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_feature_contract.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_hotfix_contract.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_rules_regressions.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_historical_runtime_contract.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_package_cleanliness.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_regressions.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_center_patch.py"
PYTHONDONTWRITEBYTECODE=1 python3 "$HERE/tests/test_standalone_contract.py"
if command -v node >/dev/null; then node "$HERE/tests/test_ui_helpers.mjs"; fi
php "$HERE/tests/progression-chain-smoke.php"
php "$HERE/tests/bgwiki-encoding-smoke.php"

echo '[OK] Voraussetzungen + historische Funktionsregressionen'

# Installer is never a lifecycle owner. Use the NEW package recognizer so
# legacy product orphans cannot keep serving stale deleted gp08/gp10 assets.
"$HERE/payload/scripts/gear-center-services.sh" prepare-install

# Real PHP+PDO-SQLite pre-mutation gate. The test clones the selected database
# with SQLite VACUUM INTO and mutates ONLY that disposable clone. Existing
# installations are checked against their real DB; fresh installs use seed data.
PDO_QA_DB="$APP/data/gear-center.sqlite"
if [[ -f "$PDO_QA_DB" ]]; then
  php "$HERE/tests/test_live_sqlite_lkg.php" "$PDO_QA_DB" || {
    echo '[ERR] Live-SQLite Last-Known-Good E2E fehlgeschlagen; Installation wurde vor jeder Änderung abgebrochen.' >&2
    exit 1
  }
  php "$HERE/tests/test_php_pdo_e2e.php" "$PDO_QA_DB" || {
    echo '[ERR] PHP+PDO-SQLite E2E fehlgeschlagen; Installation wurde vor jeder Änderung abgebrochen.' >&2
    exit 1
  }
  echo '[OK] PHP+PDO-SQLite Clone-E2E gegen vorhandene Installation bestanden.'
else
  echo '[OK] Frische öffentliche Installation: Seed ist absichtlich schema-only; tiefer Daten-E2E folgt nach dem ersten lokalen Import.'
fi

echo; echo '===== 2/9 BACKUP ====='
mkdir -p "$BK" "$SCRIPTS" "$STATE"
# Presence manifest makes rollback exact even when 1.0.1 created a component
# that did not exist before the upgrade. Values are deliberately simple 0/1.
{
  [[ -d "$APP" ]] && echo 'app=1' || echo 'app=0'
  [[ -d "$RUNTIME" ]] && echo 'runtime=1' || echo 'runtime=0'
  [[ -f "$CENTER_START" ]] && echo 'center_start=1' || echo 'center_start=0'
  [[ -d "$BRIDGE" ]] && echo 'gearplaner_bridge=1' || echo 'gearplaner_bridge=0'
  [[ -f "$LACBRIDGE" ]] && echo 'lac_bridge=1' || echo 'lac_bridge=0'
  [[ -f "$GCINCLUDE" ]] && echo 'gcinclude=1' || echo 'gcinclude=0'
  [[ -d "$CAPTURE_ADDON" ]] && echo 'capture_addon=1' || echo 'capture_addon=0'
  [[ -d "$CAPTURE_CFG" ]] && echo 'capture_config=1' || echo 'capture_config=0'
  [[ -f "$STATE/requirements.json" ]] && echo 'state_requirements=1' || echo 'state_requirements=0'
  [[ -f "$STATE/goals.json" ]] && echo 'state_goals=1' || echo 'state_goals=0'
  [[ -f "$STATE/performance-cache.json" ]] && echo 'state_performance=1' || echo 'state_performance=0'
  for f in gear-center-services.sh gear-center-status.sh open-gear-center.sh start-ffxi-center-with-gear-center.sh start-gear-center-api.sh start-gear-center-web.sh; do
    [[ -f "$SCRIPTS/$f" ]] && echo "script:$f=1" || echo "script:$f=0"
  done
} > "$BK/presence.txt"
[[ -d "$APP" ]] && tar -C "$(dirname "$APP")" -czf "$BK/ffxi-gear-center-code.tar.gz" "$(basename "$APP")"
[[ -d "$RUNTIME" ]] && tar -C "$(dirname "$RUNTIME")" -czf "$BK/Gearplaner-1.0.tar.gz" "$(basename "$RUNTIME")"
[[ "$CENTER_AVAILABLE" -eq 0 ]] || cp -a "$CENTER" "$BK/ffxi_center.py"
cp -a "$CENTER_START" "$BK/start-ffxi-center.sh" 2>/dev/null || true
mkdir -p "$BK/scripts"
for f in gear-center-services.sh gear-center-status.sh open-gear-center.sh start-ffxi-center-with-gear-center.sh start-gear-center-api.sh start-gear-center-web.sh; do
  [[ ! -f "$SCRIPTS/$f" ]] || cp -a "$SCRIPTS/$f" "$BK/scripts/$f"
done
printf '%s\n' "$BOOT" > "$BK/boot-path.txt"
cp -a "$BOOT" "$BK/boot-script.txt"
cp -a "$POINTS" "$BK/points.lua"
mkdir -p "$BK/state"
for f in requirements.json goals.json performance-cache.json; do [[ ! -f "$STATE/$f" ]] || cp -a "$STATE/$f" "$BK/state/$f"; done
[[ -d "$BRIDGE" ]] && cp -a "$BRIDGE" "$BK/GearplanerBridge"
[[ -f "$LACBRIDGE" ]] && cp -a "$LACBRIDGE" "$BK/gearcenter_bridge.lua"
[[ -f "$GCINCLUDE" ]] && cp -a "$GCINCLUDE" "$BK/gcinclude.lua"
if [[ -d "$CAPTURE_ADDON" || -d "$CAPTURE_CFG" ]]; then
  mkdir -p "$BK/retired-combat-capture"
  [[ ! -d "$CAPTURE_ADDON" ]] || cp -a "$CAPTURE_ADDON" "$BK/retired-combat-capture/addon"
  [[ ! -d "$CAPTURE_CFG" ]] || cp -a "$CAPTURE_CFG" "$BK/retired-combat-capture/config"
fi
echo '[OK] Bestand gesichert'

echo; echo '===== 3/9 WEBAPP KONSOLIDIEREN ====='
MUTATION_STARTED=1
mkdir -p "$TMP/keep"
[[ -d "$APP/data" ]] && cp -a "$APP/data" "$TMP/keep/data"
[[ -f "$APP/config/local.php" ]] && cp -a "$APP/config/local.php" "$TMP/keep/local.php"
rm -rf "$APP"; mkdir -p "$APP"; cp -a "$HERE/payload/app/." "$APP/"
if [[ -d "$TMP/keep/data" ]]; then cp -a "$TMP/keep/data" "$APP/data"; else mkdir -p "$APP/data"; cp -a "$HERE/seed-data/." "$APP/data/"; fi
if [[ -f "$TMP/keep/local.php" ]]; then cp -a "$TMP/keep/local.php" "$APP/config/local.php"; else cp -a "$APP/config/local.php.example" "$APP/config/local.php"; fi
printf '%s\n' "$CHARACTER" > "$APP/data/character.txt"
printf '%s\n' "$CHARACTER" > "$STATE/character.txt"
chmod +x "$APP/bin/"*.sh 2>/dev/null || true
"$APP/shared/bin/php" "$APP/bin/ensure-character.php" "$CHARACTER"
# The historical structured BG-Wiki rows were produced by reparsing a UTF-8
# fragment without an explicit charset. Repair only strictly reversible mojibake
# inside fields_json, after the user's DB has been backed up. The migration is
# transactional and idempotent; no network access and no display-time guessing.
if [[ -f "$APP/data/gear-center.sqlite" && -f "$APP/bin/fix-bgwiki-encoding.php" ]]; then
  "$APP/shared/bin/php" "$APP/bin/fix-bgwiki-encoding.php" "$APP/data/gear-center.sqlite"
fi
echo '[OK] Ein UI-Core, gp10, bestehende DB/Cache erhalten + BG-Wiki UTF-8-Strukturdaten bereinigt'

echo; echo '===== 4/9 LIVE-RUNTIME + COLLECTOR ====='
rm -rf "$RUNTIME"; mkdir -p "$RUNTIME"; cp -a "$HERE/payload/runtime/." "$RUNTIME/"
rm -rf "$BRIDGE"; mkdir -p "$(dirname "$BRIDGE")"; cp -a "$HERE/payload/ashita/GearplanerBridge" "$BRIDGE"
mkdir -p "$(dirname "$LACBRIDGE")"; cp -a "$HERE/payload/lac/gearcenter_bridge.lua" "$LACBRIDGE"
# Live-tested LuAshitacast integration: load the established bridge in gcinclude
# and call Tick() from CheckDefault(). Marker blocks make this idempotent.
python3 - "$GCINCLUDE" <<'PYLAC'
from pathlib import Path
import re,sys
p=Path(sys.argv[1]); s=p.read_text(encoding='utf-8',errors='surrogateescape')
s=re.sub(r'\n?\s*-- GEAR CENTER AUGMENT BRIDGE BEGIN\n.*?-- GEAR CENTER AUGMENT BRIDGE END\n?', '\n', s, flags=re.S)
s=re.sub(r'\n?\s*-- GEAR CENTER AUGMENT BRIDGE TICK BEGIN\n.*?-- GEAR CENTER AUGMENT BRIDGE TICK END\n?', '\n', s, flags=re.S)
load="\n-- GEAR CENTER AUGMENT BRIDGE BEGIN\nlocal GearCenterBridge = gFunc.LoadFile('common/gearcenter_bridge.lua');\n-- GEAR CENTER AUGMENT BRIDGE END\n"
m=re.search(r"^.*gcdisplay\s*=\s*gFunc\.LoadFile\(['\"]common[\\\\/]gcdisplay\.lua['\"]\).*$",s,re.M)
if m: s=s[:m.end()]+load+s[m.end():]
else:
    lines=s.splitlines(True); lines.insert(1,load); s=''.join(lines)
func='function gcinclude.CheckDefault()'
if func not in s: raise SystemExit('[ERR] gcinclude.CheckDefault() nicht gefunden; keine Änderung geschrieben.')
tick="function gcinclude.CheckDefault()\n\t-- GEAR CENTER AUGMENT BRIDGE TICK BEGIN\n\tif (GearCenterBridge ~= nil and GearCenterBridge.Tick ~= nil) then GearCenterBridge.Tick(); end\n\t-- GEAR CENTER AUGMENT BRIDGE TICK END"
s=s.replace(func,tick,1)
p.write_text(s,encoding='utf-8',errors='surrogateescape')
print('[OK] gcinclude.lua: live-getestete Gear-Center Augment-Bridge eingebunden')
PYLAC
# Retired Gear-Lab Combat Capture is not part of Gear Center. Archive was made above.
rm -rf "$CAPTURE_ADDON" "$CAPTURE_CFG"
# Reuse Points; only maintain its small read-only export hook.
python3 "$HERE/payload/points/patch_points_export.py" --file "$POINTS" --hook "$HERE/payload/points/gearplaner_points_export.lua.inc" --backup-dir "$BK"
# Migrate old planning state only if current path is empty.
for old in "$BASE/data/Gearplaner-0.5"; do
  [[ -f "$STATE/requirements.json" ]] || [[ ! -f "$old/requirements.json" ]] || cp -a "$old/requirements.json" "$STATE/requirements.json"
  [[ -f "$STATE/goals.json" ]] || [[ ! -f "$old/goals.json" ]] || cp -a "$old/goals.json" "$STATE/goals.json"
done
[[ -f "$STATE/requirements.json" ]] || printf '{"schema":"gearplaner.requirements/0.6","tasks":[]}\n' > "$STATE/requirements.json"
[[ -f "$STATE/goals.json" ]] || printf '{"schema":"gearplaner.goals/0.6","goals":[]}\n' > "$STATE/goals.json"
# Rebuild only the generated guide catalog from the already verified local target DB.
# Manual requirements and personal goals remain untouched.
if php -r 'exit(extension_loaded("pdo_sqlite")?0:1);' && [[ -f "$APP/data/gear-center.sqlite" ]]; then
  GEARCENTER_REQUIREMENTS="$STATE/requirements.json" php "$APP/bin/sync-targets-to-gearcenter.php" --jobs=ALL
else
  echo '[WARN] Guide-Set-Sync übersprungen (pdo_sqlite/DB fehlt); vorhandene requirements.json bleibt erhalten.'
fi
echo '[OK] Runtime 1.0.2 + GearplanerBridge + Points-Hook + lokaler Guide-Katalog'

echo; echo '===== 5/9 START/STOP-LIFECYCLE ====='
for f in "$HERE/payload/scripts/"*.sh; do cp -a "$f" "$SCRIPTS/$(basename "$f")"; done
chmod +x "$SCRIPTS"/start-gear-center-api.sh "$SCRIPTS"/start-gear-center-web.sh "$SCRIPTS"/open-gear-center.sh "$SCRIPTS"/gear-center-services.sh "$SCRIPTS"/start-ffxi-center-with-gear-center.sh
if [[ "$CENTER_AVAILABLE" -eq 1 ]]; then
  cat > "$CENTER_START" <<'WRAP'
#!/usr/bin/env bash
set -Eeuo pipefail
# Optional compatibility integration: ffxi_center.py itself owns its lifecycle token.
exec python3 "$HOME/Games/FFXI/tools/FFXI-Center/ffxi_center.py"
WRAP
  chmod +x "$CENTER_START"
  echo '[OK] Optionaler FFXI-Center Owner-Lifecycle installiert; Standalone bleibt unabhängig nutzbar.'
else
  echo '[OK] Standalone-Lifecycle installiert; keine FFXI-Center-Dateien benötigt oder verändert.'
fi

echo; echo '===== 6/9 OPTIONALE FFXI-CENTER-INTEGRATION ====='
if [[ "$CENTER_AVAILABLE" -eq 1 ]]; then
  python3 "$HERE/payload/center/patch_ffxi_center.py" "$CENTER"
  python3 - "$CENTER" <<'PYSYNTAX'
from pathlib import Path
import sys
p=Path(sys.argv[1]); compile(p.read_text(encoding='utf-8'), str(p), 'exec')
PYSYNTAX
  N="$(python3 - "$CENTER" <<'PYCOUNT'
from pathlib import Path
import re,sys
s=Path(sys.argv[1]).read_text(encoding='utf-8',errors='ignore')
print(len(re.findall(r'["\'](?:FFXI )?Gear Center["\']\s*:',s,re.I)))
PYCOUNT
)"; [[ "$N" -ge 1 ]] || { echo '[ERR] Gear Center Eintrag fehlt.' >&2; exit 1; }
  echo "[OK] Optional: Gear Center in $N FFXI-Center-Dictionary(s) integriert."
else
  echo '[OK] Übersprungen: FFXI Center ist für Standalone nicht erforderlich.'
fi

echo; echo '===== 7/9 AUTOMATISCHE FFXI-SNAPSHOTS ====='
python3 - "$BOOT" <<'PY'
from pathlib import Path
import re,sys
p=Path(sys.argv[1]); s=p.read_text(encoding='utf-8',errors='ignore')
s=re.sub(r'^\s*/addon\s+(?:load|reload)\s+gearlab_capture(?:\s|$).*\n?', '', s, flags=re.I|re.M)
s=re.sub(r'^\s*/glcapture(?:\s|$).*\n?', '', s, flags=re.I|re.M)
s=re.sub(r'\n?# GEAR_CENTER_V1_AUTOSYNC_BEGIN\n.*?# GEAR_CENTER_V1_AUTOSYNC_END\n?','\n',s,flags=re.S)
active_points=bool(re.search(r'^\s*/addon\s+load\s+points(?:\s|$)',s,re.I|re.M))
active_bridge=bool(re.search(r'^\s*/addon\s+load\s+GearplanerBridge(?:\s|$)',s,re.I|re.M))
lines=['# GEAR_CENTER_V1_AUTOSYNC_BEGIN']
if not active_points: lines.append('/addon load Points')
if not active_bridge: lines.append('/addon load GearplanerBridge')
lines += ['/wait 8','/gppoints export','/wait 4','/gp export','/wait 20','/gppoints export','/wait 2','/gp export','# GEAR_CENTER_V1_AUTOSYNC_END']
p.write_text(s.rstrip()+'\n\n'+'\n'.join(lines)+'\n',encoding='utf-8')
PY
grep -q 'GEAR_CENTER_V1_AUTOSYNC_BEGIN' "$BOOT"
echo '[OK] Login-Export automatisch; spätere Inventar/Currency-Änderungen bleiben Collector-gesteuert'

echo; echo '===== 8/9 OFFLINE ACCEPTANCE ====='
"$APP/bin/v1-acceptance.sh"
# Hard lifecycle gate: the installer itself contains no service acquisition,
# localhost smoke or lifecycle test that can start API/Web.
python3 - "$HERE/install-1.0.sh" <<'PYLIFE'
from pathlib import Path
import re,sys
s=Path(sys.argv[1]).read_text(encoding='utf-8')
# Only inspect the executable shell portion before this self-check heredoc;
# otherwise the forbidden regex literals would match themselves.
shell=s.split("<<'PYLIFE'",1)[0]
forbidden=[
 r'gear-center-services\.sh["\s]+acquire',
 r'(?:python3|bash|\"\$HERE/)[^\n]*tests/test_lifecycle\.sh',
 r'(?:python3|bash|\"\$HERE/)[^\n]*tests/test_api_v1\.py',
 r'GEAR_CENTER_LIFECYCLE=1\s+nohup',
 r'curl[^\n]+127\.0\.0\.1:(?:8765|8787)',
]
hits=[x for x in forbidden if re.search(x,shell)]
if hits: raise SystemExit('[ERR] Installer verletzt Center-only Lifecycle: '+', '.join(hits))
print('[OK] Installer ist serverfrei; API/Web starten erst explizit per Standalone-Lifecycle oder optionalem FFXI Center')
PYLIFE
# Final invariant: clean recognized product orphans but never launch anything.
"$HERE/payload/scripts/gear-center-services.sh" prepare-install

echo; echo '===== 9/9 ALTE RUNTIMES ARCHIVIEREN ====='
mkdir -p "$BK/legacy-runtimes" "$BK/legacy-scripts"
for d in "$BASE/tools/Gearplaner-0.5" "$BASE/tools/Gearplaner-0.6" "$BASE/tools/Gearplaner-0.7" "$BASE/tools/Gearplaner-0.8"; do [[ ! -d "$d" ]] || mv "$d" "$BK/legacy-runtimes/"; done
for pattern in 'gearplaner*.sh' 'start-gearplaner*.sh' 'open-gearplaner*.sh' 'sync-gearplaner*.sh' 'probe-gearplaner*.sh'; do
  for f in "$SCRIPTS"/$pattern; do [[ ! -f "$f" ]] || mv "$f" "$BK/legacy-scripts/"; done
done

INSTALL_COMPLETE=1
echo; echo '============================================================'
echo ' FFXI GEAR CENTER 1.0.2 STANDALONE INSTALLIERT'
echo '============================================================'
echo 'Ab jetzt im Normalbetrieb:'
echo '  Standalone starten:  ~/Games/FFXI/scripts/gear-center-services.sh start'
echo '  Browser öffnen:      ~/Games/FFXI/scripts/gear-center-services.sh open'
echo '  Beenden:             ~/Games/FFXI/scripts/gear-center-services.sh stop'
echo 'FFXI Center ist optional; falls vorhanden, kann dessen Owner-Lifecycle die gleichen Dienste weiterhin sicher teilen.'
echo 'Inventar/Points/Snapshots aktualisieren sich automatisch.'
echo 'Falls einzelne Currency-Werte fehlen, zeigt Datenstatus den Hinweis, das Currencies-Menü einmal zu öffnen.'
echo "Backup: $BK"
