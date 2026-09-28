from __future__ import annotations

import json
import sqlite3
import sys
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "payload/runtime/bridge"
sys.path.insert(0, str(BRIDGE))

import augment_state
import character_state
import farming_planner
import resource_planner
import runtime_overlay


def test_valid_cp932_native_snapshot_is_loaded_not_hidden_by_legacy() -> None:
    with tempfile.TemporaryDirectory() as td:
        td = Path(td)
        native = td / "snapshot-v06.json"
        legacy = td / "snapshot-v05.json"
        native_obj = {
            "schema": "gearplaner.character-state/0.6",
            "character": {"name": "魔王"},
            "items": [], "containers": [], "equipped": [], "key_items": [],
            "currencies": {}, "progression": {},
        }
        # Reproduce the live-tested Ashita envelope: JSON punctuation is ASCII,
        # resource strings themselves contain CP932 bytes.
        native.write_bytes(json.dumps(native_obj, ensure_ascii=False, separators=(",", ":")).encode("cp932"))
        legacy.write_text(json.dumps({
            "schema": "gearplaner.character-state/0.5",
            "character": {"name": "LEGACY"},
            "items": [], "containers": [], "key_items": [], "currencies": {}, "progression": {},
        }), encoding="utf-8")
        state, source = character_state.load_best_snapshot(native, legacy)
        assert source == native
        assert state["character"]["name"] == "魔王"



def test_cp932_legacy_names_are_overlaid_with_english_catalog_metadata_only() -> None:
    with tempfile.TemporaryDirectory() as td:
        td = Path(td)
        db = td / "gear-center.sqlite"
        con = sqlite3.connect(db)
        try:
            con.execute("CREATE TABLE item_definitions(item_id INTEGER PRIMARY KEY,name TEXT)")
            con.execute("INSERT INTO item_definitions(item_id,name) VALUES(2956,?)", ("High Kindred's crest",))
            con.commit()
        finally:
            con.close()
        state = {
            "items": [{
                "item_id": 2956, "name": "魔王印章", "count": 7,
                "container_id": 8, "slot": 3, "extra_hex": "ab" * 24,
                "decoded_augments": ["Rank 15"],
            }],
            "sources": {},
        }
        out, status = character_state.enrich_item_names(state, db)
        row = out["items"][0]
        assert row["name"] == "High Kindred's crest"
        assert row["native_name"] == "魔王印章"
        assert row["count"] == 7 and row["container_id"] == 8 and row["slot"] == 3
        assert row["extra_hex"] == "ab" * 24 and row["decoded_augments"] == ["Rank 15"]
        assert status["mapped_unique_ids"] == 1 and status["missing_unique_ids"] == 0
        assert out["sources"]["item_name_catalog"]["not_authoritative_for"] == [
            "ownership", "counts", "locations", "augments"
        ]

def test_malformed_cp932_native_snapshot_is_error_not_legacy_or_prelive() -> None:
    with tempfile.TemporaryDirectory() as td:
        td = Path(td)
        native = td / "snapshot-v06.json"
        legacy = td / "snapshot-v05.json"
        # 0x81 is a CP932 lead byte with no trail byte inside this JSON string.
        native.write_bytes(
            b'{"schema":"gearplaner.character-state/0.6","character":{"name":"bad\x81"},'
            b'"items":[],"containers":[],"equipped":[],"key_items":[],"currencies":{},"progression":{}}'
        )
        legacy.write_text(json.dumps({
            "schema": "gearplaner.character-state/0.5",
            "character": {"name": "LEGACY"},
            "items": [], "containers": [], "key_items": [], "currencies": {}, "progression": {},
        }), encoding="utf-8")
        try:
            character_state.load_best_snapshot(native, legacy)
        except character_state.SnapshotError as exc:
            message = str(exc)
            assert "neither valid UTF-8 nor valid Ashita CP932" in message
        else:
            raise AssertionError("present but malformed native snapshot must not fall back to legacy")


def test_missing_native_can_use_valid_legacy_and_both_missing_are_prelive() -> None:
    with tempfile.TemporaryDirectory() as td:
        td = Path(td)
        native = td / "snapshot-v06.json"
        legacy = td / "snapshot-v05.json"
        legacy.write_text(json.dumps({
            "schema": "gearplaner.character-state/0.5",
            "items": [], "containers": [], "key_items": [], "currencies": {}, "progression": {},
        }), encoding="utf-8")
        state, source = character_state.load_best_snapshot(native, legacy)
        assert source == legacy and state["schema"] == "gearplaner.character-state/0.6"
        legacy.unlink()
        try:
            character_state.load_best_snapshot(native, legacy)
        except character_state.SnapshotUnavailableError as exc:
            assert "Snapshot not found" in str(exc)
        else:
            raise AssertionError("both missing snapshots must be a PRE-LIVE/unavailable state")


def test_consumable_stock_is_subtracted_once() -> None:
    plan = {
        "personal": [
            {"id": "a", "title": "A", "priority": 1, "requirements": [{
                "kind": "currency", "key": "gil", "label": "Gil", "quantity": 100,
                "needed": 100, "owned": 100, "missing": 0, "known": True,
                "resource_key": "currency:gil",
            }]},
            {"id": "b", "title": "B", "priority": 1, "requirements": [{
                "kind": "currency", "key": "gil", "label": "Gil", "quantity": 100,
                "needed": 100, "owned": 100, "missing": 0, "known": True,
                "resource_key": "currency:gil",
            }]},
        ]
    }
    farm = farming_planner.build_farming_plan(plan)
    row = next(x for x in farm["resources"] if x["resource_key"] == "currency:gil")
    assert row["required"] == 200
    assert row["owned"] == 100
    assert row["missing"] == 100
    assert row["status"] == "farm"


def test_augment_sign_and_value_are_not_erased() -> None:
    assert resource_planner._augment_row_matches(
        {"details": ["DEX+20"]}, {"decoded_augments": ["DEX+20"]}
    ) is True
    assert resource_planner._augment_row_matches(
        {"details": ["DEX+20"]}, {"decoded_augments": ["DEX-20"]}
    ) is False
    assert resource_planner._augment_row_matches(
        {"details": ["Damage taken-3%"]}, {"decoded_augments": ["Damage taken+3%"]}
    ) is False


def test_distinct_augment_targets_have_distinct_resource_keys() -> None:
    resources = {
        "items": {"26258": {"item_id": 26258, "name": "Andartia's Mantle", "count": 0, "locations": []}},
        "items_by_name": {}, "currencies": {},
        "availability": {"inventory": True, "currencies": True, "augments": "complete"},
    }
    base = {"kind": "item", "item_id": 26258, "item_name": "Andartia's Mantle", "quantity": 1, "augment_required": True}
    a = resource_planner._evaluate_requirement({**base, "details": ["DEX+20", "Double Attack+10"]}, resources)
    b = resource_planner._evaluate_requirement({**base, "details": ["DEX+20", "Weapon skill damage +10%"]}, resources)
    a2 = resource_planner._evaluate_requirement({**base, "details": ["Double Attack+10", "DEX+20"]}, resources)
    assert a["resource_key"] != b["resource_key"]
    assert a["resource_key"] == a2["resource_key"]
    assert "#aug:" in a["resource_key"]


def test_overlay_rejects_character_and_complete_extra_conflicts() -> None:
    item_extra = "aa" * 24
    other_extra = "bb" * 24
    state = {
        "character": {"name": "ExamplePlayer"}, "exported_at_unix": 1000,
        "items": [{"item_id": 26258, "name": "Andartia's Mantle", "count": 1,
                   "container_id": 8, "slot": 1, "extra_hex": item_extra}],
    }
    wrong_char = {
        "character": {"name": "Other"}, "exported_at_unix": 1000,
        "rows": [{"source_index": 0, "item_id": 26258, "name": "Andartia's Mantle",
                  "container_id": 8, "slot": 1, "extra_hex": item_extra, "augments": ["DEX+20"]}],
        "coverage_complete": True,
    }
    over, status = augment_state.apply_augment_overlay(state, wrong_char)
    assert status["identity_reason"] == "character-mismatch"
    assert not over["items"][0].get("decoded_augments")

    wrong_extra = {**wrong_char, "character": {"name": "ExamplePlayer"}}
    wrong_extra["rows"] = [{**wrong_extra["rows"][0], "extra_hex": other_extra}]
    over2, status2 = augment_state.apply_augment_overlay(state, wrong_extra)
    assert status2["matched_rows"] == 0
    assert status2["identity_conflicts"] >= 1
    assert not over2["items"][0].get("decoded_augments")


def test_short_extra_is_not_claimed_as_full_identity_but_exact_location_can_match() -> None:
    state = {
        "character": {"name": "ExamplePlayer"}, "exported_at_unix": 1000,
        "items": [{"item_id": 21906, "name": "Kikoku", "count": 1,
                   "container_id": 13, "slot": 45, "extra_hex": "aa"}],
    }
    source = {
        "character": {"name": "ExamplePlayer"}, "exported_at_unix": 1000,
        "rows": [{"source_index": 0, "item_id": 21906, "name": "Kikoku",
                  "container_id": 13, "slot": 45, "extra_hex": "bbcc", "augments": ["Path A", "Rank 15"]}],
        "coverage_complete": False,
    }
    assert augment_state._complete_extra("aa") is None
    assert augment_state._complete_extra("bbcc") is None
    over, status = augment_state.apply_augment_overlay(state, source)
    assert status["matched_rows"] == 1
    assert over["items"][0]["decoded_augments"] == ["Path A", "Rank 15"]


def test_collector_uses_proven_english_resource_index() -> None:
    lua = (ROOT / "payload/ashita/GearplanerBridge/GearplanerBridge.lua").read_text(encoding="utf-8")
    assert "r.Name[1] or r.Name[0] or r.Name[2]" in lua
    assert "r.LogNameSingular[1] or r.LogNameSingular[0] or r.LogNameSingular[2]" in lua


def test_frontend_and_service_worker_do_not_mask_source_errors() -> None:
    api = (ROOT / "payload/app/public/assets/gp10/character-state.js").read_text(encoding="utf-8")
    ui = (ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    sw = (ROOT / "payload/app/public/sw.js").read_text(encoding="utf-8")
    assert "GearCenterApiError" in api
    assert "erwartete JSON-Antwort" in api
    assert "data-source-failure" in (ROOT / "payload/runtime/bridge/server.py").read_text(encoding="utf-8")
    assert "DATENFEHLER" in ui and "letzte erfolgreich geladene" in ui
    assert "url.port==='8765'" in sw or "url.port === '8765'" in sw
    assert "fetch(req,{cache:'no-store'})" in sw or "fetch(req" in sw
    # The shell fallback is allowed only for navigation, never for static/API.
    assert sw.count("caches.match('/')") == 1
    assert "if(req.mode==='navigate')" in sw
    nav_pos = sw.index("if(req.mode==='navigate')")
    shell_pos = sw.index("caches.match('/')")
    assert shell_pos > nav_pos
    assert "Response.error()" in sw[shell_pos:]



def test_runtime_overlay_sqlite_goal_enters_normal_plan() -> None:
    requirements = {"schema": resource_planner.REQUIREMENTS_SCHEMA, "tasks": []}
    goals = {"schema": resource_planner.GOALS_SCHEMA, "goals": []}
    overlay = {
        "schema": runtime_overlay.OVERLAY_SCHEMA,
        "requirements": {"schema": resource_planner.REQUIREMENTS_SCHEMA, "tasks": [{
            "id": "sqlite-goal:1", "title": "Example Consumable Set", "category": "Personal LAC set goal",
            "job": "COMMON", "source_meta": {"goal_origin": "sqlite", "manage_url": "/advanced.php?view=goals&compact=1&return=plan"},
            "requirements": [{"kind": "item", "item_id": 1, "item_name": "Example Consumable", "quantity": 1, "slots": ["item"]}],
        }]},
        "goals": {"schema": resource_planner.GOALS_SCHEMA, "goals": [{"task_id": "sqlite-goal:1", "enabled": True, "priority": 2.0, "origin": "sqlite"}]},
        "progression_todos": [],
    }
    req2, goals2 = runtime_overlay.merge_planning_catalogs(requirements, goals, overlay)
    state = {"items": [], "currencies": {}, "_availability": {"inventory": True, "currencies": True, "augments": False}}
    plan = resource_planner.build_plan(state, req2, goals2)
    row = next(x for x in plan["personal"] if x["id"] == "sqlite-goal:1")
    assert row["personal_goal"] is True
    assert row["source_meta"]["goal_origin"] == "sqlite"
    assert row["requirements"][0]["item_name"] == "Example Consumable"


def test_progression_todo_fixed_materials_sum_and_stock_subtracts_once() -> None:
    base = farming_planner.build_farming_plan({"personal": []})
    overlay = {
        "progression_todos": [
            {"id": "sqlite-todo:1", "title": "Path one", "status": "open", "priority": 2,
             "item_name": "Target A", "source_title": "BG-Wiki path",
             "requirements": [{"name": "Pluton", "kind": "item_or_resource", "quantity_known": True, "required_quantity": 100}],
             "alternative_groups": [], "systems": []},
            {"id": "sqlite-todo:2", "title": "Path two", "status": "doing", "priority": 2,
             "item_name": "Target B", "source_title": "BG-Wiki path",
             "requirements": [{"name": "Pluton", "kind": "item_or_resource", "quantity_known": True, "required_quantity": 100}],
             "alternative_groups": [], "systems": []},
        ]
    }
    state = {"items": [{"item_id": 1, "name": "Pluton", "count": 100, "container_id": 0, "slot": 1}],
             "currencies": {}, "_availability": {"inventory": True, "currencies": True}}
    farm = runtime_overlay.merge_progression_todos_into_farming(base, overlay, state)
    row = next(x for x in farm["resources"] if x["resource_key"] == "progression:pluton")
    assert row["required"] == 200
    assert row["owned"] == 100
    assert row["missing"] == 100
    assert row["status"] == "farm"
    assert row["goal_count"] == 2


def test_progression_any_of_stays_choice_not_sum() -> None:
    base = farming_planner.build_farming_plan({"personal": []})
    overlay = {"progression_todos": [{
        "id": "sqlite-todo:7", "title": "Augment path", "status": "open", "priority": 3,
        "item_name": "Andartia's Mantle", "source_title": "JSE Cape",
        "requirements": [], "systems": [],
        "alternative_groups": [{"key": "choice-1", "label": "One seal", "raw": "ANY-OF", "choices": [
            {"name": "Abdhaljs Seal", "kind": "item_or_resource", "required_quantity": 1, "quantity_known": True},
            {"name": "Alternative Seal", "kind": "item_or_resource", "required_quantity": 1, "quantity_known": True},
        ]}],
    }]}
    state = {"items": [], "currencies": {}, "_availability": {"inventory": True, "currencies": True}}
    farm = runtime_overlay.merge_progression_todos_into_farming(base, overlay, state)
    row = next(x for x in farm["resources"] if x["kind"] == "alternative_group")
    assert row["required"] is None and row["missing"] is None and row["known"] is False
    assert row["aggregation_rule"] == "any_of_not_summed"
    assert len(row["alternative_choices"]) == 2


def test_status_and_goal_mutation_use_merged_goal_world() -> None:
    server = (ROOT / "payload/runtime/bridge/server.py").read_text(encoding="utf-8")
    status_start = server.index('if path in {"/api/v1/prelive", "/api/v1/status"}:')
    status_end = server.index('if path == "/api/v1/dashboard":', status_start)
    status_block = server[status_start:status_end]
    assert "self._load_plan(state)" in status_block
    assert '"runtime_overlay": plan.get("runtime_overlay") or {}' in status_block
    assert 'task_id.startswith("sqlite-goal:")' in server
    assert "SQLite goals are managed by the Gear Center goal repository" in server


def test_progression_todo_ui_has_no_dead_ends() -> None:
    ui = (ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    # SQLite progression To-Dos are not normal JSON/LAC plan task IDs. They must
    # open the existing task manager instead of feeding openGoal(), which cannot
    # resolve sqlite-todo:* and previously resulted in a silent click.
    assert "function goalRefLink" in ui
    assert "origin==='sqlite_todo'||id.startsWith('sqlite-todo:')" in ui
    assert 'href="${esc(g.manage_url)}"' in ui
    assert "map(g=>goalRefLink(g,'farming'))" in ui
    assert "map(g=>goalRefLink(g,'character'))" in ui

    # ANY-OF/system-choice rows are not items themselves. Group cards must show
    # their actual alternatives, not create an item link for the group label.
    assert "if(kind==='alternative_group')" in ui
    assert "${esc(label)} · Auswahl" in ui
    assert "r.alternative_choices||[]" in ui

    # The farm plan explicitly reports both backed goal sources.
    assert "persönlichen Ziele und ausdrücklich angelegten Progressions-To-Dos" in ui
    assert "Progressions-To-Dos" in ui and "c.progression_todos" in ui


def test_overlay_is_wired_into_api_and_center_owned_startup() -> None:
    server = (ROOT / "payload/runtime/bridge/server.py").read_text(encoding="utf-8")
    api_start = (ROOT / "payload/scripts/start-gear-center-api.sh").read_text(encoding="utf-8")
    web_start = (ROOT / "payload/scripts/start-gear-center-web.sh").read_text(encoding="utf-8")
    ui = (ROOT / "payload/app/public/assets/gp10/embedded-panel.js").read_text(encoding="utf-8")
    assert "merge_planning_catalogs" in server and "merge_progression_todos_into_farming" in server
    assert '--runtime-overlay' in server and 'Handler.runtime_overlay_path' in server
    assert 'export-runtime-planning-overlay.php' in api_start and '--runtime-overlay "$OVERLAY"' in api_start
    assert 'BASE="$HOME/Games/FFXI"' in web_start and 'GEARCENTER_RUNTIME_OVERLAY' in web_start
    assert "goal_origin==='sqlite'" in ui and "Ziel verwalten" in ui
    assert "kind==='alternative_group'" in ui


def test_physical_copy_context_survives_advanced_inventory_and_progression_todo() -> None:
    advanced = (ROOT / "payload/app/public/advanced.php").read_text(encoding="utf-8")
    overlay = (ROOT / "payload/app/src/RuntimePlanningOverlayExporter.php").read_text(encoding="utf-8")

    # Advanced inventory must drill into the exact owned copy, not only an item name.
    assert "function physicalItemUrl" in advanced
    assert "href=\"<?=h(physicalItemUrl($r))?>\"" in advanced
    assert "instance_container" in advanced and "instance_slot" in advanced and "instance_extra" in advanced

    # Creating an augment/rank To-Do with duplicates must either bind the selected
    # copy or refuse to guess.  The selected identity is persisted in route fields.
    assert "Multiple live copies of this item exist" in advanced
    assert "selected_instance" in advanced
    assert "The selected physical item no longer matches" in advanced

    # Runtime material/progression evaluation must reuse the persisted physical copy
    # and must not silently swap to a different duplicate if it becomes stale.
    assert "liveAugmentForTask" in overlay
    assert "AMBIGUOUS_MULTIPLE_COPIES" in overlay
    assert "EXTRA_CONFLICT" in overlay
    assert "selected_physical_instance" in overlay

def run() -> None:
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    for test in tests:
        test()
        print(f"PASS: {test.__name__}")
    print(f"PASS: rules regression suite ({len(tests)} groups)")


if __name__ == "__main__":
    run()
