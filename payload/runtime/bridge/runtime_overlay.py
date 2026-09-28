from __future__ import annotations

import json
import re
import unicodedata
from copy import deepcopy
from pathlib import Path
from typing import Any

from resource_planner import GOALS_SCHEMA, REQUIREMENTS_SCHEMA, PlannerDataError, build_resource_index

OVERLAY_SCHEMA = "gearcenter.runtime-planning-overlay/1.0"


def _norm(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or "")).replace("’", "'").replace("‘", "'")
    return re.sub(r"\s+", " ", text.strip()).casefold()


def load_runtime_overlay(path: str | Path | None) -> dict[str, Any]:
    if path is None:
        return empty_overlay()
    p = Path(path).expanduser()
    if not p.is_file():
        return empty_overlay(path=str(p), status="MISSING")
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except (OSError, UnicodeDecodeError, json.JSONDecodeError) as exc:
        raise PlannerDataError(f"Invalid runtime planning overlay {p}: {exc}") from exc
    if not isinstance(data, dict):
        raise PlannerDataError(f"Runtime planning overlay root in {p} must be an object")
    if data.get("schema") != OVERLAY_SCHEMA:
        raise PlannerDataError(f"Unsupported runtime planning overlay schema: {data.get('schema')!r}")
    reqs = data.get("requirements") or {}
    goals = data.get("goals") or {}
    if reqs.get("schema") != REQUIREMENTS_SCHEMA or not isinstance(reqs.get("tasks"), list):
        raise PlannerDataError("runtime overlay requirements are invalid")
    if goals.get("schema") != GOALS_SCHEMA or not isinstance(goals.get("goals"), list):
        raise PlannerDataError("runtime overlay goals are invalid")
    if not isinstance(data.get("progression_todos") or [], list):
        raise PlannerDataError("runtime overlay progression_todos must be an array")
    out = dict(data)
    out["path"] = str(p)
    out["available"] = True
    return out


def empty_overlay(*, path: str | None = None, status: str = "EMPTY") -> dict[str, Any]:
    return {
        "schema": OVERLAY_SCHEMA,
        "requirements": {"schema": REQUIREMENTS_SCHEMA, "tasks": []},
        "goals": {"schema": GOALS_SCHEMA, "goals": []},
        "progression_todos": [],
        "available": False,
        "status": status,
        "path": path,
    }


def merge_planning_catalogs(requirements: dict[str, Any], goals: dict[str, Any], overlay: dict[str, Any]) -> tuple[dict[str, Any], dict[str, Any]]:
    """Merge explicit SQLite-backed goals into the existing guide/runtime catalogs.

    Task IDs are namespaced by the exporter. A collision is an error rather than a
    silent overwrite because that would make a personal goal point at the wrong set.
    """
    req_out = deepcopy(requirements)
    goal_out = deepcopy(goals)
    req_out.setdefault("tasks", [])
    goal_out.setdefault("goals", [])

    known = {str(row.get("id")) for row in req_out["tasks"]}
    for task in (overlay.get("requirements") or {}).get("tasks") or []:
        task_id = str(task.get("id") or "").strip()
        if not task_id:
            raise PlannerDataError("runtime overlay task missing id")
        if task_id in known:
            raise PlannerDataError(f"runtime overlay task id collides with requirements catalog: {task_id}")
        row = deepcopy(task)
        meta = dict(row.get("source_meta") or {})
        meta["goal_origin"] = "sqlite"
        row["source_meta"] = meta
        req_out["tasks"].append(row)
        known.add(task_id)

    goal_ids = {str(row.get("task_id")) for row in goal_out["goals"]}
    for goal in (overlay.get("goals") or {}).get("goals") or []:
        task_id = str(goal.get("task_id") or "").strip()
        if not task_id:
            raise PlannerDataError("runtime overlay goal missing task_id")
        if task_id not in known:
            raise PlannerDataError(f"runtime overlay goal points to unknown task: {task_id}")
        if task_id in goal_ids:
            raise PlannerDataError(f"runtime overlay goal collides with JSON personal goal: {task_id}")
        goal_out["goals"].append(deepcopy(goal))
        goal_ids.add(task_id)

    req_out["runtime_overlay_task_count"] = len((overlay.get("requirements") or {}).get("tasks") or [])
    goal_out["runtime_overlay_goal_count"] = len((overlay.get("goals") or {}).get("goals") or [])
    return req_out, goal_out


def _currency_lookup(resources: dict[str, Any]) -> dict[str, dict[str, Any]]:
    out: dict[str, dict[str, Any]] = {}
    for key, row in (resources.get("currencies") or {}).items():
        for alias in {str(key), str(key).replace("_", " "), str(key).replace("-", " ")}:
            out[_norm(alias)] = row
    return out


def _evaluate_named_resource(name: str, semantic_kind: str, resources: dict[str, Any]) -> tuple[str, int | None, bool, str | None]:
    """Return runtime kind, owned quantity, whether ownership is known, source.

    BG-Wiki's `currency_or_progression_resource` is intentionally broad: many such
    resources (Detritus, Eschalixir, etc.) are physical items. Items are therefore
    attempted first. A missing ambiguous progression resource remains unknown rather
    than being fabricated as zero.
    """
    n = _norm(name)
    item = (resources.get("items_by_name") or {}).get(n)
    if item is not None:
        known = (resources.get("availability") or {}).get("inventory", True) is not False
        return "item", int(item.get("count") or 0) if known else None, known, "gearplaner_bridge"

    currencies = _currency_lookup(resources)
    cur = currencies.get(n)
    if cur is not None:
        known = cur.get("known") is True
        value = cur.get("value")
        return "currency", int(value) if known and value is not None else None, known, str(cur.get("source") or "currency_overlay")

    if semantic_kind == "item_or_resource":
        known = (resources.get("availability") or {}).get("inventory", True) is not False
        return "item", 0 if known else None, known, "gearplaner_bridge" if known else None

    # Could be an untracked currency, KI, or physical progression item. Do not lie.
    return "currency", None, False, None


def _todo_goal_ref(todo: dict[str, Any]) -> dict[str, Any]:
    return {
        "task_id": str(todo.get("id") or ""),
        "title": str(todo.get("title") or "Progression To-Do"),
        "job": None,
        "set_name": str(todo.get("item_name") or todo.get("title") or "Progression"),
        "variant_name": "Progressions-To-Do",
        "priority": float(todo.get("priority") or 0),
        "goal_origin": "sqlite_todo",
        "manage_url": str(todo.get("manage_url") or "/advanced.php?view=tasks&compact=1&return=farming"),
    }


def merge_progression_todos_into_farming(farming: dict[str, Any], overlay: dict[str, Any], state: dict[str, Any]) -> dict[str, Any]:
    """Add structured BG-Wiki progression material requirements to normal Farmplan.

    Fixed quantities are additive across explicit progression To-Dos and live stock
    is subtracted once. ANY-OF groups are separate unresolved choice rows; their
    alternatives are never summed. Unknown quantities stay unknown.
    """
    out = deepcopy(farming)
    todos = [row for row in overlay.get("progression_todos") or [] if str(row.get("status") or "") in {"open", "doing"}]
    if not todos:
        out["progression_todos"] = []
        out.setdefault("counts", {})["progression_todos"] = 0
        return out

    live = build_resource_index(state)
    existing = list(out.get("resources") or [])
    overlay_buckets: dict[str, dict[str, Any]] = {}
    alternative_rows: list[dict[str, Any]] = []

    for todo in todos:
        goal = _todo_goal_ref(todo)
        activity = str(todo.get("source_title") or todo.get("plan_title") or todo.get("title") or "BG-Wiki progression")
        activity_hint = str(todo.get("summary") or "Structured BG-Wiki progression To-Do")
        for req in todo.get("requirements") or []:
            name = str(req.get("name") or "").strip()
            if not name:
                continue
            semantic_kind = str(req.get("kind") or "item_or_resource")
            runtime_kind, owned, owned_known, owned_source = _evaluate_named_resource(name, semantic_kind, live)
            quantity_known = req.get("quantity_known") is True and req.get("required_quantity") is not None
            required = int(req.get("required_quantity") or 0) if quantity_known else None
            key = "progression:" + _norm(name)
            row = overlay_buckets.setdefault(key, {
                "resource_key": key,
                "kind": runtime_kind,
                "label": name,
                "reusable": False,
                "required": 0,
                "owned": owned if owned_known else None,
                "missing": 0 if owned_known else None,
                "known": owned_known and quantity_known,
                "partial_unknown": False,
                "quantity_known": quantity_known,
                "goal_count": 0,
                "goals": [],
                "jobs": [],
                "slots": [],
                "activities": [],
                "activity_hints": [],
                "max_personal_priority": 0.0,
                "performance": None,
                "augment_required": False,
                "progression_resource": True,
                "sources": [],
                "owned_source": owned_source,
                "unknown_reason": None,
            })
            if row["kind"] != runtime_kind:
                # Same BG-Wiki label resolved differently in two runs/contexts. Be conservative.
                row["kind"] = "item"
                row["known"] = False
                row["owned"] = None
                row["missing"] = None
                row["partial_unknown"] = True
            if quantity_known:
                row["required"] = int(row.get("required") or 0) + int(required or 0)
            else:
                row["quantity_known"] = False
                row["known"] = False
                row["missing"] = None
                row["unknown_reason"] = "BG-Wiki nennt die Ressource, aber keine belastbare Menge."
            if not owned_known:
                row["known"] = False
                row["missing"] = None
                row["partial_unknown"] = True
                row["unknown_reason"] = row["unknown_reason"] or "Aktueller Bestand dieser Progressionsressource ist nicht sicher aufgelöst."
            if owned is not None:
                row["owned"] = max(int(row.get("owned") or 0), int(owned))
            row["goal_count"] += 1
            row["goals"].append(goal)
            row["max_personal_priority"] = max(float(row.get("max_personal_priority") or 0), float(todo.get("priority") or 0))
            if activity not in row["activities"]:
                row["activities"].append(activity)
            if activity_hint and activity_hint not in row["activity_hints"]:
                row["activity_hints"].append(activity_hint)
            for src in req.get("sources") or []:
                if src not in row["sources"]:
                    row["sources"].append(src)

        for group in todo.get("alternative_groups") or []:
            choices = []
            for choice in group.get("choices") or []:
                name = str(choice.get("name") or "").strip()
                if not name:
                    continue
                rk, owned, known, source = _evaluate_named_resource(name, str(choice.get("kind") or "item_or_resource"), live)
                choices.append({**deepcopy(choice), "runtime_kind": rk, "owned_quantity": owned if known else None, "owned_known": known, "owned_source": source})
            if not choices:
                continue
            alternative_rows.append({
                "resource_key": "progression-choice:" + str(group.get("key") or f"{todo.get('id')}:{len(alternative_rows)}"),
                "kind": "alternative_group",
                "label": str(group.get("label") or "Alternative Materialien"),
                "reusable": False,
                "required": None,
                "owned": None,
                "missing": None,
                "known": False,
                "partial_unknown": True,
                "quantity_known": False,
                "goal_count": 1,
                "goals": [goal],
                "jobs": [], "slots": [],
                "activities": [activity],
                "activity_hints": [str(group.get("raw") or "ANY-OF: eine der aufgeführten Alternativen wählen")],
                "max_personal_priority": float(todo.get("priority") or 0),
                "performance": None,
                "augment_required": False,
                "progression_resource": True,
                "alternative_choices": choices,
                "aggregation_rule": "any_of_not_summed",
                "status": "unknown",
                "unknown_reason": "ANY-OF-Auswahl: Alternativen dürfen nicht addiert werden.",
            })

        # Reinforcement/RP systems often intentionally have no deterministic amount
        # until the physical item's live rank is known. Surface accepted resources as
        # an ANY-OF context row instead of inventing a number.
        for system in todo.get("systems") or []:
            accepted = []
            for choice in system.get("accepted_resources") or []:
                name = str(choice.get("name") or "").strip()
                if not name:
                    continue
                rk, owned, known, source = _evaluate_named_resource(name, "currency_or_progression_resource", live)
                accepted.append({**deepcopy(choice), "runtime_kind": rk, "owned_quantity": owned if known else None, "owned_known": known, "owned_source": source})
            if accepted:
                alternative_rows.append({
                    "resource_key": "progression-system:" + str(todo.get("id")) + ":" + str(system.get("key") or len(alternative_rows)),
                    "kind": "alternative_group",
                    "label": str(system.get("label") or "Progressionsressourcen"),
                    "reusable": False,
                    "required": None,
                    "owned": None,
                    "missing": None,
                    "known": False,
                    "partial_unknown": True,
                    "quantity_known": False,
                    "goal_count": 1,
                    "goals": [goal],
                    "jobs": [], "slots": [],
                    "activities": [str(system.get("content") or system.get("npc") or activity)],
                    "activity_hints": list(system.get("notes") or []),
                    "max_personal_priority": float(todo.get("priority") or 0),
                    "performance": None,
                    "augment_required": False,
                    "progression_resource": True,
                    "alternative_choices": accepted,
                    "progression_system": deepcopy(system),
                    "aggregation_rule": "system_choice_not_summed",
                    "status": "unknown",
                    "unknown_reason": str(system.get("remaining_state") or "Live Path/Rank/RP determines the remaining amount."),
                })

    for row in overlay_buckets.values():
        row["shared_across_goals"] = row["goal_count"] > 1
        row["aggregation_rule"] = "sum_across_progression_todos"
        if row["known"] and row["quantity_known"]:
            row["missing"] = max(0, int(row.get("required") or 0) - int(row.get("owned") or 0))
            row["status"] = "ready" if int(row["missing"]) == 0 else "farm"
        else:
            row["required"] = row["required"] if row["quantity_known"] else None
            row["missing"] = None
            row["status"] = "unknown"

    merged = existing + list(overlay_buckets.values()) + alternative_rows
    # Existing resources already include their own aggregation. Overlay resource keys
    # are namespaced so they never accidentally convert reusable target gear into a
    # consumable material requirement.
    status_rank = {"farm": 0, "unknown": 1, "ready": 2}
    merged.sort(key=lambda r: (
        status_rank.get(str(r.get("status")), 9),
        -float(r.get("max_personal_priority") or 0),
        _norm(r.get("label")),
    ))

    activities: dict[str, dict[str, Any]] = {}
    for row in merged:
        if row.get("status") == "ready":
            continue
        for activity in row.get("activities") or ["Unresolved acquisition"]:
            g = activities.setdefault(str(activity), {
                "activity": str(activity), "resources": [], "resource_count": 0,
                "known_deficit_count": 0, "unknown_count": 0, "goal_ids": [],
                "max_personal_priority": 0.0, "best_performance_gain_percent": None,
            })
            g["resources"].append(row); g["resource_count"] += 1
            if row.get("status") == "farm": g["known_deficit_count"] += 1
            elif row.get("status") == "unknown": g["unknown_count"] += 1
            g["max_personal_priority"] = max(g["max_personal_priority"], float(row.get("max_personal_priority") or 0))
            for goal in row.get("goals") or []:
                tid = goal.get("task_id")
                if tid and tid not in g["goal_ids"]: g["goal_ids"].append(tid)
    activity_rows = list(activities.values())
    activity_rows.sort(key=lambda g: (0 if g["known_deficit_count"] else 1, -float(g["max_personal_priority"]), _norm(g["activity"])))

    out["resources"] = merged
    out["queue"] = [r for r in merged if r.get("status") != "ready"]
    out["activities"] = activity_rows
    out["progression_todos"] = todos
    out["has_personal_goals"] = bool(out.get("has_personal_goals")) or bool(todos)
    counts = dict(out.get("counts") or {})
    counts.update({
        "progression_todos": len(todos),
        "unique_resources": len(merged),
        "farm_resources": sum(1 for r in merged if r.get("status") == "farm"),
        "unknown_resources": sum(1 for r in merged if r.get("status") == "unknown"),
        "ready_resources": sum(1 for r in merged if r.get("status") == "ready"),
        "shared_resources": sum(1 for r in merged if r.get("shared_across_goals")),
        "activity_groups": len(activity_rows),
    })
    out["counts"] = counts
    rules = dict(out.get("rules") or {})
    rules.update({
        "sqlite_progression_todos_are_personal": True,
        "bgwiki_unknown_quantities_remain_unknown": True,
        "bgwiki_any_of_alternatives_are_not_summed": True,
    })
    out["rules"] = rules
    return out
