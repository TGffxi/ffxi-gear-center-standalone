from __future__ import annotations

import json
import hashlib
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any
import re
import unicodedata

REQUIREMENTS_SCHEMA = "gearplaner.requirements/0.6"
GOALS_SCHEMA = "gearplaner.goals/0.6"
LEGACY_REQUIREMENTS_SCHEMAS = {"gearplaner.requirements/0.5"}
LEGACY_GOALS_SCHEMAS = {"gearplaner.goals/0.5"}


class PlannerDataError(ValueError):
    pass


def _load_json(path: str | Path, *, default: dict[str, Any]) -> dict[str, Any]:
    p = Path(path).expanduser()
    if not p.is_file():
        return default
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise PlannerDataError(f"Invalid JSON in {p}: {exc}") from exc
    if not isinstance(data, dict):
        raise PlannerDataError(f"JSON root in {p} must be an object")
    return data


def load_requirements(path: str | Path) -> dict[str, Any]:
    data = _load_json(path, default={"schema": REQUIREMENTS_SCHEMA, "tasks": []})
    if data.get("schema") not in {REQUIREMENTS_SCHEMA, *LEGACY_REQUIREMENTS_SCHEMAS}:
        raise PlannerDataError(f"Unsupported requirements schema: {data.get('schema')!r}")
    data = dict(data)
    data["source_schema"] = data.get("schema")
    data["schema"] = REQUIREMENTS_SCHEMA
    tasks = data.get("tasks")
    if not isinstance(tasks, list):
        raise PlannerDataError("requirements.tasks must be an array")
    seen: set[str] = set()
    for idx, task in enumerate(tasks):
        _validate_task(task, idx)
        task_id = str(task["id"])
        if task_id in seen:
            raise PlannerDataError(f"Duplicate task id: {task_id}")
        seen.add(task_id)
    return data


def load_goals(path: str | Path) -> dict[str, Any]:
    data = _load_json(path, default={"schema": GOALS_SCHEMA, "goals": []})
    if data.get("schema") not in {GOALS_SCHEMA, *LEGACY_GOALS_SCHEMAS}:
        raise PlannerDataError(f"Unsupported goals schema: {data.get('schema')!r}")
    data = dict(data)
    data["source_schema"] = data.get("schema")
    data["schema"] = GOALS_SCHEMA
    goals = data.get("goals")
    if not isinstance(goals, list):
        raise PlannerDataError("goals.goals must be an array")
    seen: set[str] = set()
    for idx, goal in enumerate(goals):
        if not isinstance(goal, dict):
            raise PlannerDataError(f"goals[{idx}] must be an object")
        task_id = str(goal.get("task_id") or "").strip()
        if not task_id:
            raise PlannerDataError(f"goals[{idx}] missing task_id")
        if task_id in seen:
            raise PlannerDataError(f"Duplicate personal goal task_id: {task_id}")
        seen.add(task_id)
        if "priority" in goal and not isinstance(goal["priority"], (int, float)):
            raise PlannerDataError(f"goals[{idx}].priority must be numeric")
    return data


def _validate_task(task: Any, idx: int) -> None:
    if not isinstance(task, dict):
        raise PlannerDataError(f"tasks[{idx}] must be an object")
    for key in ("id", "title"):
        if not str(task.get(key) or "").strip():
            raise PlannerDataError(f"tasks[{idx}] missing {key}")
    source_type = str(task.get("source_type") or "global_guide")
    if source_type not in {"global_guide", "manual", "imported"}:
        raise PlannerDataError(f"tasks[{idx}].source_type unsupported: {source_type}")
    requirements = task.get("requirements") or []
    if not isinstance(requirements, list):
        raise PlannerDataError(f"tasks[{idx}].requirements must be an array")
    for r_idx, req in enumerate(requirements):
        _validate_requirement(req, idx, r_idx)


def _validate_requirement(req: Any, task_idx: int, req_idx: int) -> None:
    if not isinstance(req, dict):
        raise PlannerDataError(f"tasks[{task_idx}].requirements[{req_idx}] must be an object")
    kind = str(req.get("kind") or "")
    if kind not in {"item", "currency"}:
        raise PlannerDataError(f"Unsupported requirement kind: {kind!r}")
    try:
        quantity = int(req.get("quantity"))
    except (TypeError, ValueError) as exc:
        raise PlannerDataError(f"Requirement quantity must be an integer: {req!r}") from exc
    if quantity <= 0:
        raise PlannerDataError(f"Requirement quantity must be > 0: {req!r}")
    if kind == "item":
        try:
            item_id = int(req.get("item_id") or 0)
        except (TypeError, ValueError) as exc:
            raise PlannerDataError(f"Item requirement item_id must be numeric when present: {req!r}") from exc
        item_name = str(req.get("item_name") or req.get("name") or "").strip()
        if item_id <= 0 and not item_name:
            raise PlannerDataError(f"Item requirement needs item_id or item_name: {req!r}")
    elif not str(req.get("key") or "").strip():
        raise PlannerDataError(f"Currency requirement missing key: {req!r}")


def _norm_item_name(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or "")).replace("’", "'").replace("‘", "'")
    return re.sub(r"\s+", " ", text.strip()).casefold()


def build_resource_index(state: dict[str, Any]) -> dict[str, Any]:
    availability = state.get("_availability") or {}
    inventory_available = availability.get("inventory", True) is not False
    currencies_available = availability.get("currencies", True) is not False
    item_totals: Counter[int] = Counter()
    item_names: dict[int, str] = {}
    item_locations: defaultdict[int, list[dict[str, Any]]] = defaultdict(list)
    name_totals: Counter[str] = Counter()
    name_rows: defaultdict[str, list[dict[str, Any]]] = defaultdict(list)
    for item in state.get("items") or []:
        item_id = int(item.get("item_id") or 0)
        count = int(item.get("count") or 0)
        name = str(item.get("name") or "").strip()
        if item_id > 0:
            item_totals[item_id] += count
            if name: item_names.setdefault(item_id, name)
            item_locations[item_id].append({
                "container_id": item.get("container_id"), "container_name": item.get("container_name"),
                "slot": item.get("slot"), "count": count, "extra_hex": item.get("extra_hex"),
                "decoded_augments": item.get("decoded_augments") or [], "augment_source": item.get("augment_source"),
                "equipped_slots": item.get("equipped_slots") or [],
            })
        if name:
            key=_norm_item_name(name); name_totals[key]+=count; name_rows[key].append(item)

    currencies: dict[str, Any] = {}
    currency_prov = (state.get("provenance") or {}).get("currencies") or {}
    for key, value in (state.get("currencies") or {}).items():
        currencies[str(key)] = {"value": value, "known": currencies_available and value is not None, "source": currency_prov.get(key) or "unknown"}

    items = {str(item_id): {"item_id": item_id, "name": item_names.get(item_id), "count": count, "locations": item_locations.get(item_id, [])}
             for item_id, count in sorted(item_totals.items())}
    by_name = {key: {"name": (rows[0].get("name") if rows else None), "count": int(name_totals[key]), "rows": rows}
               for key, rows in name_rows.items()}
    return {"items": items, "items_by_name": by_name, "currencies": currencies,
            "availability": {"inventory": inventory_available, "currencies": currencies_available,
                             "augments": availability.get("augments", False)}}


def _goal_map(goals: dict[str, Any]) -> dict[str, dict[str, Any]]:
    return {
        str(goal["task_id"]): goal
        for goal in goals.get("goals") or []
        if goal.get("enabled", True) is not False
    }


def _words(value: Any) -> set[str]:
    text = unicodedata.normalize("NFKC", str(value or "")).casefold()
    return set(re.findall(r"[a-z0-9]+", text))


def _norm_augment_text(value: Any) -> str:
    text = unicodedata.normalize("NFKC", str(value or "")).replace("’", "'").replace("‘", "'").casefold()
    text = text.replace('"', '').replace("'", '')
    text = re.sub(r"\s+", " ", text.strip())
    return text


def _augment_requirement_signature(req: dict[str, Any]) -> str | None:
    if req.get("augment_required") is not True:
        return None
    parts = [_norm_augment_text(x) for x in _augment_phrases(req.get("details") or req.get("required_augments") or [])]
    for key in ("aug_path", "path", "augment_path"):
        if req.get(key) not in (None, ""):
            parts.append("path=" + _norm_augment_text(req.get(key)))
            break
    for key in ("aug_rank", "rank", "augment_rank"):
        if req.get(key) not in (None, ""):
            parts.append("rank=" + str(req.get(key)))
            break
    for key in ("aug_trial", "trial", "trial_id"):
        if req.get(key) not in (None, ""):
            parts.append("trial=" + str(req.get(key)))
            break
    parts = sorted(x for x in parts if x)
    raw = "\n".join(parts) or "augment-required-unspecified"
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()[:16]


def _augment_phrases(details: Any) -> list[str]:
    rows = details if isinstance(details, list) else ([details] if details else [])
    out: list[str] = []
    for row in rows:
        for part in re.split(r"[,;/]+", str(row)):
            part = part.strip()
            if part and part.casefold() not in {"augment", "augments"}:
                out.append(part)
    return out


def _augment_row_matches(req: dict[str, Any], row: dict[str, Any]) -> bool:
    decoded = [str(v) for v in row.get("decoded_augments") or [] if str(v).strip()]
    if not decoded:
        return False
    phrases = _augment_phrases(req.get("details") or req.get("required_augments") or [])
    # Structured fields, when present, are exact target semantics and are checked
    # independently of free-text detail phrases.
    for keys, label in ((("aug_path", "path", "augment_path"), "path"), (("aug_rank", "rank", "augment_rank"), "rank"), (("aug_trial", "trial", "trial_id"), "trial")):
        wanted = next((req.get(k) for k in keys if req.get(k) not in (None, "")), None)
        if wanted is not None:
            needle = _norm_augment_text(f"{label} {wanted}")
            if not any(_norm_augment_text(v) == needle for v in decoded):
                return False
    if not phrases:
        return any(req.get(k) not in (None, "") for k in ("aug_path", "path", "augment_path", "aug_rank", "rank", "augment_rank", "aug_trial", "trial", "trial_id"))
    decoded_norm = [_norm_augment_text(v) for v in decoded]
    decoded_words = [_words(v) for v in decoded]
    for phrase in phrases:
        norm = _norm_augment_text(phrase)
        if not norm:
            continue
        # Any signed/numeric target is not allowed to lose its sign/value through
        # tokenization. Historical LAC matching used exact augment strings.
        if re.search(r"[+-]\s*\d|\d", norm):
            if not any(norm == actual or norm in actual for actual in decoded_norm):
                return False
            continue
        required = _words(norm)
        if required and not any(required.issubset(words) for words in decoded_words):
            return False
    return True


def _evaluate_requirement(req: dict[str, Any], resources: dict[str, Any]) -> dict[str, Any]:
    kind = str(req["kind"])
    needed = int(req["quantity"])
    availability = resources.get("availability") or {}
    augment_match = None
    if kind == "item":
        item_id = int(req.get("item_id") or 0)
        item_name = str(req.get("item_name") or req.get("name") or req.get("label") or "").strip()
        owned_row = (resources.get("items") or {}).get(str(item_id)) if item_id > 0 else None
        if owned_row is None and item_name:
            owned_row = (resources.get("items_by_name") or {}).get(_norm_item_name(item_name))
        inventory_known = availability.get("inventory", True) is not False
        augment_required = req.get("augment_required") is True
        base_count = int((owned_row or {}).get("count") or 0) if inventory_known else None
        if augment_required and inventory_known:
            # If the base item is absent we can safely report the requirement as
            # missing even when the augment decoder itself is not available.
            if int(base_count or 0) == 0:
                known = True
                owned = 0
                augment_match = False
                source = "gearplaner_bridge"
            else:
                rows = (owned_row or {}).get("locations") or (owned_row or {}).get("rows") or []
                matching = sum(int(r.get("count") or 1) for r in rows if _augment_row_matches(req, r))
                if matching > 0:
                    known = True
                    owned = matching
                    augment_match = True
                    source = "gearcenter_augment_bridge"
                else:
                    # Partial/unknown decoder coverage must never turn an owned
                    # base item into a false "missing augment" assertion.
                    known = availability.get("augments") == "complete"
                    owned = 0 if known else None
                    augment_match = False if known else None
                    source = "gearcenter_augment_bridge" if known else "augment_verification_pending"
        else:
            known = inventory_known
            owned = int(base_count or 0) if known else None
            source = "gearplaner_bridge"
        label = req.get("label") or item_name or (owned_row or {}).get("name") or (f"Item {item_id}" if item_id else "Item")
        key = f"item:{item_id}" if item_id > 0 else f"item-name:{_norm_item_name(item_name)}"
        aug_sig = _augment_requirement_signature(req)
        if aug_sig:
            key += f"#aug:{aug_sig}"
    else:
        currency_key = str(req["key"])
        row = (resources.get("currencies") or {}).get(currency_key)
        known = availability.get("currencies", True) is not False and row is not None and row.get("known") is True
        owned = int((row or {}).get("value") or 0) if known else None
        label = req.get("label") or currency_key
        source = (row or {}).get("source") or "unknown"
        key = f"currency:{currency_key}"

    missing = None if not known else max(0, needed - int(owned or 0))
    ratio = None if not known else min(1.0, float(owned or 0) / float(needed))
    return {**req, "resource_key": key, "label": label, "needed": needed, "owned": owned, "missing": missing,
            "known": known, "ready": known and missing == 0, "coverage": ratio, "source": source,
            "augment_match": augment_match}


def evaluate_task(task: dict[str, Any], resources: dict[str, Any], goal: dict[str, Any] | None = None) -> dict[str, Any]:
    evaluated = [_evaluate_requirement(req, resources) for req in task.get("requirements") or []]
    unknown = [r for r in evaluated if not r["known"]]
    missing = [r for r in evaluated if r["known"] and int(r["missing"] or 0) > 0]
    if unknown:
        status = "unknown"
    elif missing:
        status = "farm"
    else:
        status = "ready"

    known_rows = [r for r in evaluated if r["coverage"] is not None]
    readiness = None
    if known_rows:
        readiness = round(sum(float(r["coverage"]) for r in known_rows) / len(known_rows), 4)
    elif not evaluated:
        readiness = 1.0

    is_personal = goal is not None
    base_priority = float(task.get("priority_hint") or 0)
    effective_priority = float(goal.get("priority", base_priority) if goal else base_priority)
    return {
        "id": str(task["id"]),
        "title": task.get("title"),
        "description": task.get("description"),
        "category": task.get("category"),
        "job": task.get("job"),
        "source_type": task.get("source_type") or "global_guide",
        "source_ref": task.get("source_ref"),
        "source_meta": task.get("source_meta") or {},
        "set_name": (task.get("source_meta") or {}).get("set_name"),
        "variant_name": (task.get("source_meta") or {}).get("variant_name"),
        "personal_goal": is_personal,
        "personal_note": goal.get("note") if goal else None,
        "priority": effective_priority,
        "status": status,
        "readiness": readiness,
        "requirements": evaluated,
        "missing_count": len(missing),
        "unknown_count": len(unknown),
        "next_step": task.get("next_step"),
        "location": task.get("location"),
        "prerequisites": task.get("prerequisites") or [],
        "unlocks": task.get("unlocks") or [],
    }


def build_plan(state: dict[str, Any], requirements: dict[str, Any], goals: dict[str, Any]) -> dict[str, Any]:
    resources = build_resource_index(state)
    goals_by_task = _goal_map(goals)
    evaluated = [
        evaluate_task(task, resources, goals_by_task.get(str(task["id"])))
        for task in requirements.get("tasks") or []
    ]
    evaluated.sort(key=lambda row: (
        0 if row["personal_goal"] else 1,
        0 if row["status"] == "ready" else 1 if row["status"] == "farm" else 2,
        -float(row["priority"]),
        str(row["title"] or "").lower(),
    ))

    known_task_ids = {row["id"] for row in evaluated}
    orphan_goals = [
        {**goal, "reason": "task-not-in-requirements-catalog"}
        for goal in goals.get("goals") or []
        if goal.get("enabled", True) is not False and str(goal.get("task_id")) not in known_task_ids
    ]

    personal = [row for row in evaluated if row["personal_goal"]]
    guide = [row for row in evaluated if not row["personal_goal"]]
    return {
        "schema": "gearplaner.resource-plan/0.6",
        "character": state.get("character") or {},
        "personal": personal,
        "guide": guide,
        "orphan_personal_goals": orphan_goals,
        "counts": {
            "personal": len(personal),
            "guide": len(guide),
            "ready": sum(1 for row in evaluated if row["status"] == "ready"),
            "farm": sum(1 for row in evaluated if row["status"] == "farm"),
            "unknown": sum(1 for row in evaluated if row["status"] == "unknown"),
        },
        "rules": {
            "global_guide_is_not_personal_goal": True,
            "personal_goal_requires_explicit_goal_entry": True,
            "unknown_currency_never_counts_as_owned": True,
            "augment_base_item_is_not_exact_augment_proof": True,
            "partial_augment_decoder_never_creates_false_missing": True,
        },
    }


def aggregate_deficits(plan: dict[str, Any]) -> dict[str, Any]:
    def aggregate(rows: list[dict[str, Any]]) -> list[dict[str, Any]]:
        buckets: dict[str, dict[str, Any]] = {}
        for task in rows:
            for req in task.get("requirements") or []:
                missing = req.get("missing")
                if missing is None or int(missing) <= 0:
                    continue
                key = str(req["resource_key"])
                bucket = buckets.setdefault(key, {
                    "resource_key": key,
                    "kind": req.get("kind"),
                    "label": req.get("label"),
                    "sum_missing": 0,
                    "max_single_task_missing": 0,
                    "tasks": [],
                })
                miss = int(missing)
                bucket["sum_missing"] += miss
                bucket["max_single_task_missing"] = max(bucket["max_single_task_missing"], miss)
                bucket["tasks"].append({"id": task.get("id"), "title": task.get("title"), "missing": miss})
        return sorted(buckets.values(), key=lambda row: (-int(row["sum_missing"]), str(row["label"] or "").lower()))

    return {
        "schema": "gearplaner.resource-deficits/0.6",
        "personal": aggregate(plan.get("personal") or []),
        "guide": aggregate(plan.get("guide") or []),
        "note": "sum_missing assumes selected tasks are all pursued; max_single_task_missing avoids implying shared resources must always be summed.",
    }


def save_goals(path: str | Path, goals: dict[str, Any]) -> None:
    """Validate and atomically persist explicit personal goals."""
    p = Path(path).expanduser()
    # Reuse schema validation before writing anything.
    if goals.get("schema") != GOALS_SCHEMA:
        raise PlannerDataError(f"Unsupported goals schema: {goals.get('schema')!r}")
    rows = goals.get("goals")
    if not isinstance(rows, list):
        raise PlannerDataError("goals.goals must be an array")
    seen: set[str] = set()
    for idx, goal in enumerate(rows):
        if not isinstance(goal, dict):
            raise PlannerDataError(f"goals[{idx}] must be an object")
        task_id = str(goal.get("task_id") or "").strip()
        if not task_id:
            raise PlannerDataError(f"goals[{idx}] missing task_id")
        if task_id in seen:
            raise PlannerDataError(f"Duplicate personal goal task_id: {task_id}")
        seen.add(task_id)
        if "priority" in goal and not isinstance(goal["priority"], (int, float)):
            raise PlannerDataError(f"goals[{idx}].priority must be numeric")
    p.parent.mkdir(parents=True, exist_ok=True)
    tmp = p.with_name(p.name + ".tmp")
    backup = p.with_name(p.name + ".bak")
    payload = json.dumps(goals, ensure_ascii=False, indent=2) + "\n"
    if p.is_file():
        try:
            backup.write_bytes(p.read_bytes())
        except OSError as exc:
            raise PlannerDataError(f"Could not back up goals file {p}: {exc}") from exc
    try:
        tmp.write_text(payload, encoding="utf-8")
        tmp.replace(p)
    except OSError as exc:
        try:
            tmp.unlink(missing_ok=True)
        except OSError:
            pass
        raise PlannerDataError(f"Could not save goals file {p}: {exc}") from exc


def set_personal_goal(goals: dict[str, Any], task_id: str, *, priority: float | None = None, note: str | None = None) -> dict[str, Any]:
    task_id = str(task_id or "").strip()
    if not task_id:
        raise PlannerDataError("task_id is required")
    if priority is not None and not isinstance(priority, (int, float)):
        raise PlannerDataError("priority must be numeric")
    clean_note = None if note is None else str(note).strip()
    if clean_note is not None and len(clean_note) > 500:
        raise PlannerDataError("note must be <= 500 characters")
    out = {"schema": GOALS_SCHEMA, "goals": [dict(row) for row in goals.get("goals") or [] if str(row.get("task_id")) != task_id]}
    row: dict[str, Any] = {"task_id": task_id, "enabled": True}
    if priority is not None:
        row["priority"] = priority
    if clean_note:
        row["note"] = clean_note
    out["goals"].append(row)
    return out


def remove_personal_goal(goals: dict[str, Any], task_id: str) -> dict[str, Any]:
    task_id = str(task_id or "").strip()
    if not task_id:
        raise PlannerDataError("task_id is required")
    return {
        "schema": GOALS_SCHEMA,
        "goals": [dict(row) for row in goals.get("goals") or [] if str(row.get("task_id")) != task_id],
    }
