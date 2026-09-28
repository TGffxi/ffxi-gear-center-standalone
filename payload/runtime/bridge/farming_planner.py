from __future__ import annotations

from collections import defaultdict
from typing import Any
import hashlib
import json
import re
import unicodedata

SCHEMA = "gearplaner.farming-plan/0.8"


def _norm(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or "")).replace("’", "'").replace("‘", "'")
    return re.sub(r"\s+", " ", text.strip()).casefold()


def _first(*values: Any) -> str:
    for value in values:
        text = str(value or "").strip()
        if text:
            return text
    return ""


def _resource_reusable(req: dict[str, Any], task: dict[str, Any]) -> bool:
    if req.get("reusable") is False or req.get("consumable") is True:
        return False
    if str(req.get("kind") or "") != "item":
        return False
    # Guide-set item requirements are equipment by construction.  Slots are
    # preserved by the target exporter, so shared pieces across several sets
    # must be counted once (or the max number of copies required by any set),
    # never summed once per set.
    if req.get("slots"):
        return True
    return str(task.get("category") or "") == "gear_set" and int(req.get("quantity") or 0) <= 2


def _system_from_currency(key: str) -> str:
    k = _norm(key).replace(" ", "_")
    rules = (
        (("gallimaufry",), "Sortie"),
        (("mog_segments", "segments"), "Odyssey"),
        (("hallmarks", "badges_of_gallantry"), "Ambuscade"),
        (("domain_points",), "Domain Invasion"),
        (("escha_silt", "escha_beads"), "Escha/Reisenjima"),
        (("plasm",), "Delve"),
        (("bayld",), "Adoulin"),
        (("nyzul_tokens", "assault_points"), "Assault/Nyzul"),
        (("therion_ichor",), "Einherjar"),
        (("allied_notes",), "Campaign"),
        (("cruor",), "Abyssea"),
        (("ancient_beastcoins", "temenos", "apollyon"), "Limbus"),
        (("sparks", "accolades"), "Records of Eminence / Unity"),
        (("gil",), "Gil / Market"),
    )
    for needles, label in rules:
        if any(n in k for n in needles):
            return label
    return "Stored currency / points"


def _system_from_text(text: str) -> str:
    t = _norm(text)
    rules = (
        (("sortie", "gallimaufry"), "Sortie"),
        (("odyssey", "sheol", "gaol", "segment"), "Odyssey / Gaol"),
        (("omen",), "Omen"),
        (("dynamis", "divergence"), "Dynamis-D"),
        (("ambuscade", "hallmark", "gallantry"), "Ambuscade"),
        (("limbus", "temenos", "apollyon"), "Limbus"),
        (("unity", "wanted"), "Unity / Wanted"),
        (("escha", "reisenjima"), "Escha/Reisenjima"),
        (("delve", "plasm"), "Delve"),
        (("skirmish",), "Skirmish"),
        (("trial", "magian"), "Trial / Magian"),
        (("synthesis", "synergy", "craft"), "Crafting / Synergy"),
        (("oboro",), "Oboro"),
        (("oseem",), "Oseem"),
        (("domain invasion",), "Domain Invasion"),
    )
    for needles, label in rules:
        if any(n in t for n in needles):
            return label
    return "Unresolved acquisition"


def _activity_for(req: dict[str, Any], task: dict[str, Any]) -> tuple[str, str]:
    if str(req.get("kind") or "") == "currency":
        key = str(req.get("key") or "")
        return _system_from_currency(key), f"currency:{key}"
    hints = " · ".join(filter(None, [
        _first(req.get("location"), req.get("route"), req.get("acquisition"), req.get("next_step")),
        _first(task.get("location"), task.get("next_step")),
        _first((task.get("source_meta") or {}).get("system"), (task.get("source_meta") or {}).get("route")),
    ]))
    return _system_from_text(hints), hints


def _augment_signature(req: dict[str, Any]) -> str:
    if req.get("augment_required") is not True:
        return ""
    details = req.get("details") or req.get("required_augments") or req.get("augments") or []
    canonical = json.dumps(details, ensure_ascii=False, sort_keys=True, separators=(",", ":"))
    return hashlib.sha256(canonical.encode("utf-8")).hexdigest()[:16]


def _resource_key(req: dict[str, Any]) -> str:
    key = _first(req.get("resource_key"))
    if not key:
        if str(req.get("kind") or "") == "currency":
            key = "currency:" + str(req.get("key") or "")
        else:
            item_id = int(req.get("item_id") or 0)
            key = f"item:{item_id}" if item_id > 0 else "item-name:" + _norm(_first(req.get("item_name"), req.get("label")))
    signature = _augment_signature(req)
    return f"{key}#augment:{signature}" if key and signature else key


def _performance_by_item(priorities: dict[str, Any] | None) -> dict[str, list[dict[str, Any]]]:
    out: dict[str, list[dict[str, Any]]] = defaultdict(list)
    for row in (priorities or {}).get("rows") or []:
        name = _first(row.get("item"), row.get("item_name"))
        if not name:
            continue
        key = _norm(name)
        gain = row.get("gain_percent")
        dps = row.get("dps_gain")
        if gain is None and dps is None:
            continue
        out[key].append(row)
    def rank(x: dict[str, Any]) -> tuple[float, float]:
        return (float(x.get("gain_percent") if x.get("gain_percent") is not None else -1e18),
                float(x.get("dps_gain") if x.get("dps_gain") is not None else -1e18))
    return {key: sorted(rows, key=rank, reverse=True) for key, rows in out.items()}


def _signal_for_task(signals: list[dict[str, Any]], task: dict[str, Any], req: dict[str, Any]) -> dict[str, Any] | None:
    task_id = str(task.get("id") or "")
    task_job = _norm(task.get("job"))
    req_slots = {_norm(x) for x in req.get("slots") or [] if _norm(x)}
    for signal in signals:
        linked = signal.get("linked_tasks") or []
        if linked and not any(str(ref.get("task_id") or "") == task_id for ref in linked):
            continue
        signal_job = _norm(signal.get("job"))
        if signal_job and task_job and signal_job != task_job:
            continue
        signal_slot = _norm(signal.get("slot"))
        if signal_slot and req_slots and signal_slot not in req_slots:
            continue
        signal_item_id = int(signal.get("item_id") or 0)
        req_item_id = int(req.get("item_id") or 0)
        if signal_item_id and req_item_id and signal_item_id != req_item_id:
            continue
        return signal
    return None

def build_farming_plan(plan: dict[str, Any], priorities: dict[str, Any] | None = None) -> dict[str, Any]:
    personal = list(plan.get("personal") or [])
    perf = _performance_by_item(priorities)
    buckets: dict[str, dict[str, Any]] = {}
    graph_edges: list[dict[str, Any]] = []

    for task in personal:
        task_id = str(task.get("id") or "")
        task_label = _first(task.get("title"), task_id)
        task_priority = float(task.get("priority") or 0)
        for req in task.get("requirements") or []:
            resource_key = _resource_key(req)
            if not resource_key:
                continue
            label = _first(req.get("label"), req.get("item_name"), req.get("key"), resource_key)
            reusable = _resource_reusable(req, task)
            activity, activity_hint = _activity_for(req, task)
            missing = req.get("missing")
            needed = int(req.get("needed") or req.get("quantity") or 0)
            owned = req.get("owned")
            known = req.get("known") is True
            row = buckets.setdefault(resource_key, {
                "resource_key": resource_key,
                "kind": req.get("kind"),
                "label": label,
                "reusable": reusable,
                "required": 0,
                "owned": owned if known else None,
                "missing": None,
                "known": known,
                "partial_unknown": False,
                "goal_count": 0,
                "goals": [],
                "jobs": [],
                "slots": [],
                "activities": [],
                "activity_hints": [],
                "max_personal_priority": task_priority,
                "performance": None,
                "augment_required": req.get("augment_required") is True,
                "augment_signature": _augment_signature(req) or None,
                "_owned_values": [],
            })
            # A generated catalog can refer to the same item from several sets.
            # Equipment is reusable between sets: max requirement/missing wins.
            # Consumables/currencies/materials are additive.
            if reusable:
                row["required"] = max(int(row.get("required") or 0), needed)
            else:
                row["required"] = int(row.get("required") or 0) + needed
            if not known:
                if row.get("known") is True:
                    row["partial_unknown"] = True
                row["known"] = False
            elif row.get("known") is False:
                row["partial_unknown"] = True
            if known and owned is not None:
                row["_owned_values"].append(int(owned))
            row["max_personal_priority"] = max(float(row.get("max_personal_priority") or 0), task_priority)
            goal_ref = {"task_id": task_id, "title": task_label, "job": task.get("job"), "set_name": task.get("set_name"), "variant_name": task.get("variant_name"), "priority": task_priority}
            if not any(str(g.get("task_id") or "") == task_id for g in row["goals"]):
                row["goals"].append(goal_ref)
            if task.get("job") and task.get("job") not in row["jobs"]:
                row["jobs"].append(task.get("job"))
            for slot in req.get("slots") or []:
                if slot not in row["slots"]:
                    row["slots"].append(slot)
            if activity not in row["activities"]:
                row["activities"].append(activity)
            if activity_hint and activity_hint not in row["activity_hints"]:
                row["activity_hints"].append(activity_hint)
            signal = _signal_for_task(perf.get(_norm(label), []), task, req)
            if signal:
                current = row.get("performance")
                new_rank = (float(signal.get("gain_percent") or -1e18), float(signal.get("dps_gain") or -1e18))
                old_rank = (float((current or {}).get("gain_percent") or -1e18), float((current or {}).get("dps_gain") or -1e18))
                if current is None or new_rank > old_rank:
                    row["performance"] = {
                        "gain_percent": signal.get("gain_percent"),
                        "dps_gain": signal.get("dps_gain"),
                        "objective": signal.get("objective"),
                        "source_file": signal.get("source_file"),
                        "evidence_only": True,
                    }
            edge = {"from": f"goal:{task_id}", "to": f"resource:{resource_key}", "kind": "requires", "reusable": reusable}
            if edge not in graph_edges:
                graph_edges.append(edge)

    resources = list(buckets.values())
    for row in resources:
        owned_values = row.pop("_owned_values", [])
        row["goal_count"] = len(row["goals"])
        if row["known"] and owned_values:
            row["owned"] = max(owned_values)
            row["owned_consistent"] = len(set(owned_values)) == 1
            # Inventory/currencies are global stock: aggregate requirement first,
            # then subtract that shared holding exactly once.
            row["missing"] = max(0, int(row.get("required") or 0) - int(row["owned"]))
        elif row["known"]:
            row["known"] = False
            row["owned"] = None
            row["missing"] = None
            row["partial_unknown"] = True
            row["owned_consistent"] = False
        else:
            row["owned"] = None
            row["missing"] = None
            row["owned_consistent"] = len(set(owned_values)) <= 1
        if not row["activities"]:
            row["activities"] = ["Unresolved acquisition"]
        if row["known"]:
            # Required has now been aggregated. Apply the one shared holding once.
            row["missing"] = max(0, int(row.get("required") or 0) - int(row.get("owned") or 0))
            row["status"] = "ready" if int(row.get("missing") or 0) == 0 else "farm"
        else:
            row["status"] = "unknown"
        row["shared_across_goals"] = row["goal_count"] > 1
        row["aggregation_rule"] = "max_across_goals" if row["reusable"] else "sum_across_goals"

    # No synthetic optimizer score: stable lexicographic ordering uses explicit
    # personal priority first, then explicit Gear-Lab gain evidence, then sharing.
    status_rank = {"farm": 0, "unknown": 1, "ready": 2}
    def sort_key(row: dict[str, Any]):
        perfrow = row.get("performance") or {}
        gain = perfrow.get("gain_percent")
        dps = perfrow.get("dps_gain")
        return (
            status_rank.get(str(row.get("status")), 9),
            -float(row.get("max_personal_priority") or 0),
            -float(gain if gain is not None else -1e18),
            -float(dps if dps is not None else -1e18),
            -int(row.get("goal_count") or 0),
            _norm(row.get("label")),
        )
    resources.sort(key=sort_key)

    activity_map: dict[str, dict[str, Any]] = {}
    for row in resources:
        if row["status"] == "ready":
            continue
        for activity in row.get("activities") or ["Unresolved acquisition"]:
            group = activity_map.setdefault(activity, {
                "activity": activity,
                "resources": [],
                "resource_count": 0,
                "known_deficit_count": 0,
                "unknown_count": 0,
                "goal_ids": [],
                "max_personal_priority": 0.0,
                "best_performance_gain_percent": None,
            })
            group["resources"].append(row)
            group["resource_count"] += 1
            if row["status"] == "farm":
                group["known_deficit_count"] += 1
            elif row["status"] == "unknown":
                group["unknown_count"] += 1
            group["max_personal_priority"] = max(group["max_personal_priority"], float(row.get("max_personal_priority") or 0))
            gain = (row.get("performance") or {}).get("gain_percent")
            if gain is not None:
                if group["best_performance_gain_percent"] is None or float(gain) > float(group["best_performance_gain_percent"]):
                    group["best_performance_gain_percent"] = gain
            for goal in row.get("goals") or []:
                tid = goal.get("task_id")
                if tid and tid not in group["goal_ids"]:
                    group["goal_ids"].append(tid)
    activities = list(activity_map.values())
    activities.sort(key=lambda g: (
        0 if g["known_deficit_count"] else 1,
        -float(g.get("max_personal_priority") or 0),
        -float(g.get("best_performance_gain_percent") if g.get("best_performance_gain_percent") is not None else -1e18),
        -int(g.get("resource_count") or 0),
        _norm(g.get("activity")),
    ))

    goal_nodes = [{"id": f"goal:{t.get('id')}", "kind": "goal", "label": _first(t.get("title"), t.get("id")), "job": t.get("job"), "status": t.get("status")} for t in personal]
    resource_nodes = [{"id": f"resource:{r['resource_key']}", "kind": "resource", "label": r["label"], "status": r["status"], "shared": r["shared_across_goals"]} for r in resources]

    counts = {
        "personal_goals": len(personal),
        "unique_resources": len(resources),
        "farm_resources": sum(1 for r in resources if r["status"] == "farm"),
        "unknown_resources": sum(1 for r in resources if r["status"] == "unknown"),
        "ready_resources": sum(1 for r in resources if r["status"] == "ready"),
        "shared_resources": sum(1 for r in resources if r["shared_across_goals"]),
        "activity_groups": len(activities),
        "ready_goals": sum(1 for t in personal if t.get("status") == "ready"),
        "farm_goals": sum(1 for t in personal if t.get("status") == "farm"),
        "unknown_goals": sum(1 for t in personal if t.get("status") == "unknown"),
    }
    return {
        "schema": SCHEMA,
        "personal_only": True,
        "has_personal_goals": bool(personal),
        "counts": counts,
        "resources": resources,
        "queue": [r for r in resources if r["status"] != "ready"],
        "activities": activities,
        "goals": [{
            "id": t.get("id"), "title": t.get("title"), "job": t.get("job"), "set_name": t.get("set_name"),
            "variant_name": t.get("variant_name"), "status": t.get("status"), "readiness": t.get("readiness"),
            "priority": t.get("priority"), "missing_count": t.get("missing_count"), "unknown_count": t.get("unknown_count"),
        } for t in personal],
        "dependency_graph": {"nodes": goal_nodes + resource_nodes, "edges": graph_edges},
        "rules": {
            "guide_recommendations_are_not_farming_goals": True,
            "reusable_gear_is_aggregated_by_max_not_sum": True,
            "consumables_and_currencies_are_additive": True,
            "global_owned_stock_is_subtracted_once_after_aggregation": True,
            "augment_variants_have_distinct_resource_keys": True,
            "duplicate_goal_resource_edges_are_deduplicated": True,
            "unknown_values_are_never_zero": True,
            "performance_ordering_uses_only_explicit_gearlab_gain_evidence": True,
            "no_synthetic_stat_score": True,
        },
    }
