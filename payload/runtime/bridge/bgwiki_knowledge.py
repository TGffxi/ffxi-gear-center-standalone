from __future__ import annotations

import json
import re
import sqlite3
from pathlib import Path
from typing import Any

_ACQ_WORDS = (
    "obtained", "purchased", "redeemed", "synthesis", "synergy", "quest",
    "reward", "drop", "dropped", "acquisition", "crafted", "craft",
)
_PROG_WORDS = ("augmented", "upgrade", "trial", "reforge", "progression")

_SECTION_LABELS = {
    "obtained from": "Erhältlich durch",
    "obtained from instance": "Erhältlich aus Instanz",
    "purchased from": "Kaufbar bei",
    "redeemed from": "Eintausch bei",
    "dropped from": "Drop von",
    "synthesis": "Synthese",
    "synthesis information": "Synthese",
    "synergy": "Synergie",
    "synergy information": "Synergie",
    "quest": "Quest",
    "augmented via": "Augment via",
    "upgraded from": "Upgrade von",
    "used in": "Verwendet für",
    "upgrade info": "Upgrade-Information",
}

def _section_label(section: str) -> str:
    raw = section.strip() or "BG-Wiki"
    low = raw.casefold().rstrip(".:")
    if low in _SECTION_LABELS:
        return _SECTION_LABELS[low]
    for key, label in _SECTION_LABELS.items():
        if low.startswith(key):
            return label
    return raw


def _row_dict(row: sqlite3.Row | None) -> dict[str, Any] | None:
    return dict(row) if row is not None else None


def _connect(path: Path) -> sqlite3.Connection | None:
    if not path.is_file():
        return None
    try:
        uri = f"file:{path.resolve()}?mode=ro"
        con = sqlite3.connect(uri, uri=True, timeout=1.0)
        con.row_factory = sqlite3.Row
        return con
    except sqlite3.Error:
        return None


def _page_for_name(con: sqlite3.Connection, name: str) -> dict[str, Any] | None:
    q = """
      SELECT m.item_name,m.item_name_norm,m.page_title,
             p.canonical_url,p.raw_excerpt,p.fetched_at,p.revision_id,p.revision_timestamp
      FROM bgwiki_item_map m
      JOIN bgwiki_pages p ON lower(p.page_title)=lower(m.page_title)
      WHERE lower(m.item_name)=lower(?) OR lower(m.page_title)=lower(?)
      ORDER BY CASE WHEN lower(m.item_name)=lower(?) THEN 0 ELSE 1 END
      LIMIT 1
    """
    try:
        row = con.execute(q, (name, name, name)).fetchone()
    except sqlite3.Error:
        return None
    if row:
        return _row_dict(row)
    try:
        row = con.execute(
            "SELECT page_title,canonical_url,raw_excerpt,fetched_at,revision_id,revision_timestamp FROM bgwiki_pages WHERE lower(page_title)=lower(?) AND exists_flag=1 LIMIT 1",
            (name,),
        ).fetchone()
    except sqlite3.Error:
        return None
    return _row_dict(row)


def _clean(value: Any) -> str:
    s = str(value or "").replace("Â", " ")
    s = re.sub(r"\s+", " ", s).strip()
    return s


def _route_text(section: str, fields: dict[str, Any]) -> str:
    # Favor actor/location/cost/notes. Field names differ across BG-Wiki tables.
    priority = [
        "Battle Name", "Monster", "NM", "NPC Name", "NPC", "Quest", "Event",
        "Craft Requirements", "Yield", "Zone", "Location", "Rate", "Price", "Cost",
        "Entry Item(s)", "Required Items", "Required Item", "Materials", "Notes",
    ]
    parts: list[str] = []
    seen: set[str] = set()
    for key in priority:
        for fk, fv in fields.items():
            if fk.casefold() == key.casefold():
                text = _clean(fv)
                if text and text.casefold() not in seen:
                    seen.add(text.casefold()); parts.append(text)
                break
        if len(parts) >= 3:
            break
    if not parts:
        for fv in fields.values():
            text = _clean(fv)
            if text and text.casefold() not in seen:
                seen.add(text.casefold()); parts.append(text)
            if len(parts) >= 3:
                break
    label = _section_label(section)
    return f"{label}: " + " · ".join(parts) if parts else label


def _excerpt_hint(excerpt: str) -> str:
    text = _clean(excerpt)
    if not text:
        return ""
    markers = [
        "Obtained From", "Obtained from", "Purchased From", "Redeemed From",
        "Synthesis Information", "Synergy Information", "Quest", "Augmented Via",
    ]
    start = None
    for marker in markers:
        i = text.casefold().find(marker.casefold())
        if i >= 0 and (start is None or i < start):
            start = i
    if start is not None:
        text = text[start:]
    return text[:360] + ("…" if len(text) > 360 else "")


def lookup_item(path: Path, name: str, *, max_records: int = 6) -> dict[str, Any]:
    name = str(name or "").strip()
    if not name:
        return {"cached": False}
    con = _connect(path)
    if con is None:
        return {"cached": False}
    try:
        page = _page_for_name(con, name)
        if not page:
            return {"cached": False}
        page_title = str(page.get("page_title") or name)
        try:
            rows = con.execute(
                "SELECT section_name,record_index,source_type,fields_json FROM bgwiki_progression_records WHERE lower(page_title)=lower(?) ORDER BY record_index",
                (page_title,),
            ).fetchall()
        except sqlite3.Error:
            rows = []
        acquisition: list[dict[str, Any]] = []
        progression: list[dict[str, Any]] = []
        for row in rows:
            section = str(row["section_name"] or "")
            try:
                fields = json.loads(str(row["fields_json"] or "{}"))
            except json.JSONDecodeError:
                fields = {}
            if not isinstance(fields, dict):
                fields = {}
            low = section.casefold()
            rec = {
                "section": section,
                "source_type": str(row["source_type"] or ""),
                "summary": _route_text(section, fields),
                "fields": {str(k): _clean(v) for k, v in fields.items() if _clean(v)},
            }
            if any(word in low for word in _ACQ_WORDS):
                acquisition.append(rec)
            elif any(word in low for word in _PROG_WORDS):
                progression.append(rec)
        acquisition = acquisition[:max_records]
        progression = progression[:max_records]
        hint = acquisition[0]["summary"] if acquisition else _excerpt_hint(str(page.get("raw_excerpt") or ""))
        return {
            "cached": True,
            "requested_name": name,
            "page_title": page_title,
            "canonical_url": str(page.get("canonical_url") or ""),
            "fetched_at": page.get("fetched_at"),
            "revision_id": page.get("revision_id"),
            "revision_timestamp": page.get("revision_timestamp"),
            "acquisition_hint": hint,
            "acquisition_records": acquisition,
            "progression_records": progression,
        }
    finally:
        con.close()




def _rebuild_activity_groups(farming: dict[str, Any]) -> None:
    """Rebuild user-facing activity groups after BG-Wiki enrichment.

    The 0.8 planner creates groups before the local BG-Wiki knowledge layer is
    applied.  If a resource originally had only "Unresolved acquisition", its
    resource row is enriched later but the copied group label would otherwise
    remain stale.  Rebuilding here keeps the historical grouped farm workflow
    and the richer cached acquisition route in sync.
    """
    groups: dict[str, dict[str, Any]] = {}
    for row in farming.get("resources") or []:
        if str(row.get("status") or "") == "ready":
            continue
        acts = [str(x).strip() for x in (row.get("activities") or []) if str(x).strip()] or ["Bezugsweg noch ungeklärt"]
        for activity in acts:
            group = groups.setdefault(activity, {
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
            if row.get("status") == "farm":
                group["known_deficit_count"] += 1
            elif row.get("status") == "unknown":
                group["unknown_count"] += 1
            group["max_personal_priority"] = max(
                float(group.get("max_personal_priority") or 0),
                float(row.get("max_personal_priority") or 0),
            )
            gain = (row.get("performance") or {}).get("gain_percent")
            if gain is not None:
                old = group.get("best_performance_gain_percent")
                if old is None or float(gain) > float(old):
                    group["best_performance_gain_percent"] = gain
            for goal in row.get("goals") or []:
                tid = goal.get("task_id")
                if tid and tid not in group["goal_ids"]:
                    group["goal_ids"].append(tid)
    rows = list(groups.values())
    rows.sort(key=lambda g: (
        0 if int(g.get("known_deficit_count") or 0) else 1,
        -float(g.get("max_personal_priority") or 0),
        -float(g.get("best_performance_gain_percent") if g.get("best_performance_gain_percent") is not None else -1e18),
        -int(g.get("resource_count") or 0),
        str(g.get("activity") or "").casefold(),
    ))
    farming["activities"] = rows
    if isinstance(farming.get("counts"), dict):
        farming["counts"]["activity_groups"] = len(rows)

def enrich_farming(path: Path, farming: dict[str, Any]) -> dict[str, Any]:
    cache: dict[str, dict[str, Any]] = {}
    for row in farming.get("resources") or []:
        if str(row.get("kind") or "") != "item":
            continue
        name = str(row.get("label") or row.get("item_name") or "").strip()
        if not name:
            continue
        key = name.casefold()
        info = cache.get(key)
        if info is None:
            info = lookup_item(path, name)
            cache[key] = info
        row["bgwiki"] = info
        if info.get("cached") and info.get("acquisition_hint"):
            acts = [str(x) for x in (row.get("activities") or []) if str(x).strip() and str(x).strip() != "Unresolved acquisition"]
            if not acts:
                acts = [str(info["acquisition_hint"])]
            row["activities"] = acts
            row["activity_hints"] = [str(info["acquisition_hint"])]
    # queue/activity groups contain references copied by build_farming_plan; refresh their
    # entries by resource_key so user-facing hints stay consistent.
    by_key = {str(r.get("resource_key")): r for r in farming.get("resources") or []}
    if isinstance(farming.get("queue"), list):
        farming["queue"] = [by_key.get(str(r.get("resource_key")), r) for r in farming["queue"]]
    # Rebuild activity groups from the enriched rows.  This avoids showing the
    # obsolete 0.8 placeholder "Unresolved acquisition" after a cached BG-Wiki
    # route has already supplied a useful acquisition hint.
    _rebuild_activity_groups(farming)
    return farming
