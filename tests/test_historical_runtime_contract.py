from __future__ import annotations

import json
import sys
import tempfile
import time
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BRIDGE = ROOT / "payload/runtime/bridge"
sys.path.insert(0, str(BRIDGE))

import augment_state
import farming_planner
import gearlab_adapter
import source_health
import resource_planner


def test_augment_v4_kikoku_ranked_effects_survive() -> None:
    with tempfile.TemporaryDirectory() as td:
        p = Path(td) / "augments.json"
        p.write_text(json.dumps({
            "schema": "gearcenter.augments/test",
            "character": {"name": "ExamplePlayer"},
            "items": [{
                "item_id": 21906,
                "name": "Kikoku",
                "container_id": 13,
                "slot": 45,
                "path": "A",
                "rank": 15,
                "augment_strings": [],
            }],
        }), encoding="utf-8")
        loaded = augment_state.load_augment_state(p)
        assert loaded["parsed_row_count"] == 1
        augs = loaded["rows"][0]["augments"]
        for expected in (
            "DMG +8",
            "Blade: Metsu: Damage +20%",
            "Ninjutsu casting time -20%",
            "Path A",
            "Rank 15",
        ):
            assert expected in augs, (expected, augs)


def test_farming_hardening_keeps_augment_variants_distinct_and_edges_unique() -> None:
    def req(details: list[str]) -> dict:
        return {
            "kind": "item",
            "item_id": 200,
            "item_name": "Test Mantle",
            "label": "Test Mantle",
            "quantity": 1,
            "needed": 1,
            "owned": 0,
            "missing": 1,
            "known": True,
            "slots": ["back"],
            "resource_key": "item:200",
            "augment_required": True,
            "details": details,
        }
    plan = {"personal": [{
        "id": "nin:ws",
        "title": "NIN WS",
        "job": "NIN",
        "category": "gear_set",
        "priority": 10,
        "requirements": [req(["STR+10"]), req(["STR+10"]), req(["DEX+10"])],
    }]}
    farm = farming_planner.build_farming_plan(plan)
    resources = farm["resources"]
    assert len(resources) == 2, resources
    assert len({r["resource_key"] for r in resources}) == 2
    assert all("#augment:" in r["resource_key"] for r in resources)
    assert all(r["goal_count"] == 1 for r in resources), resources
    assert all(r["shared_across_goals"] is False for r in resources), resources
    edges = farm["dependency_graph"]["edges"]
    assert len(edges) == 2, edges
    assert len({(e["from"], e["to"], e["kind"], e["reusable"]) for e in edges}) == 2


def test_farming_global_consumable_stock_is_subtracted_once_and_consistency_reported() -> None:
    req = lambda owned: {
        "kind": "currency", "key": "gil", "label": "Gil", "quantity": 100,
        "needed": 100, "owned": owned, "missing": max(0, 100-owned), "known": True,
        "resource_key": "currency:gil",
    }
    plan = {"personal": [
        {"id": "a", "title": "A", "requirements": [req(100)]},
        {"id": "b", "title": "B", "requirements": [req(100)]},
    ]}
    farm = farming_planner.build_farming_plan(plan)
    row = next(r for r in farm["resources"] if r["resource_key"] == "currency:gil")
    assert row["required"] == 200
    assert row["owned"] == 100
    assert row["missing"] == 100
    assert row["owned_consistent"] is True
    assert row["goal_count"] == 2


def test_gearlab_context_is_inherited_and_cross_job_signal_is_not_linked() -> None:
    signals: list[dict] = []
    gearlab_adapter._extract_upgrade_signals({
        "job": "WAR",
        "set_name": "TP",
        "variant_name": "Normal",
        "objective": "DPS",
        "results": [{"item_name": "Shared Hat", "item_id": 1234, "slot": "Head", "gain_percent": 90}],
    }, "war.json", signals, source_mtime=123)
    assert len(signals) == 1
    assert signals[0]["job"] == "WAR"
    assert signals[0]["set_name"] == "TP"
    assert signals[0]["variant_name"] == "Normal"
    assert signals[0]["item_id"] == 1234
    assert signals[0]["source_mtime"] == 123

    cache = {"results": {"upgrade_signals": [
        signals[0],
        {"item": "Shared Hat", "item_id": 1234, "slot": "Head", "job": "NIN", "gain_percent": 2,
         "source_file": "nin.json", "evidence_only": True},
    ]}}
    plan = {"personal": [{
        "id": "nin:tp", "job": "NIN", "set_name": "TP", "personal_goal": True,
        "requirements": [{"item_id": 1234, "item_name": "Shared Hat", "slots": ["Head"]}],
    }], "guide": []}
    priorities = gearlab_adapter.build_performance_priorities(cache, plan)
    war = next(r for r in priorities["rows"] if r.get("job") == "WAR")
    nin = next(r for r in priorities["rows"] if r.get("job") == "NIN")
    assert war["linked_tasks"] == []
    assert nin["linked_tasks"] and nin["linked_tasks"][0]["task_id"] == "nin:tp"

    farm_plan = {"personal": [{
        "id": "nin:tp", "title": "NIN TP", "job": "NIN", "priority": 1,
        "requirements": [{
            "kind": "item", "item_id": 1234, "item_name": "Shared Hat", "label": "Shared Hat",
            "slots": ["Head"], "quantity": 1, "needed": 1, "owned": 0, "missing": 1,
            "known": True, "resource_key": "item:1234",
        }],
    }]}
    farm = farming_planner.build_farming_plan(farm_plan, priorities)
    row = next(r for r in farm["resources"] if r["label"] == "Shared Hat")
    assert row["performance"]["gain_percent"] == 2, row["performance"]



def test_cp6_prelive_unknown_and_live_ready_semantics_survive() -> None:
    requirements = {
        "schema": "gearplaner.requirements/0.5",
        "tasks": [{
            "id": "cp6", "title": "NIN set", "source_type": "global_guide",
            "requirements": [{"kind": "item", "item_name": "Moonshade Earring", "quantity": 1}],
        }],
    }
    goals = {"schema": "gearplaner.goals/0.5", "goals": []}
    pre = {"items": [], "currencies": {}, "_availability": {"inventory": False, "currencies": False}}
    plan = resource_planner.build_plan(pre, requirements, goals)
    row = plan["guide"][0]["requirements"][0]
    assert row["known"] is False and row["missing"] is None
    assert plan["guide"][0]["status"] == "unknown"

    live = {
        "items": [{"item_id": 123, "name": "Moonshade Earring", "count": 1}],
        "currencies": {}, "_availability": {"inventory": True, "currencies": True},
    }
    plan = resource_planner.build_plan(live, requirements, goals)
    row = plan["guide"][0]["requirements"][0]
    assert row["known"] is True and row["owned"] == 1 and row["missing"] == 0
    assert plan["guide"][0]["status"] == "ready"


def _health_state(*, stack_count: int = 1, currency_value=100) -> dict:
    now = int(time.time())
    containers = [{"id": i} for i in range(17)]
    return {
        "exported_at_unix": now,
        "character": {"name": "ExamplePlayer"},
        "items": [{"item_id": 1, "name": "Test", "count": 1, "container_id": 0, "slot": 1}],
        "containers": containers,
        "equipped": [],
        "key_items": [],
        "currencies": {"gil": currency_value},
        "capture": {
            "container_ids": list(range(17)),
            "physical_stack_count": stack_count,
            "currency_0x113_seen": True,
            "currency_0x118_seen": True,
        },
        "points_overlay": {"active": False},
        "capabilities": {},
    }


def test_source_health_requires_capture_consistency_and_complete_currency_decode() -> None:
    catalog = {"packets": {"0x113": [{"key": "gil"}], "0x118": []}}
    with tempfile.TemporaryDirectory() as td:
        req = Path(td) / "requirements.json"; req.write_text("{}")
        goals = Path(td) / "goals.json"; goals.write_text("{}")
        good = source_health.build_source_health(
            _health_state(), points_status={"available": False}, currency_catalog=catalog,
            requirements_path=req, goals_path=goals,
        )
        assert good["inventory"]["capture_consistent"] is True
        assert good["inventory"]["capture_physical_stacks"] == 1
        assert good["currencies"]["decoder_complete"] is True
        assert good["checks"]["currency_decoder_complete"] is True
        assert good["live_ready"] is True, good["blocking_checks"]

        bad_stack = source_health.build_source_health(
            _health_state(stack_count=2), points_status={"available": False}, currency_catalog=catalog,
            requirements_path=req, goals_path=goals,
        )
        assert bad_stack["inventory"]["capture_consistent"] is False
        assert "inventory_collected" in bad_stack["blocking_checks"]

        bad_currency = source_health.build_source_health(
            _health_state(currency_value=None), points_status={"available": False}, currency_catalog=catalog,
            requirements_path=req, goals_path=goals,
        )
        assert bad_currency["currencies"]["decoder_complete"] is False
        assert "currency_decoder_complete" in bad_currency["blocking_checks"]


def run() -> None:
    tests = [v for k, v in sorted(globals().items()) if k.startswith("test_") and callable(v)]
    for test in tests:
        test()
        print(f"PASS: {test.__name__}")
    print(f"PASS: historical runtime contract ({len(tests)} groups)")


if __name__ == "__main__":
    run()
