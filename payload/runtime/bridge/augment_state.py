from __future__ import annotations

import copy
import json
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any


class AugmentStateError(ValueError):
    pass


def _first(row: dict[str, Any], *keys: str) -> Any:
    for key in keys:
        if key in row and row[key] not in (None, ""):
            return row[key]
    return None


def _to_int(value: Any) -> int | None:
    try:
        value = int(value)
    except (TypeError, ValueError):
        return None
    return value if value >= 0 else None


def _norm_hex(value: Any) -> str | None:
    if value in (None, ""):
        return None
    text = "".join(ch for ch in str(value).strip().lower() if ch in "0123456789abcdef")
    return text or None




def _character_name(value: Any) -> str:
    if isinstance(value, dict):
        value = value.get("name") or value.get("Name")
    return str(value or "").strip().casefold()


def _complete_extra(value: Any) -> str | None:
    text = _norm_hex(value)
    # Both collectors model the retail 24-byte Extra field. Short 2/4-char
    # values observed in old exports are explicitly not treated as full identity.
    return text if text is not None and len(text) == 48 else None

def _augments(value: Any) -> list[str]:
    if value is None:
        return []
    if isinstance(value, str):
        value = value.strip()
        return [value] if value else []
    if isinstance(value, list):
        out: list[str] = []
        for entry in value:
            if isinstance(entry, str) and entry.strip():
                out.append(entry.strip())
            elif isinstance(entry, dict):
                text = _first(entry, "text", "augment", "name", "value", "String")
                if text not in (None, ""):
                    out.append(str(text).strip())
        return out
    if isinstance(value, dict):
        out: list[str] = []
        for key, entry in value.items():
            if entry in (None, "", False):
                continue
            if isinstance(entry, (str, int, float)):
                out.append(f"{key}: {entry}")
        return out
    return []


def _dedupe(values: list[str]) -> list[str]:
    out: list[str] = []
    seen: set[str] = set()
    for value in values:
        text = str(value).strip()
        key = text.casefold()
        if text and key not in seen:
            seen.add(key)
            out.append(text)
    return out


# Some rank/path systems expose only Path/Rank in the live packet.  The
# following effects were explicitly part of the live-tested 0.8 augment-display
# v4 hotfix.  Keep the catalog intentionally narrow: values are added only when
# item identity, path and rank match exactly; live decoded strings remain intact.
_KNOWN_RANKED_EFFECTS: dict[tuple[int, str, int], tuple[str, ...]] = {
    (21906, "A", 15): (
        "DMG +8",
        "Blade: Metsu: Damage +20%",
        "Ninjutsu casting time -20%",
    ),
}


def _known_ranked_effects(item_id: int | None, path: Any, rank: int | None) -> list[str]:
    if item_id is None or path in (None, "") or rank is None:
        return []
    return list(_KNOWN_RANKED_EFFECTS.get((item_id, str(path).strip().upper(), rank), ()))


def _rows_from_root(data: dict[str, Any]) -> list[dict[str, Any]]:
    for key in ("items", "equipment", "rows", "gear", "augments"):
        value = data.get(key)
        if isinstance(value, list):
            return [row for row in value if isinstance(row, dict)]
    for key in ("equipment", "gear"):
        value = data.get(key)
        if isinstance(value, dict):
            rows: list[dict[str, Any]] = []
            for slot_name, row in value.items():
                if isinstance(row, dict):
                    rows.append({**row, "equip_slot_name": row.get("equip_slot_name") or slot_name})
            if rows:
                return rows
    return []


def load_augment_state(path: str | Path) -> dict[str, Any]:
    """Load the LuAshitacast/gearcenter augment export without guessing values.

    The live bridge writes item_id, bag_id, bag_slot, raw_extra_hex,
    augment_strings, Path/Rank/Trial metadata. Older field names remain accepted.
    """
    p = Path(path).expanduser()
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise AugmentStateError(f"Augment snapshot not found: {p}") from exc
    except UnicodeDecodeError as exc:
        raise AugmentStateError(f"Augment snapshot is not valid UTF-8: {p}: byte 0x{exc.object[exc.start]:02x} at position {exc.start}") from exc
    except json.JSONDecodeError as exc:
        raise AugmentStateError(f"Invalid augment JSON in {p}: {exc}") from exc
    if not isinstance(data, dict):
        raise AugmentStateError("Augment snapshot root must be an object")

    raw_rows = _rows_from_root(data)
    parsed: list[dict[str, Any]] = []
    for source_index, row in enumerate(raw_rows):
        item_id = _to_int(_first(row, "item_id", "id", "ItemId", "itemId"))
        name = _first(row, "name", "item_name", "Name")
        container_id = _to_int(_first(row, "container_id", "bag", "bag_id", "container", "containerId"))
        slot = _to_int(_first(row, "slot", "bag_slot", "inventory_slot", "slot_id"))
        extra_hex = _norm_hex(_first(row, "extra_hex", "raw_extra_hex", "extra", "extra_data_hex", "ExtraHex"))

        augments = _augments(_first(
            row,
            "decoded_augments",
            "augment_strings",
            "augments",
            "augment",
            "augment_text",
            "Augments",
        ))
        path_value = _first(row, "path", "augment_path", "Path")
        rank = _to_int(_first(row, "rank", "augment_rank", "Rank"))
        trial = _to_int(_first(row, "trial", "trial_id", "Trial"))
        trial_complete = _first(row, "trial_complete", "trial_completed", "TrialComplete")
        for text in _known_ranked_effects(item_id, path_value, rank):
            augments.append(text)
        if path_value not in (None, ""):
            augments.append(f"Path {str(path_value).strip()}")
        if rank is not None:
            augments.append(f"Rank {rank}")
        if trial is not None:
            augments.append(f"Trial {trial}" + (" (complete)" if trial_complete in (True, 1, "1", "true", "True") else ""))
        augments = _dedupe(augments)
        if item_id is None or not augments:
            continue

        parsed.append({
            "source_index": source_index,
            "item_id": item_id,
            "name": str(name).strip() if name not in (None, "") else None,
            "container_id": container_id,
            "slot": slot,
            "extra_hex": extra_hex,
            "augments": augments,
            "equip_slot_name": _first(row, "equip_slot_name", "equip_slot", "equipment_slot"),
        })

    return {
        "schema": data.get("schema"),
        "version": data.get("version") or data.get("bridge_version"),
        "exported_at_unix": data.get("exported_at_unix") or data.get("timestamp_unix") or data.get("timestamp"),
        "character": data.get("character") or {},
        "rows": parsed,
        "raw_row_count": len(raw_rows),
        "parsed_row_count": len(parsed),
        "coverage_complete": data.get("coverage_complete") is True or data.get("complete") is True,
    }


def apply_augment_overlay(state: dict[str, Any], augment_state: dict[str, Any] | None) -> tuple[dict[str, Any], dict[str, Any]]:
    """Overlay decoded augments using one-to-one physical-instance matching.

    A source row is consumed at most once. Item-ID-only fallback is permitted
    only if both snapshots prove that exactly one physical copy exists. This
    prevents one augment record from being copied onto multiple identical items.
    """
    out = copy.deepcopy(state)
    status: dict[str, Any] = {
        "available": augment_state is not None,
        "ready": False,
        "parsed_rows": 0,
        "matched_rows": 0,
        "unmatched_rows": 0,
        "ambiguous_matches": 0,
        "coverage_complete": False,
        "identity_conflicts": 0,
        "identity_reason": None,
    }
    if not augment_state:
        out.setdefault("_availability", {})["augments"] = False
        return out, status

    rows = list(augment_state.get("rows") or [])
    status.update({
        "ready": bool(rows),
        "parsed_rows": len(rows),
        "coverage_complete": augment_state.get("coverage_complete") is True,
        "schema": augment_state.get("schema"),
        "version": augment_state.get("version"),
        "exported_at_unix": augment_state.get("exported_at_unix"),
    })

    state_name = _character_name(state.get("character") or {})
    augment_name = _character_name(augment_state.get("character") or {})
    if state_name and augment_name and state_name != augment_name:
        status.update({"ready": False, "identity_reason": "character-mismatch", "state_character": state_name, "augment_character": augment_name})
        out.setdefault("_availability", {})["augments"] = False
        return out, status
    state_ts = _to_int(state.get("exported_at_unix")) or 0
    augment_ts = _to_int(augment_state.get("exported_at_unix")) or 0
    if state_ts and augment_ts and augment_ts < state_ts - 600:
        status.update({"ready": False, "identity_reason": "augment-snapshot-stale", "state_exported_at_unix": state_ts, "augment_exported_at_unix": augment_ts})
        out.setdefault("_availability", {})["augments"] = False
        return out, status

    by_exact: dict[tuple[int, int, int], list[int]] = defaultdict(list)
    by_extra: dict[tuple[int, str], list[int]] = defaultdict(list)
    by_item: dict[int, list[int]] = defaultdict(list)
    for idx, row in enumerate(rows):
        iid = _to_int(row.get("item_id"))
        if iid is None:
            continue
        by_item[iid].append(idx)
        cid, slot = _to_int(row.get("container_id")), _to_int(row.get("slot"))
        if cid is not None and slot is not None:
            by_exact[(iid, cid, slot)].append(idx)
        extra = _complete_extra(row.get("extra_hex"))
        if extra:
            by_extra[(iid, extra)].append(idx)

    physical_count = Counter(int(item.get("item_id") or 0) for item in out.get("items") or [] if int(item.get("item_id") or 0) > 0)
    used: set[int] = set()
    matched = 0
    ambiguous = 0

    def available(indices: list[int]) -> list[int]:
        return [idx for idx in indices if idx not in used]

    for item in out.get("items") or []:
        iid = int(item.get("item_id") or 0)
        if iid <= 0:
            continue
        chosen: int | None = None

        cid = _to_int(item.get("container_id"))
        slot = _to_int(item.get("slot"))
        if cid is not None and slot is not None:
            candidates = available(by_exact.get((iid, cid, slot), []))
            item_extra = _complete_extra(item.get("extra_hex"))
            if item_extra:
                filtered = []
                for idx in candidates:
                    source_extra = _complete_extra(rows[idx].get("extra_hex"))
                    if source_extra and source_extra != item_extra:
                        status["identity_conflicts"] += 1
                        continue
                    filtered.append(idx)
                candidates = filtered
            if len(candidates) == 1:
                chosen = candidates[0]
            elif len(candidates) > 1:
                ambiguous += 1

        if chosen is None:
            extra = _complete_extra(item.get("extra_hex"))
            if extra:
                candidates = available(by_extra.get((iid, extra), []))
                if len(candidates) == 1:
                    chosen = candidates[0]
                elif len(candidates) > 1:
                    ambiguous += 1

        if chosen is None and physical_count.get(iid, 0) == 1:
            candidates = available(by_item.get(iid, []))
            item_name = str(item.get("name") or "").strip().casefold()
            if item_name:
                candidates = [idx for idx in candidates if not str(rows[idx].get("name") or "").strip() or str(rows[idx].get("name") or "").strip().casefold() == item_name]
            # Item-ID-only fallback is convenience for a proven single physical
            # copy, never permission to override contradictory full Extra data.
            item_extra = _complete_extra(item.get("extra_hex"))
            if item_extra:
                filtered = []
                for idx in candidates:
                    source_extra = _complete_extra(rows[idx].get("extra_hex"))
                    if source_extra and source_extra != item_extra:
                        status["identity_conflicts"] += 1
                        continue
                    filtered.append(idx)
                candidates = filtered
            if len(candidates) == 1:
                chosen = candidates[0]
            elif len(candidates) > 1:
                ambiguous += 1

        if chosen is None:
            continue
        row = rows[chosen]
        augments = _dedupe([str(text) for text in row.get("augments") or []])
        if not augments:
            continue
        used.add(chosen)
        item["decoded_augments"] = augments
        item["augment_source"] = "gearcenter_augment_bridge"
        item["augment_source_index"] = row.get("source_index", chosen)
        matched += 1

    status["matched_rows"] = matched
    status["unmatched_rows"] = max(0, len(rows) - len(used))
    status["ambiguous_matches"] = ambiguous
    out.setdefault("_availability", {})["augments"] = "complete" if status["coverage_complete"] else ("partial" if status["ready"] else False)
    if status["ready"]:
        out.setdefault("sources", {})["gearcenter_augment_bridge"] = {
            "schema": status.get("schema"),
            "version": status.get("version"),
            "exported_at_unix": status.get("exported_at_unix"),
            "parsed_rows": status["parsed_rows"],
            "matched_rows": status["matched_rows"],
            "unmatched_rows": status["unmatched_rows"],
            "ambiguous_matches": status["ambiguous_matches"],
            "coverage_complete": status["coverage_complete"],
            "authoritative_for": ["decoded_augments_when_physical_instance_matched"],
        }
        out.setdefault("capabilities", {})["decoded_augment_overlay"] = True
    return out, status
