from __future__ import annotations

import hashlib
import json
import re
import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "payload/app"
BRIDGE = ROOT / "payload/runtime/bridge"
SCRIPTS = ROOT / "payload/scripts"
SEED_DB = ROOT / "seed-data/gear-center.sqlite"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def test_live_tested_core_preserved() -> None:
    manifest = APP / "docs/LIVE-TESTED-CORE.sha256"
    override_file = APP / "docs/LIVE-TESTED-CORE-OVERRIDES.json"
    assert manifest.is_file()
    assert override_file.is_file()
    overrides = json.loads(override_file.read_text(encoding="utf-8"))
    assert overrides.get("schema") == "gearcenter.live-tested-core-overrides/1"
    override_rows = overrides.get("files") or {}
    allowed_overrides = {
        "src/AugmentBridgeImporter.php",
        "src/BgWikiExtractor.php",
        "src/Config.php",
        "src/LacImporter.php",
        "src/ProgressionChainService.php",
        "src/ResourceSnapshotImporter.php",
        "src/bootstrap.php",
    }
    assert set(override_rows) == allowed_overrides, f"unexpected live-core override set: {set(override_rows)}"
    rows = []
    untouched = 0
    for line in manifest.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        baseline_digest, rel = line.split(None, 1)
        rel = rel.strip()
        p = APP / rel
        assert p.is_file(), f"live-tested core file lost: {rel}"
        override = override_rows.get(rel)
        expected = str((override or {}).get("sha256") or baseline_digest)
        if override:
            assert str(override.get("reason") or "").strip(), f"override reason missing: {rel}"
            assert str(override.get("baseline_sha256") or "") == baseline_digest, f"baseline hash lost: {rel}"
        else:
            untouched += 1
        actual = sha256(p)
        assert actual == expected, f"live-tested core changed unexpectedly: {rel}"
        rows.append(rel)
    assert untouched >= 40
    assert len(rows) >= 40
    assert "src/ItemCatalog.php" in rows
    assert "src/ProgressionChainService.php" in rows
    assert "src/AcquisitionRouteService.php" in rows
    assert "src/GoalRepository.php" in rows
    assert "src/TaskRepository.php" in rows
    assert "sql/schema.sql" in rows


def test_historical_domain_capabilities_are_present() -> None:
    # These are the reusable production services built across 0.1-0.4. Cleanup may
    # remove obsolete wrappers/tests, but not these capabilities.
    required = [
        "GearFinderImporter.php", "LacImporter.php", "ItemCatalog.php",
        "BgWikiClient.php", "BgWikiExtractor.php", "BgWikiRepository.php",
        "BgWikiItemResolver.php", "CanonicalItemIdentityService.php",
        "AcquisitionRouteService.php", "ProgressionChainService.php",
        "ProgressionResourceService.php", "ProgressionSystemService.php",
        "LatestTierAudit.php", "CoverageAnalyzer.php", "CoverageCache.php",
        "SnapshotDiffer.php", "GoalRepository.php", "TaskRepository.php",
        "AllJobsTargetParser.php", "AllJobsTargetImporter.php",
        "TargetRepository.php", "TargetVerificationService.php",
        "TargetDetailReconciler.php", "TargetChainCompletenessService.php",
        "TargetRequirementsExporter.php", "ResourceSnapshotImporter.php",
    ]
    missing = [name for name in required if not (APP / "src" / name).is_file()]
    assert not missing, f"historical production capabilities missing: {missing}"

    advanced = (APP / "public/advanced.php").read_text(encoding="utf-8")
    required_markers = [
        "Latest-tier / post-2024 audit",
        "Snapshot-Änderungen",
        "Selectable / conditional options",
        "Bezugsweg als To-Do",
        "Progressionsplan als To-Do",
        "Materialien / Währungen",
        "strict identity, route, augment and freshness status",
        "chain_status",
    ]
    for marker in required_markers:
        assert marker.casefold() in advanced.casefold(), marker


def test_public_seed_is_sanitized() -> None:
    assert SEED_DB.is_file()
    assert not (ROOT / "seed-data/cache").exists(), "public package must not bundle cached third-party guide/wiki content"
    con = sqlite3.connect(SEED_DB)
    try:
        assert con.execute("select count(*) from characters").fetchone()[0] == 0
        assert con.execute("select count(*) from goals").fetchone()[0] == 0
        assert con.execute("select count(*) from snapshots").fetchone()[0] == 0
        assert con.execute("select count(*) from equipment_snapshot_items").fetchone()[0] == 0
        assert con.execute("select count(*) from lac_profile_snapshots").fetchone()[0] == 0
        assert con.execute("select count(*) from augment_bridge_snapshots").fetchone()[0] == 0
        assert con.execute("select count(*) from resource_inventory_snapshots").fetchone()[0] == 0
        assert con.execute("select count(*) from bgwiki_pages").fetchone()[0] == 0
        assert con.execute("select count(*) from bgwiki_progression_records").fetchone()[0] == 0
        assert con.execute("select count(*) from target_items").fetchone()[0] == 0
        assert con.execute("pragma integrity_check").fetchone()[0] == "ok"
    finally:
        con.close()


def test_single_normal_navigation_and_contextual_drilldowns() -> None:
    js = (APP / "public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    m = re.search(r"tabs\(\)\{const x=\{([^}]*)\}", js)
    assert m, "top-level tab map not found"
    labels = re.findall(r":'([^']+)'", m.group(1))
    assert labels == [
        "Übersicht", "Inventar", "Gearplanung", "Farmplan",
        "Gear-Vergleich", "Charakter", "Datenstatus",
    ], labels
    for retired in ("Combat", "Live-Test", "Kampfanalyse"):
        assert retired not in m.group(1)

    markers = [
        "Item-Katalog & BG-Wiki",
        "Meine LuAshitacast-Sets",
        "Zusätzliche Item-/Set-Ziele & Notizen",
        "Meine Aufgaben / To-Dos",
        "BG-Wiki, Bezugsweg, Progression & Materialien",
        "BG-Wiki-Details anzeigen",
        "Nach Bezugsweg / Inhalt gruppiert",
        "Ziel entfernen",
        "data-open-goal",
        "itemHref",
        "Inventaränderungen",
        "Schlüsselitems",
    ]
    for marker in markers:
        assert marker in js, marker

    # Physical instance rendering must not aggregate duplicate augmented copies.
    char_js = (APP / "public/assets/gp10/character-state.js").read_text(encoding="utf-8")
    assert "physicalInventoryRows" in char_js
    assert "container_id" in char_js and "slot" in char_js and "decoded_augments" in char_js


def test_advanced_historical_capabilities_reachable() -> None:
    php = (APP / "public/advanced.php").read_text(encoding="utf-8")
    markers = [
        # 0.3 logical catalog / item detail
        "Item-Katalog",
        "BG-Wiki: Bezugsweg & Progression",
        "Strukturierte Bezugs- / Progressionsdaten",
        # actionable acquisition + progression
        "Bezugswege",
        "Bezugsweg als To-Do anlegen",
        "Konkreter Progressionsplan",
        "Progressionsplan als To-Do anlegen",
        "Materialien / Währungen",
        # personal planning
        "goal_item",
        "goal_set",
        "goal_save",
        "goal_delete",
        "Ziel löschen",
        "task_add",
        "task_save",
        "task_step",
        # LAC and snapshots
        "Meine LuAshitacast-Sets",
        "Inventaränderungen",
        # technical target verification remains present but not normal nav
        "Coverage / QA",
    ]
    for marker in markers:
        assert marker in php, marker

    # Compact detail mode must offer one back-link, not a second main navigation.
    assert "← Zurück zum Gear Center" in php
    assert "Gear Center · Expertendiagnose" in php
    assert "Datenquellen" in php and "Coverage / QA" in php and "Snapshot-Änderungen" in php


def test_live_state_and_augment_contract() -> None:
    lua = (ROOT / "payload/ashita/GearplanerBridge/GearplanerBridge.lua").read_text(encoding="utf-8")
    for marker in (
        "for containerId = 0, 16 do",
        "HasKeyItem",
        "0x113",
        "0x118",
        "key_items",
        "currencies",
    ):
        assert marker in lua, marker

    aug = (BRIDGE / "augment_state.py").read_text(encoding="utf-8")
    for marker in ("augment_strings", "raw_extra_hex", "path", "rank", "trial"):
        assert marker in aug, marker
    # Guard against one-source-to-many-copies regression.
    assert "used: set[int]" in aug and "idx not in used" in aug and "used.add(chosen)" in aug

    server = (BRIDGE / "server.py").read_text(encoding="utf-8")
    assert '"/api/v1/key-items"' in server
    assert '"/api/v1/item-knowledge"' in server
    assert '"/api/v1/goals"' in server
    assert "_dashboard_revision" in server


def test_performance_is_read_only_and_capture_retired() -> None:
    adapter = (BRIDGE / "gearlab_adapter.py").read_text(encoding="utf-8").casefold()
    assert "sanitize" in adapter or "retired" in adapter
    # API routes themselves must not expose capture/combat.
    server = (BRIDGE / "server.py").read_text(encoding="utf-8").casefold()
    assert '"/api/v1/combat"' not in server
    assert '"/api/v1/live-test"' not in server
    ui = (APP / "public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8").casefold()
    assert "combat capture" not in ui
    assert "live-test" not in ui


def test_pwa_assets_and_web_root() -> None:
    sw = (APP / "public/sw.js").read_text(encoding="utf-8")
    for asset in ("/assets/gp10/app.js", "/assets/gp10/embedded-panel.js", "/assets/gp10/character-state.js"):
        assert asset in sw
    web = (SCRIPTS / "start-gear-center-web.sh").read_text(encoding="utf-8")
    api = (SCRIPTS / "start-gear-center-api.sh").read_text(encoding="utf-8")
    assert "-t public public/router.php" in web
    assert "GEAR_CENTER_LIFECYCLE" in web and "GEAR_CENTER_LIFECYCLE" in api


def test_center_lifecycle_contract() -> None:
    services = (SCRIPTS / "gear-center-services.sh").read_text(encoding="utf-8")
    wrapper = (SCRIPTS / "start-ffxi-center-with-gear-center.sh").read_text(encoding="utf-8")
    starter = (SCRIPTS / "open-gear-center.sh").read_text(encoding="utf-8")
    patcher = (ROOT / "payload/center/patch_ffxi_center.py").read_text(encoding="utf-8")
    assert "acquire" in services and "release" in services
    assert "owners" in services.lower()
    assert "live_owner_count" in services
    assert "proc_starttime" in services and "starttime=" in services and "owner_alive" in services
    assert "manual_start" in services and "_standalone-owner" in services and "standalone.owner.pid" in services
    assert "GEAR_CENTER_LIFECYCLE=1" in services
    # Wrapper is compatibility only; it must not own services.
    assert "gear-center-services.sh" not in wrapper and "$SERVICES" not in wrapper
    assert "exec python3" in wrapper and "FFXI-Center/ffxi_center.py" in wrapper
    # The actual Python Center process acquires/releases with its own PID.
    assert "GEAR_CENTER_LIFECYCLE_OWNER_BEGIN" in patcher
    assert "str(os.getpid())" in patcher
    assert '"acquire", _GEAR_CENTER_LIFECYCLE_TOKEN' in patcher
    assert '"release", _GEAR_CENTER_LIFECYCLE_TOKEN' in patcher
    assert "finally:" in patcher and "_gear_center_lifecycle_release()" in patcher
    # Open script never starts services itself; it accepts any validated lifecycle owner (standalone or optional Center).
    assert "xdg-open" in starter and "php -S" not in starter and "server.py" not in starter
    assert "owner_active" in starter and "starttime" in starter


def test_no_stale_release_test_copy_in_user_views() -> None:
    advanced = (APP / "public/advanced.php").read_text(encoding="utf-8").casefold()
    ui = (APP / "public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8").casefold()
    assert "consolidated ffxi test" not in advanced
    assert "konsolidierter live-test" not in advanced
    assert "live-test" not in ui


def main() -> None:
    tests = [v for k, v in globals().items() if k.startswith("test_") and callable(v)]
    for test in sorted(tests, key=lambda f: f.__name__):
        test()
    print(f"PASS: historical feature contract ({len(tests)} groups) + live-tested core + sanitized public seed")


if __name__ == "__main__":
    main()
