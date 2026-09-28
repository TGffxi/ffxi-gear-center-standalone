from __future__ import annotations

import hashlib
import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "payload/app"
GP10 = APP / "public/assets/gp10"
RUNTIME = ROOT / "payload/runtime/bridge"


def text(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def test_english_items_hotfix_is_locked() -> None:
    lua = text(ROOT / "payload/ashita/GearplanerBridge/GearplanerBridge.lua")
    assert "name = r.Name[1] or r.Name[0] or r.Name[2]" in lua
    assert "logName = r.LogNameSingular[1] or r.LogNameSingular[0] or r.LogNameSingular[2]" in lua
    # Explicitly reject the regression found on the real 2026-09-07 machine state.
    assert "name = r.Name[0] or r.Name[2] or r.Name[1]" not in lua
    assert "logName = r.LogNameSingular[0] or r.LogNameSingular[2] or r.LogNameSingular[1]" not in lua


def test_augment_display_hotfix_is_locked() -> None:
    aug = text(RUNTIME / "augment_state.py")
    ui = text(GP10 / "embedded-panel.js")
    for marker in ("augment_strings", "raw_extra_hex", 'augments.append(f"Path ', 'augments.append(f"Rank ', 'augments.append(f"Trial '):
        assert marker in aug, marker
    # One source row may not be copied to multiple identical physical items.
    for marker in ("used: set[int] = set()", "physical_count", "chosen", "used.add(chosen)"):
        assert marker in aug, marker
    assert "decodedAugments" in ui
    assert "keine ausgelesenen Augments" in ui


def test_inventory_search_hotfix_v2_is_locked() -> None:
    ui = text(GP10 / "embedded-panel.js")
    for marker in (
        "this.inventoryQuery=''",
        "this.inventoryQuery=iq.value",
        'value="${esc(this.inventoryQuery)}"',
        "focusState",
        "selectionStart",
        "selectionEnd",
        "setSelectionRange",
        "preventScroll:true",
    ):
        assert marker in ui, marker


def test_live_ux_hotfix_capabilities_are_locked() -> None:
    ui = text(GP10 / "embedded-panel.js")
    # 1.0.1 keeps the cleaner layout, but the proven 0.8 actions must remain reachable.
    for marker in (
        "Alle Varianten als Ziele",
        "Diese Variante als Ziel",
        "Ziel entfernen",
        "data-plan-reset",
        "data-open-goal",
        "goalRefLink",
        "Guide-Verifikation öffnen",
        "Bezugsweg noch nicht zugeordnet",
        "Nach Bezugsweg / Inhalt gruppiert",
        "Item-Katalog & BG-Wiki",
    ):
        assert marker in ui, marker
    # Item entry points must stay clickable and preserve physical-instance context.
    item_href = "function itemHref(value,returnTab='overview')"
    assert item_href in ui
    for marker in ("instance_container", "instance_slot", "instance_extra"):
        assert marker in ui, marker
    assert '<a href="${itemHref(x,\'inventory\')}"' in ui


def test_coverage_hotfix_is_locked() -> None:
    analyzer = text(APP / "src/CoverageAnalyzer.php")
    advanced = text(APP / "public/advanced.php")
    assert "GP08_COVERAGE_CACHE_HOTFIX_BEGIN" in analyzer
    assert "GP08_COVERAGE_CACHE_HELPERS_BEGIN" in analyzer
    assert "PHP_SAPI !== 'cli'" in analyzer
    assert "gp08LoadCoverageCache" in analyzer
    assert "gp08TriggerCoverageRefresh" in analyzer
    assert "GP08_COVERAGE_CACHE_UI_BEGIN" in advanced
    for name in ("coverage-cache-refresh.php", "coverage-cache-status.php"):
        p = APP / "bin" / name
        assert p.is_file(), name
        assert p.stat().st_mode & 0o111, f"{name} must remain executable"


def test_lac_augment_bridge_hook_is_locked() -> None:
    installer = text(ROOT / "install-1.0.sh")
    bridge = ROOT / "payload/lac/gearcenter_bridge.lua"
    assert bridge.is_file()
    assert "gFunc.LoadFile('common/gearcenter_bridge.lua')" in installer
    assert "GEAR CENTER AUGMENT BRIDGE TICK BEGIN" in installer
    assert "GearCenterBridge.Tick()" in installer
    assert "gcinclude.CheckDefault()" in installer


def test_installer_stays_server_free() -> None:
    installer = text(ROOT / "install-1.0.sh")
    # These release-QA tests start HTTP services and must never be run by install/upgrade.
    assert 'tests/test_api_v1.py"' not in "\n".join(
        line for line in installer.splitlines()
        if not line.lstrip().startswith("r'") and not line.lstrip().startswith('r"')
    )
    assert 'tests/test_lifecycle.sh"' not in "\n".join(
        line for line in installer.splitlines()
        if not line.lstrip().startswith("r'") and not line.lstrip().startswith('r"')
    )
    assert '"$HERE/payload/scripts/gear-center-services.sh" prepare-install' in installer
    assert "prepare-install" in installer



def test_installer_runs_real_pdo_clone_gate_before_mutation() -> None:
    installer = text(ROOT / "install-1.0.sh")
    marker = 'php "$HERE/tests/test_php_pdo_e2e.php" "$PDO_QA_DB"'
    assert marker in installer
    assert 'PDO_QA_DB="$APP/data/gear-center.sqlite"' in installer
    assert 'schema-only' in installer
    # Existing installations still run the clone E2E before production mutation;
    # fresh public installs intentionally have no personal/rich seed data.
    assert installer.index(marker) < installer.index("MUTATION_STARTED=1", installer.index("===== 3/9"))
    e2e = text(ROOT / "tests/test_php_pdo_e2e.php")
    for required in ("VACUUM INTO", "GoalRepository", "TaskRepository", "RuntimePlanningOverlayExporter", "PRAGMA integrity_check"):
        assert required in e2e, required

def test_actual_center_is_lifecycle_owner() -> None:
    patcher = text(ROOT / "payload/center/patch_ffxi_center.py")
    compat = text(ROOT / "payload/scripts/start-ffxi-center-with-gear-center.sh")
    installer = text(ROOT / "install-1.0.sh")
    for marker in (
        "GEAR_CENTER_LIFECYCLE_OWNER_BEGIN",
        'f"ffxi-center-{os.getpid()}"',
        '"acquire", _GEAR_CENTER_LIFECYCLE_TOKEN, str(os.getpid())',
        '"release", _GEAR_CENTER_LIFECYCLE_TOKEN',
        "finally:",
    ):
        assert marker in patcher, marker
    assert "gear-center-services.sh acquire" not in compat
    assert "gear-center-services.sh release" not in compat
    assert 'exec python3 "$HOME/Games/FFXI/tools/FFXI-Center/ffxi_center.py"' in compat
    assert 'exec python3 "$HOME/Games/FFXI/tools/FFXI-Center/ffxi_center.py"' in installer


def test_service_worker_and_api_error_contract_is_locked() -> None:
    sw = text(APP / "public/sw.js")
    client = text(GP10 / "character-state.js")
    ui = text(GP10 / "embedded-panel.js")
    server = text(RUNTIME / "server.py")
    assert "const isApi=" in sw
    assert "if(isApi){e.respondWith(fetch(req,{cache:'no-store'}));return;}" in sw
    assert "GearCenterApiError" in client
    assert "application/json" in client
    assert "schema-error" in client
    assert "DATENFEHLER" in ui
    assert '"error": "data-source-failure"' in server



def test_cp932_snapshot_compatibility_is_locked() -> None:
    state = text(RUNTIME / "character_state.py")
    for marker in (
        "def _decode_ashita_cp932",
        'raw.decode("latin-1")',
        '.decode("cp932")',
        "neither valid UTF-8 nor valid Ashita CP932",
        "def enrich_item_names",
        "gear-center:item_definitions",
        '"not_authoritative_for": ["ownership", "counts", "locations", "augments"]',
    ):
        assert marker in state, marker
    server = text(RUNTIME / "server.py")
    assert "enrich_item_names(state, self.catalog_db_path)" in server
    # Missing snapshot and malformed snapshot must remain different states.
    assert "SnapshotUnavailableError" in state


def test_deep_link_initial_tab_is_set_before_custom_element_registration() -> None:
    app = text(GP10 / "app.js")
    assert "import './embedded-panel.js';" not in app
    set_marker = "el.setAttribute('initial-tab',tab)"
    import_marker = "await import('./embedded-panel.js')"
    assert set_marker in app and import_marker in app
    assert app.index(set_marker) < app.index(import_marker)


def test_center_hard_crash_watchdog_is_locked() -> None:
    service = text(ROOT / "payload/scripts/gear-center-services.sh")
    for marker in (
        "watch_owner(){",
        "start_owner_watchdog(){",
        "_watch-owner)",
        '[[ "$(proc_state "$pid")" != "Z" ]]',
        'start_owner_watchdog "$token" "$owner_pid" "$owner_starttime"',
        '"$SELF" release "$token"',
    ):
        assert marker in service, marker
    lifecycle = text(ROOT / "tests/test_lifecycle.sh")
    assert "SIGKILL / hard-crash" in lifecycle
    assert 'kill -9 "$CENTER_PID"' in lifecycle


def test_augment_v4_ranked_effect_catalog_is_locked() -> None:
    aug = text(RUNTIME / "augment_state.py")
    for marker in (
        "_KNOWN_RANKED_EFFECTS",
        '(21906, "A", 15)',
        '"DMG +8"',
        '"Blade: Metsu: Damage +20%"',
        '"Ninjutsu casting time -20%"',
        "_known_ranked_effects(item_id, path_value, rank)",
    ):
        assert marker in aug, marker


def test_farming_hardening_semantics_are_locked() -> None:
    farm = text(RUNTIME / "farming_planner.py")
    for marker in (
        "def _augment_signature",
        "def _resource_key",
        "def _signal_for_task",
        'row["goal_count"] = len(row["goals"])',
        'if edge not in graph_edges',
        '"global_owned_stock_is_subtracted_once_after_aggregation": True',
        '"augment_variants_have_distinct_resource_keys": True',
    ):
        assert marker in farm, marker


def test_gearlab_theory_context_hardening_is_locked_without_combat_capture() -> None:
    adapter = text(RUNTIME / "gearlab_adapter.py")
    for marker in (
        "def _signal_context",
        '"item_id": _first_scalar(obj, ("item_id", "itemid", "id"))',
        '"source_mtime": source_mtime',
        "refs_by_id",
        "signal_job",
        "signal_slot",
        '"latest_result_mtime"',
    ):
        assert marker in adapter, marker
    # Theory/performance context survives, retired Combat Capture does not.
    assert "def analyze_capture" not in adapter
    assert '"combat": analyze_capture' not in adapter


def test_source_health_capture_and_currency_hardening_is_locked() -> None:
    health = text(RUNTIME / "source_health.py")
    for marker in (
        "inventory_capture_consistent",
        "capture_physical_stacks",
        "capture_consistent",
        "currency_decoder_complete",
        '"decoder_complete": currency_decoder_complete',
        "points_overlay_valid",
    ):
        assert marker in health, marker

def test_live_tested_core_hashes_still_match() -> None:
    manifest = APP / "docs/LIVE-TESTED-CORE.sha256"
    override_file = APP / "docs/LIVE-TESTED-CORE-OVERRIDES.json"
    assert manifest.is_file() and override_file.is_file()
    cfg = json.loads(override_file.read_text(encoding="utf-8"))
    overrides = cfg.get("files") or {}
    baseline_rows = {}
    for raw in manifest.read_text(encoding="utf-8").splitlines():
        raw = raw.strip()
        if not raw or raw.startswith("#"):
            continue
        baseline, rel = raw.split(None, 1)
        rel = rel.strip().lstrip("*")
        baseline_rows[rel] = baseline
        path = APP / rel
        assert path.is_file(), rel
        override = overrides.get(rel)
        expected = str((override or {}).get("sha256") or baseline)
        if override:
            assert str(override.get("baseline_sha256") or "") == baseline
            assert str(override.get("reason") or "").strip()
        actual = hashlib.sha256(path.read_bytes()).hexdigest()
        assert actual == expected, f"live-tested core changed: {rel}"
    assert set(overrides).issubset(baseline_rows)


if __name__ == "__main__":
    tests = [
        test_english_items_hotfix_is_locked,
        test_augment_display_hotfix_is_locked,
        test_inventory_search_hotfix_v2_is_locked,
        test_live_ux_hotfix_capabilities_are_locked,
        test_coverage_hotfix_is_locked,
        test_lac_augment_bridge_hook_is_locked,
        test_installer_stays_server_free,
        test_installer_runs_real_pdo_clone_gate_before_mutation,
        test_actual_center_is_lifecycle_owner,
        test_service_worker_and_api_error_contract_is_locked,
        test_cp932_snapshot_compatibility_is_locked,
        test_deep_link_initial_tab_is_set_before_custom_element_registration,
        test_center_hard_crash_watchdog_is_locked,
        test_augment_v4_ranked_effect_catalog_is_locked,
        test_farming_hardening_semantics_are_locked,
        test_gearlab_theory_context_hardening_is_locked_without_combat_capture,
        test_source_health_capture_and_currency_hardening_is_locked,
        test_live_tested_core_hashes_still_match,
    ]
    for fn in tests:
        fn()
    print(f"PASS: historical/later hotfix contract ({len(tests)} groups)")
