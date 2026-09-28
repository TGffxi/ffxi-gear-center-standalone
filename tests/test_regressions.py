from __future__ import annotations

import importlib.util
import json
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "payload/runtime/bridge"
sys.path.insert(0, str(BRIDGE))


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    mod = importlib.util.module_from_spec(spec)
    assert spec and spec.loader
    spec.loader.exec_module(mod)
    return mod


augment = load_module("augment_state_test", BRIDGE / "augment_state.py")
server = load_module("server_test", BRIDGE / "server.py")
gearlab = load_module("gearlab_adapter_test", BRIDGE / "gearlab_adapter.py")
bgknowledge = load_module("bgwiki_knowledge_test", BRIDGE / "bgwiki_knowledge.py")
character_state = load_module("character_state_test", BRIDGE / "character_state.py")


def test_actual_lua_augment_format() -> None:
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "gearcenter_augments.json"
        p.write_text(json.dumps({
            "schema": "gearcenter.augments/test",
            "items": [{
                "item_id": 21906,
                "item_name": "Kikoku",
                "bag_id": 13,
                "bag_slot": 45,
                "raw_extra_hex": "AA BB CC",
                "augment_strings": ["DMG:+8", "Blade: Metsu: Damage +20%", "Ninjutsu casting time -20%"],
                "path": "A",
                "rank": 15,
            }],
        }), encoding="utf-8")
        data = augment.load_augment_state(p)
        assert data["parsed_row_count"] == 1
        row = data["rows"][0]
        assert row["container_id"] == 13 and row["slot"] == 45
        assert row["extra_hex"] == "aabbcc"
        assert "DMG:+8" in row["augments"]
        assert "Path A" in row["augments"]
        assert "Rank 15" in row["augments"]


def test_one_to_one_duplicate_matching() -> None:
    state = {
        "items": [
            {"item_id": 26258, "name": "Andartia's Mantle", "count": 1, "container_id": 8, "container_name": "Wardrobe", "slot": 1, "extra_hex": "1111"},
            {"item_id": 26258, "name": "Andartia's Mantle", "count": 1, "container_id": 8, "container_name": "Wardrobe", "slot": 2, "extra_hex": "2222"},
        ]
    }
    source = {
        "rows": [{"source_index": 0, "item_id": 26258, "container_id": 8, "slot": 1, "extra_hex": "1111", "augments": ["DEX+20"]}],
        "coverage_complete": False,
    }
    over, status = augment.apply_augment_overlay(state, source)
    assert status["matched_rows"] == 1
    assert over["items"][0].get("decoded_augments") == ["DEX+20"]
    assert not over["items"][1].get("decoded_augments")

    # Location-less single source must NEVER be spread across two physical copies.
    source_ambiguous = {"rows": [{"source_index": 0, "item_id": 26258, "augments": ["STR+20"]}]}
    over2, status2 = augment.apply_augment_overlay(state, source_ambiguous)
    assert status2["matched_rows"] == 0
    assert not over2["items"][0].get("decoded_augments")
    assert not over2["items"][1].get("decoded_augments")


def test_revision_changes_on_augment_only() -> None:
    base = {
        "exported_at_unix": 123,
        "items": [{"item_id": 1, "container_id": 8, "slot": 2, "count": 1, "extra_hex": "aa", "decoded_augments": ["DEX+10"]}],
        "currencies": {}, "progression": {}, "events": {}, "points_overlay": {},
    }
    changed = json.loads(json.dumps(base))
    changed["items"][0]["decoded_augments"] = ["DEX+11"]
    r1 = server.Handler._dashboard_revision(base, {"personal": []}, {}, {"counts": {}}, {})
    r2 = server.Handler._dashboard_revision(changed, {"personal": []}, {}, {"counts": {}}, {})
    assert r1 != r2


def contains_retired_capture(obj) -> bool:
    text = json.dumps(obj, ensure_ascii=False).casefold().replace("-", "_")
    return any(x in text for x in ("gearlab_capture", "combat_capture.py", "structured_combat_capture", "capture_dir", "capture_addon", "combat_module"))


def test_gearlab_capture_metadata_is_filtered() -> None:
    with tempfile.TemporaryDirectory() as td:
        root = Path(td) / "FFXI-Gear-Lab"
        root.mkdir()
        (root / "engine.py").write_text("# test\n", encoding="utf-8")
        (root / "RELEASE.json").write_text(json.dumps({
            "version": "1.0",
            "files": {"engine.py": {}, "combat_capture.py": {}, "gearlab_capture/gearlab_capture.lua": {}},
            "validation": {"capture_packet_layout_v2_matches_established_0x028_split": True},
            "features": {"structured_combat_capture": True},
            "combat_module": str(root / "combat_capture.py"),
        }), encoding="utf-8")
        detected = gearlab.detect_gearlab(root, Path(td) / "python")
        assert not contains_retired_capture(detected)

        cache = Path(td) / "cache.json"
        cache.write_text(json.dumps({
            "schema": "gearcenter.performance-cache/1.0",
            "release": "1.0.0",
            "gearlab": {"features": {"structured_combat_capture": True}, "capture_dir": "/tmp/gearlab_capture"},
            "diagnostics": {}, "results": {}, "combat": {"old": True},
        }), encoding="utf-8")
        loaded = gearlab.load_performance_cache(cache, root, Path(td) / "python")
        assert not contains_retired_capture(loaded)
        assert "combat" not in loaded



def test_bgwiki_cache_is_user_visible_in_farming() -> None:
    # Public distribution ships no third-party cache. Build a tiny synthetic
    # cache in a disposable DB so the enrichment behavior remains covered.
    import shutil, sqlite3, tempfile
    with tempfile.TemporaryDirectory() as td:
        db = Path(td) / "gear-center.sqlite"
        shutil.copy2(ROOT / "seed-data/gear-center.sqlite", db)
        con = sqlite3.connect(db)
        try:
            now = "2099-01-01T00:00:00Z"
            con.execute(
                "INSERT INTO bgwiki_pages(page_title,canonical_url,revision_id,revision_timestamp,fetched_at,exists_flag,sections_json,raw_excerpt,error_message) VALUES(?,?,?,?,?,1,'{}',?,NULL)",
                ("Example Relic", "https://www.bg-wiki.com/ffxi/Example_Relic", 1, now, now, "Obtained From Dealer Moogle"),
            )
            con.execute(
                "INSERT INTO bgwiki_item_map(item_name_norm,item_name,page_title,confidence,updated_at) VALUES(?,?,?,?,?)",
                ("example relic", "Example Relic", "Example Relic", "exact_title", now),
            )
            con.execute(
                "INSERT INTO bgwiki_progression_records(page_title,section_name,record_index,source_type,fields_json,created_at) VALUES(?,?,?,?,?,?)",
                ("Example Relic", "Obtained From", 0, "table", json.dumps({"NPC Name":"Dealer Moogle","Zone":"Example Zone"}), now),
            )
            con.commit()
        finally:
            con.close()

        info = bgknowledge.lookup_item(db, "Example Relic")
        assert info["cached"] is True
        assert info["canonical_url"].startswith("https://www.bg-wiki.com/")
        assert "Dealer Moogle" in info["acquisition_hint"]

        farming = {
            "resources": [{
                "resource_key": "item-name:example relic", "kind": "item", "label": "Example Relic",
                "activities": ["Unresolved acquisition"], "activity_hints": [],
            }],
            "queue": [{"resource_key": "item-name:example relic"}],
            "activities": [],
        }
        out = bgknowledge.enrich_farming(db, farming)
        row = out["resources"][0]
        assert row["bgwiki"]["cached"] is True
        assert "Dealer Moogle" in row["activities"][0]
        assert out["queue"][0]["bgwiki"]["page_title"] == "Example Relic"
        assert out["activities"]
        assert "Dealer Moogle" in out["activities"][0]["activity"]
        assert out["activities"][0]["resource_count"] == 1


def test_main_ui_restores_drilldowns_and_goal_removal() -> None:
    js = (ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    assert "Ziel entfernen" in js
    assert "BG-Wiki, Bezugsweg, Progression & Materialien" in js
    assert "BG-Wiki-Details anzeigen" in js
    assert "Nach Bezugsweg / Inhalt gruppiert" in js
    assert "Kompakte Routenübersicht" in js
    assert "routeActivityView" in js
    assert 'class="route-list"' in js
    assert "Vollständiger Bezugsweg:" in js
    assert "g.variant_name" in js
    assert "Meine Aufgaben / To-Dos" in js
    assert "Meine LuAshitacast-Sets" in js
    assert "Zusätzliche Item-/Set-Ziele & Notizen" in js
    assert "itemHref" in js
    assert "openGoal(id)" in js
    assert "this.planStatus='GOAL'" in js and "this.setTab('plan')" in js
    advanced = (ROOT / "payload/app/public/advanced.php").read_text(encoding="utf-8")
    assert "BG-Wiki: Bezugsweg & Progression" in advanced
    assert "Bezugswege" in advanced
    assert "Materialien / Währungen" in advanced
    assert "goal_delete" in advanced
    assert "Ziel löschen" in advanced
    assert "function advancedUrl" in advanced
    assert "Meine Ziele & Notizen" in advanced

def test_key_items_preserved_and_revisioned() -> None:
    fixture = json.loads((ROOT / "tests/fixtures/sample-snapshot-v06.json").read_text(encoding="utf-8"))
    state = character_state.normalize_snapshot(fixture)
    character_state.validate_snapshot(state)
    assert len(state["key_items"]) == 2
    assert state["key_items"][0]["name"] == "Test Key Item"
    assert state["key_items"][1]["key_item_id"] == 314 and "name" not in state["key_items"][1]
    base = {"exported_at_unix": 1, "items": [], "key_items": [{"key_item_id": 12, "owned": True}], "currencies": {}, "progression": {}, "events": {}, "points_overlay": {}}
    changed = json.loads(json.dumps(base))
    changed["key_items"].append({"key_item_id": 314, "owned": True})
    r1 = server.Handler._dashboard_revision(base, {"personal": []}, {}, {"counts": {}}, {})
    r2 = server.Handler._dashboard_revision(changed, {"personal": []}, {}, {"counts": {}}, {})
    assert r1 != r2


def test_key_items_ui_is_contextual_not_new_nav() -> None:
    js=(ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    assert "Schlüsselitems" in js
    assert "keyItemsTable" in js
    assert "Schlüsselitem #" in js
    # Key Items live inside Charakter; they must not create an eighth top-level tab.
    assert "keyitems:'" not in js and "key_items:'" not in js


def test_no_dead_end_user_flow_contract() -> None:
    js = (ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    # Overview metrics must navigate, not just display numbers.
    for marker in ('data-tab="inventory"', 'data-tab="character"', 'data-tab="plan"', 'data-tab="farming"'):
        assert marker in js, marker
    # Empty/waiting states always provide a next action.
    assert 'Datenstatus ansehen' in js
    assert 'Datenstatus prüfen' in js
    assert 'Gearplanung öffnen' in js
    # All major entity surfaces connect onward.
    assert 'BG-Wiki, Bezugsweg, Progression & Materialien' in js
    assert 'data-open-goal' in js
    assert "v.match(/^item:(\\d+)$/i)" in js
    assert 'Bezug zu deiner Planung' in js
    assert 'Auf BG-Wiki nachschlagen' in js
    assert 'drawCurrencies(){const b=' in js and 'this.bindGoals(b)' in js
    # Dynamic/filtered subviews keep their newly rendered action handlers.
    assert 'bindContext(root)' in js
    assert 'this.bindContext(b);this.bindGoals(b)' in js
    # Filter/search empty states are actionable instead of terminal.
    for marker in (
        'Erneut versuchen', 'Im Item-Katalog weitersuchen', 'Filter zurücksetzen',
        'data-plan-reset', 'data-currency-reset', 'data-open-currency', 'data-open-set',
        'Alles für die ausgewählten Ziele ist aktuell bereit',
        'Passende Sets in Gearplanung suchen', 'Name/Quelle im Datenstatus prüfen',
    ):
        assert marker in js, marker

    advanced = (ROOT / "payload/app/public/advanced.php").read_text(encoding="utf-8")
    # Item details lead to LAC, goals, tasks, routes, progression, materials and BG-Wiki.
    for marker in (
        'Verwendung in LuAshitacast', 'Ziele & Notizen', 'Aufgaben / To-Dos',
        'Bezugswege', 'Upgrade- / Augment-Verlauf', 'Materialien / Währungen',
        'BG-Wiki: Bezugsweg & Progression', 'Aufgabe öffnen',
    ):
        assert marker in advanced, marker
    # Even an unresolved item offers useful exits instead of a blank terminal page.
    assert 'Noch nicht im lokalen Item-Katalog' in advanced
    assert 'Auf BG-Wiki suchen' in advanced
    assert 'Als eigenes Item-Ziel speichern' in advanced
    # Item-related goal/task rows are navigable back to their managers.
    assert "advancedUrl('goals')" in advanced
    assert "advancedUrl('tasks')" in advanced
    # Technical/context views also let the user recover from empty filters/data.
    assert 'Keine Items passen zu diesen Filtern.' in advanced and "advancedUrl('items')" in advanced
    assert 'Keine Guide-Ziele passen zu diesen Filtern.' in advanced and "advancedUrl('targets')" in advanced
    assert 'Keine Items passen zu diesem Coverage-Filter.' in advanced and "advancedUrl('coverage')" in advanced
    assert 'Noch kein GearFinder-Snapshot importiert.' in advanced and '/?tab=diagnostics' in advanced


def test_rc3_live_blockers_are_locked() -> None:
    progression = (ROOT / "payload/app/src/ProgressionChainService.php").read_text(encoding="utf-8")
    assert "Aug/Item" in progression and "Max Aug" in progression
    assert "embeddedHeaders" in progression

    extractor = (ROOT / "payload/app/src/BgWikiExtractor.php").read_text(encoding="utf-8")
    assert "loadHtmlUtf8" in extractor
    assert "repairLegacyMojibakeString" in extractor
    assert "<?xml encoding=\"UTF-8\">" in extractor

    advanced = (ROOT / "payload/app/public/advanced.php").read_text(encoding="utf-8")
    assert "LiveStateSynchronizer" in advanced
    assert "Live-Sync" in advanced
    # Normal compact item details must not dump the entire JSE-cape Aug/Item /
    # Max Aug matrix as hundreds of cards. The same classifier used by the
    # progression service hides reference-only rows while keeping the real
    # augment transaction and acquisition routes visible.
    assert "isAugmentReferenceFields" in advanced
    assert "technische Augment-Statzeilen sind in der normalen Ansicht ausgeblendet" in advanced
    assert "BG-Wiki-Tabelle direkt öffnen" in advanced
    start_api = (ROOT / "payload/scripts/start-gear-center-api.sh").read_text(encoding="utf-8")
    assert "sync-live-sqlite.php" in start_api
    assert "last-known-good" in start_api

    aug_importer = (ROOT / "payload/app/src/AugmentBridgeImporter.php").read_text(encoding="utf-8")
    res_importer = (ROOT / "payload/app/src/ResourceSnapshotImporter.php").read_text(encoding="utf-8")
    assert "snapshot is empty; preserving last-known-good" in aug_importer
    assert "zero physical items; preserving last-known-good" in res_importer
    assert "partial while SQLite has a complete last-known-good" in res_importer

    installer = (ROOT / "install-1.0.sh").read_text(encoding="utf-8")
    assert "fix-bgwiki-encoding.php" in installer
    assert "test_live_sqlite_lkg.php" in installer

    # The shipped seed must already be clean; upgrades additionally run the same
    # strict transactional repair against the preserved user DB after backup.
    import sqlite3
    con = sqlite3.connect(ROOT / "seed-data/gear-center.sqlite")
    try:
        bad = con.execute(
            "SELECT COUNT(*) FROM bgwiki_progression_records "
            "WHERE fields_json LIKE '%â€%' OR fields_json LIKE '%Ã%' OR fields_json LIKE '%Â%'"
        ).fetchone()[0]
        assert bad == 0, f"seed structured BG-Wiki mojibake rows remain: {bad}"
    finally:
        con.close()

def main() -> None:
    tests = [v for k, v in globals().items() if k.startswith("test_") and callable(v)]
    for test in sorted(tests, key=lambda f: f.__name__):
        test()
    print(f"PASS: regression suite ({len(tests)} groups): augments/duplicates/revision/BG-Wiki/drilldowns/goals/key-items/no-dead-ends/capture retirement")


if __name__ == "__main__":
    main()
