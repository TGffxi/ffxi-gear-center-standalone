#!/usr/bin/env bash
set -Eeuo pipefail
APP="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BASE="$HOME/Games/FFXI"
RUNTIME="$BASE/tools/Gearplaner-1.0"
SCRIPTS="$BASE/scripts"
fail=0
ok(){ echo "[OK] $*"; }
bad(){ echo "[ERR] $*" >&2; fail=1; }

[[ "$(cat "$APP/VERSION" 2>/dev/null)" == '1.0.2' ]] && ok 'Version 1.0.2' || bad 'VERSION'

for d in gp05 gp06 gp07 gp08; do [[ ! -e "$APP/public/assets/$d" ]] || bad "Alt-Asset $d vorhanden"; done
[[ ! -e "$APP/gcwork5" && ! -e "$APP/ffxi-gear-center-v0.8.0-augment-display-hotfix" ]] && ok 'keine eingebetteten Alt-/Hotfix-Bäume' || bad 'Altbaum vorhanden'
[[ ! -d "$APP/public/api" ]] && ok 'keine zweite/alte Web-API neben der 1.0-Runtime' || bad 'alte public/api vorhanden'
[[ ! -d "$APP/tests" ]] && ok 'keine Entwicklungs-Testbäume in der installierten App' || bad 'App-Testbaum vorhanden'
[[ ! -f "$APP/bin/serve.sh" && ! -f "$APP/bin/watch.sh" && ! -f "$APP/bin/sync.sh" ]] && ok 'keine konkurrierenden Alt-Server/Watcher' || bad 'alter Server/Watcher vorhanden'
[[ ! -f "$APP/src/GearFinderImporter.php.bak" ]] && ok 'keine .bak-Quelldatei' || bad '.bak vorhanden'

CORE_MANIFEST="$APP/docs/LIVE-TESTED-CORE.sha256"
CORE_OVERRIDES="$APP/docs/LIVE-TESTED-CORE-OVERRIDES.json"
if [[ -f "$CORE_MANIFEST" && -f "$CORE_OVERRIDES" ]]; then
  python3 - "$APP" <<'PYCORE'     && ok 'historischer Live-Core erhalten; nur dokumentierte RC3-Zielfixes weichen ab'     || bad 'historischer Core oder dokumentierte RC3-Overrides weichen ab'
import hashlib,json,sys
from pathlib import Path
app=Path(sys.argv[1])
manifest=app/'docs/LIVE-TESTED-CORE.sha256'
cfg=json.loads((app/'docs/LIVE-TESTED-CORE-OVERRIDES.json').read_text(encoding='utf-8'))
over=cfg.get('files') or {}
seen=set(); untouched=0
for raw in manifest.read_text(encoding='utf-8').splitlines():
    raw=raw.strip()
    if not raw or raw.startswith('#'): continue
    baseline,rel=raw.split(None,1); rel=rel.strip().lstrip('*'); seen.add(rel)
    row=over.get(rel)
    expected=str((row or {}).get('sha256') or baseline)
    if row:
        if str(row.get('baseline_sha256') or '') != baseline: raise SystemExit('baseline hash mismatch: '+rel)
        if not str(row.get('reason') or '').strip(): raise SystemExit('override reason missing: '+rel)
    else: untouched += 1
    p=app/rel
    if not p.is_file(): raise SystemExit('core file missing: '+rel)
    actual=hashlib.sha256(p.read_bytes()).hexdigest()
    if actual!=expected: raise SystemExit('core hash mismatch: '+rel)
if set(over)-seen: raise SystemExit('override not in baseline: '+', '.join(sorted(set(over)-seen)))
if untouched < 40: raise SystemExit('too much live core overridden')
PYCORE
else
  bad 'LIVE-TESTED-CORE manifest/override contract fehlt'
fi

JS="$APP/public/assets/gp10/embedded-panel.js"
STATEJS="$APP/public/assets/gp10/character-state.js"
APPJS="$APP/public/assets/gp10/app.js"
for f in "$JS" "$STATEJS" "$APPJS"; do [[ -f "$f" ]] || bad "Frontend fehlt: $f"; done

python3 - "$JS" <<'PY' || fail=1
from pathlib import Path
import re,sys
s=Path(sys.argv[1]).read_text(encoding='utf-8')
m=re.search(r"tabs\(\)\{const x=\{(.*?)\};return",s,re.S)
if not m: raise SystemExit('[ERR] Navigation nicht erkannt')
labels=re.findall(r"\w+:'([^']+)'",m.group(1))
expected=['Übersicht','Inventar','Gearplanung','Farmplan','Gear-Vergleich','Charakter','Datenstatus']
if labels != expected: raise SystemExit(f'[ERR] Hauptnavigation: {labels!r}')
if len(labels) != len(set(labels)): raise SystemExit('[ERR] doppelte Hauptnavigation')
print('[OK] genau eine Hauptnavigation mit 7 erwarteten Bereichen')
PY

grep -q 'Diese Variante als Ziel' "$JS" && grep -q 'Alle Varianten als Ziele' "$JS" && ok 'Set-/Varianten-Zielbedienung' || bad 'Zielbedienung'
grep -q 'physicalInventoryRows' "$JS" && grep -q 'physicalInventoryRows' "$STATEJS" && ok 'physische Inventarinstanzen statt Item-Aggregation' || bad 'physische Inventarinstanzen'
grep -q 'activeElement' "$JS" && grep -q 'selectionStart' "$JS" && grep -q 'setSelectionRange' "$JS" && ok 'Suchfokus/Cursor über Auto-Refresh erhalten' || bad 'Focus-/Caret-Regressionsschutz'
grep -q "signature(d){return String(d?.revision" "$JS" && ok 'UI-Refresh folgt Dashboard-Revision' || bad 'Dashboard-Revision nicht UI-wirksam'
grep -q 'refreshSeq' "$JS" && ok 'überlappende Refreshes geschützt' || bad 'Refresh-Race-Schutz'
grep -q 'Jedes physische Exemplar bleibt eindeutig' "$JS" && ok 'Duplicate-Augment-UX eindeutig' || bad 'Duplicate-Augment-UX'

grep -q 'Ziel entfernen' "$JS" && grep -q 'data-action="remove"' "$JS" && ok 'persönliche Ziele sichtbar löschbar' || bad 'Ziele löschen'
grep -q 'BG-Wiki, Bezugsweg, Progression & Materialien' "$JS" && grep -q 'itemHref' "$JS" && ok 'Items aus Inventar/Plan/Farmplan wieder aufrufbar' || bad 'Item-Drilldown/BG-Wiki fehlt'
grep -q 'Nach Bezugsweg / Inhalt gruppiert' "$JS" && grep -q 'Kompakte Routenübersicht' "$JS" && grep -q 'route-list' "$JS" && ok 'Farm-Ressourcen kompakt nach Bezugsweg/Inhalt gruppiert' || bad 'Bezugsweg-Gruppierung/kompakte Routenansicht fehlt'
grep -q 'Meine Aufgaben / To-Dos' "$JS" && grep -q 'Meine LuAshitacast-Sets' "$JS" && ok '0.2/0.3 Funktionen kontextuell erreichbar' || bad 'ältere Kernfunktionen nicht erreichbar'
grep -q 'Guide-Verifikation öffnen' "$JS" && grep -q 'Guide-Ziele / Verifikation' "$APP/public/advanced.php" && ok '0.4 Target-Verifikation kontextuell erreichbar' || bad 'Target-Verifikation nicht erreichbar'
grep -q 'Zusätzliche Item-/Set-Ziele & Notizen' "$JS" && grep -q 'Meine Ziele & Notizen' "$APP/public/advanced.php" && grep -q 'goal_delete' "$APP/public/advanced.php" && ok 'ältere Ziele/Notizen bleiben erreichbar und löschbar' || bad 'Ziele-/Notiz-Drilldown'
grep -q 'Schlüsselitems' "$JS" && grep -q 'keyItemsTable' "$JS" && ok 'Schlüsselitems unter Charakter erhalten' || bad 'Schlüsselitems fehlen'
grep -q 'Datenstatus ansehen' "$JS" && grep -q 'Datenstatus prüfen' "$JS" && grep -q 'Gearplanung öffnen' "$JS" \
  && grep -q 'Im Item-Katalog weitersuchen' "$JS" && grep -q 'data-plan-reset' "$JS" && grep -q 'data-currency-reset' "$JS" \
  && grep -q 'data-open-currency' "$JS" && grep -q 'data-open-set' "$JS" && grep -q 'Passende Sets in Gearplanung suchen' "$JS" \
  && grep -q 'Erneut versuchen' "$JS" && ok 'leere/wartende/gefilterte Zustände haben nächsten Schritt' || bad 'Sackgassen in Leer-/Filterzuständen'
grep -q 'Bezug zu deiner Planung' "$JS" && grep -q 'Auf BG-Wiki nachschlagen' "$JS" && ok 'Charakterdaten führen kontextuell weiter' || bad 'Charakterdaten ohne Drilldown'
grep -q 'Noch nicht im lokalen Item-Katalog' "$APP/public/advanced.php" && grep -q 'Auf BG-Wiki suchen' "$APP/public/advanced.php" && grep -q 'Als eigenes Item-Ziel speichern' "$APP/public/advanced.php" \
  && grep -q 'Keine Items passen zu diesen Filtern' "$APP/public/advanced.php" && grep -q 'Keine Guide-Ziele passen zu diesen Filtern' "$APP/public/advanced.php" \
  && grep -q 'Keine Items passen zu diesem Coverage-Filter' "$APP/public/advanced.php" && ok 'unaufgelöste/leer gefilterte Detailansichten enden nicht als Sackgasse' || bad 'Detailansicht ist Sackgasse'
grep -q '\$compact' "$APP/public/advanced.php" && grep -q 'BG-Wiki: Bezugsweg & Progression' "$APP/public/advanced.php" && grep -q 'Bezugswege' "$APP/public/advanced.php" && grep -q 'Materialien / Währungen' "$APP/public/advanced.php" && ok 'kompakte Itemdetails behalten BG-Wiki/Bezugsweg/Progression/Materialien' || bad 'BG-Wiki-Itemdetails'
grep -q 'isAugmentReferenceFields' "$APP/public/advanced.php" && grep -q 'technische Augment-Statzeilen sind in der normalen Ansicht ausgeblendet' "$APP/public/advanced.php" && ok 'kompakte Itemdetails blenden technische JSE-Augmentmatrix aus' || bad 'JSE-Augmentmatrix bläht kompakte Itemdetails auf'

DB="$APP/data/gear-center.sqlite"
if [[ -f "$DB" ]]; then
  python3 - "$DB" <<'PYDB' || fail=1
import sqlite3,sys
db=sys.argv[1]
con=sqlite3.connect(db)
try:
    pages=con.execute('select count(*) from bgwiki_pages').fetchone()[0]
    records=con.execute('select count(*) from bgwiki_progression_records').fetchone()[0]
finally:
    con.close()
if pages == 0 and records == 0:
    print('[OK] Öffentlicher Sanitized-Seed enthält absichtlich keinen gebündelten BG-Wiki-Cache; lokale Daten werden vom Nutzer aufgebaut.')
elif pages >= 700 and records >= 6000:
    print(f'[OK] Lokaler BG-Wiki-Datenbestand vorhanden: {pages} Seiten · {records} strukturierte Datensätze')
else:
    raise SystemExit(f'[ERR] BG-Wiki-Datenbestand ist weder leerer Public-Seed noch vollständiger lokaler Bestand: pages={pages}, records={records}')
PYDB
else
  bad 'gear-center.sqlite fehlt'
fi

AUG="$RUNTIME/bridge/augment_state.py"
[[ -f "$AUG" ]] || bad 'augment_state.py fehlt'
if [[ -f "$AUG" ]]; then
  grep -q 'augment_strings' "$AUG" && grep -q 'raw_extra_hex' "$AUG" && grep -q 'physical_count' "$AUG" && grep -q 'used: set' "$AUG" \
    && ok 'Augmentformat + one-to-one Matching vorhanden' || bad 'Augment-Regressionsschutz'
fi

SERVER="$RUNTIME/bridge/server.py"
BRIDGE_LUA="$BASE/ashita/addons/GearplanerBridge/GearplanerBridge.lua"
[[ -f "$BRIDGE_LUA" ]] && grep -q 'HasKeyItem' "$BRIDGE_LUA" && grep -q 'key_items = build_key_items' "$BRIDGE_LUA" && ok 'Schlüsselitem-Export aus FFXI vorhanden' || bad 'Schlüsselitem-Export fehlt'
if [[ -f "$SERVER" ]]; then
  if grep -qE '/api/v1/(combat|live-test|live_test)' "$SERVER"; then bad 'retired Combat-/Live-Test-Endpunkt vorhanden'; else ok 'keine retired Combat-/Live-Test-Endpunkte'; fi
else bad 'server.py fehlt'; fi

[[ -f "$APP/public/advanced.php" ]] && ok 'Expertendiagnose getrennt' || bad 'advanced.php fehlt'
if grep -qE 'Übersicht.*Inventar.*Gearplanung.*Farmplan' "$APP/public/advanced.php" 2>/dev/null; then bad 'zweite normale Hauptnavigation in Advanced'; else ok 'Advanced ist keine doppelte Hauptnavigation'; fi

WEBSTART="$SCRIPTS/start-gear-center-web.sh"
[[ -f "$WEBSTART" ]] && grep -q -- '-t public public/router.php' "$WEBSTART" && ok 'Web Document-Root = public' || bad 'Web Document-Root'
SERVICES="$SCRIPTS/gear-center-services.sh"
[[ -f "$SERVICES" ]] && grep -q 'OWNERS=' "$SERVICES" && grep -q 'acquire)' "$SERVICES" && grep -q 'release)' "$SERVICES" && grep -q 'proc_starttime' "$SERVICES" && grep -q 'starttime=' "$SERVICES" && ok 'Owner-Token Lifecycle inkl. PID-Reuse-Schutz' || bad 'Owner-Token Lifecycle'
[[ -f "$SERVICES" ]] && grep -q 'standalone.owner.pid' "$SERVICES" && grep -q '_standalone-owner' "$SERVICES" && grep -q 'manual_start' "$SERVICES" && grep -q 'GEAR_CENTER_LIFECYCLE=1' "$SERVICES" && grep -q 'GEAR_CENTER_LIFECYCLE' "$SCRIPTS/start-gear-center-api.sh" && grep -q 'GEAR_CENTER_LIFECYCLE' "$SCRIPTS/start-gear-center-web.sh" && ok 'Standalone-Lifecycle + geschützte interne Service-Starter' || bad 'Standalone-/Service-Lifecycle'
OPENSTART="$SCRIPTS/open-gear-center.sh"
[[ -f "$OPENSTART" ]] && grep -q 'owner_active' "$OPENSTART" && ! grep -q 'php -S' "$OPENSTART" && ! grep -q 'server.py' "$OPENSTART" && ok 'Gear-Center-Öffner akzeptiert validierten Standalone-/Center-Owner und startet nichts selbst' || bad 'Gear-Center-Öffner umgeht Lifecycle'

# Syntax checks for installed production code.
while IFS= read -r -d '' f; do php -l "$f" >/dev/null || { bad "PHP Syntax: $f"; break; }; done < <(find "$APP/public" "$APP/src" "$APP/bin" -type f -name '*.php' -print0)
[[ "$fail" -eq 0 ]] && ok 'PHP Syntax' || true
if command -v node >/dev/null; then
  node --check "$STATEJS" >/dev/null && node --check "$JS" >/dev/null && node --check "$APPJS" >/dev/null && ok 'JavaScript Syntax' || bad 'JavaScript Syntax'
fi
if python3 - "$RUNTIME/bridge" <<'PYSYNTAX' >/dev/null 2>&1
from pathlib import Path
import sys
for p in Path(sys.argv[1]).glob('*.py'): compile(p.read_text(encoding='utf-8'), str(p), 'exec')
PYSYNTAX
then ok 'Python Runtime Syntax'; else bad 'Python Runtime Syntax'; fi
for f in "$SCRIPTS"/gear-center-services.sh "$SCRIPTS"/start-gear-center-api.sh "$SCRIPTS"/start-gear-center-web.sh "$SCRIPTS"/start-ffxi-center-with-gear-center.sh; do bash -n "$f" || bad "Shell Syntax: $f"; done

[[ "$fail" -eq 0 ]] || exit 1
echo '===== ACCEPTANCE: PASS ====='
