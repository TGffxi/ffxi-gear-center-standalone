from __future__ import annotations

import copy
import json
import sqlite3
import time
from collections import Counter, defaultdict
from pathlib import Path
from typing import Any

SCHEMA = "gearplaner.character-state/0.6"
LEGACY_SCHEMAS = {"gearplaner.character-state/0.5"}


class SnapshotError(ValueError):
    pass


class SnapshotUnavailableError(SnapshotError):
    """No configured snapshot exists yet (valid PRE-LIVE state)."""
    pass


def _decode_ashita_cp932(value: Any) -> Any:
    """Transcode byte-preserving JSON strings emitted by Ashita resources.

    Ashita can place CP932 resource bytes in an otherwise ASCII JSON envelope.
    The live-tested 0.8 cleanup hotfix parsed that envelope one-to-one through
    latin-1 and then transcoded string values recursively.  Keep that proven
    compatibility path, but reject genuinely malformed CP932 instead of silently
    preserving undecodable byte strings.  Real Unicode produced through JSON unicode escapes is already decoded by
    ``json.loads`` and is left intact.
    """
    if isinstance(value, str):
        if any(ord(ch) > 0xFF for ch in value):
            return value
        return value.encode("latin-1").decode("cp932")
    if isinstance(value, list):
        return [_decode_ashita_cp932(item) for item in value]
    if isinstance(value, dict):
        return {
            _decode_ashita_cp932(key): _decode_ashita_cp932(item)
            for key, item in value.items()
        }
    return value


def _load_json(path: str | Path) -> dict[str, Any]:
    p = Path(path).expanduser()
    try:
        raw = p.read_bytes()
    except FileNotFoundError as exc:
        raise SnapshotUnavailableError(f"Snapshot not found: {p}") from exc

    try:
        data = json.loads(raw.decode("utf-8"))
    except UnicodeDecodeError as utf8_exc:
        # Historical/live-tested Ashita compatibility: CP932 resource bytes may
        # occur inside an ASCII JSON envelope. Parsing through latin-1 preserves
        # each byte 1:1 so CP932 trail byte 0x5c remains a JSON escape exactly as
        # in the proven 0.8 live-cleanup hotfix.
        try:
            envelope = json.loads(raw.decode("latin-1"))
            data = _decode_ashita_cp932(envelope)
        except (UnicodeDecodeError, UnicodeEncodeError) as cp932_exc:
            bad = raw[utf8_exc.start] if utf8_exc.start < len(raw) else 0
            raise SnapshotError(
                f"Snapshot is neither valid UTF-8 nor valid Ashita CP932: {p}: "
                f"UTF-8 byte 0x{bad:02x} at position {utf8_exc.start}; "
                f"CP932 decode failed: {cp932_exc}"
            ) from cp932_exc
        except json.JSONDecodeError as exc:
            raise SnapshotError(f"Invalid JSON in Ashita CP932 snapshot {p}: {exc}") from exc
    except json.JSONDecodeError as exc:
        raise SnapshotError(f"Invalid JSON in {p}: {exc}") from exc

    if not isinstance(data, dict):
        raise SnapshotError("Snapshot root must be an object")
    return data


def normalize_snapshot(data: dict[str, Any]) -> dict[str, Any]:
    """Normalize native 0.6 and legacy 0.5 snapshots to the 0.6 contract.

    0.6 intentionally accepts the last 0.5 bridge snapshot so the web app can
    come up cleanly before the consolidated in-game test has produced its first
    native 0.6 file.
    """
    schema = data.get("schema")
    if schema not in {SCHEMA, *LEGACY_SCHEMAS}:
        raise SnapshotError(f"Unsupported schema: {schema!r}")
    out = copy.deepcopy(data)
    out["source_schema"] = schema
    out["schema"] = SCHEMA
    out.setdefault("bridge_version", "legacy")
    out.setdefault("exported_at_unix", 0)
    out.setdefault("exported_reason", "unknown")
    out.setdefault("character", {})
    out.setdefault("containers", [])
    out.setdefault("items", [])
    out.setdefault("equipped", [])
    out.setdefault("key_items", [])
    out.setdefault("currencies", {})
    out.setdefault("progression", {})
    out.setdefault("events", {})
    out.setdefault("raw", {})
    out.setdefault("capabilities", {})
    out.setdefault("provenance", {})
    out["provenance"].setdefault("currencies", {})
    out["provenance"].setdefault("progression", {})

    raw = out.get("raw") or {}
    capture = out.setdefault("capture", {})
    capture.setdefault("sequence", 0)
    capture.setdefault("reason", out.get("exported_reason") or "unknown")
    capture.setdefault("exported_at_unix", int(out.get("exported_at_unix") or 0))
    capture.setdefault("currency_0x113_seen", bool(raw.get("currency_0x113_hex")))
    capture.setdefault("currency_0x118_seen", bool(raw.get("currency_0x118_hex")))
    capture.setdefault("conquest_0x05e_seen", bool(raw.get("conquest_0x05E_hex")))
    capture.setdefault("container_ids", sorted({int(c.get("id")) for c in out.get("containers") or [] if c.get("id") is not None}))
    capture.setdefault("physical_stack_count", len(out.get("items") or []))
    capture.setdefault("equipped_slot_count", len(out.get("equipped") or []))

    availability = out.setdefault("_availability", {})
    availability.setdefault("inventory", True)
    availability.setdefault("key_items", bool(out.get("key_items")) or bool((out.get("capabilities") or {}).get("key_items")))
    # Packet-derived currencies are only globally available after at least one
    # currency packet has been observed. Individual getter fallbacks still stay
    # in the map with their own provenance.
    availability.setdefault("currencies", bool(capture.get("currency_0x113_seen") or capture.get("currency_0x118_seen") or out.get("currencies")))
    availability.setdefault("augments", False)

    for key in out.get("currencies") or {}:
        out["provenance"]["currencies"].setdefault(str(key), "gearplaner_bridge")
    for key in out.get("progression") or {}:
        out["provenance"]["progression"].setdefault(str(key), "gearplaner_bridge")

    out.setdefault("sources", {})["gearplaner_bridge"] = {
        "version": out.get("bridge_version"),
        "schema": schema,
        "exported_at_unix": out.get("exported_at_unix"),
        "authoritative_for": ["items", "containers", "equipped", "key_items", "gil", "packet_currencies"],
    }
    return out



def enrich_item_names(state: dict[str, Any], database_path: str | Path) -> tuple[dict[str, Any], dict[str, Any]]:
    """Overlay stable English item names without changing live ownership data.

    This is the proven 0.8 live-cleanup/English-items behavior: the SQLite
    ``item_definitions`` table owns display-name metadata only. Physical count,
    container/slot, extra bytes and augment identity remain owned by live data.
    """
    path = Path(database_path).expanduser()
    item_ids = {int(row.get("item_id") or 0) for row in state.get("items") or []}
    item_ids.discard(0)
    status: dict[str, Any] = {
        "available": False,
        "path": str(path),
        "total_unique_ids": len(item_ids),
        "mapped_unique_ids": 0,
        "missing_unique_ids": len(item_ids),
    }
    if not path.is_file():
        status["error"] = "item catalog database not found"
        state["item_name_catalog"] = status
        return state, status

    try:
        connection = sqlite3.connect(f"file:{path}?mode=ro", uri=True)
        try:
            names = {
                int(item_id): str(name).strip()
                for item_id, name in connection.execute(
                    "SELECT item_id,name FROM item_definitions "
                    "WHERE name IS NOT NULL AND trim(name) <> ''"
                )
            }
        finally:
            connection.close()
    except (OSError, sqlite3.Error) as exc:
        status["error"] = str(exc)
        state["item_name_catalog"] = status
        return state, status

    mapped_ids: set[int] = set()
    for row in state.get("items") or []:
        item_id = int(row.get("item_id") or 0)
        catalog_name = names.get(item_id)
        if not catalog_name:
            continue
        native_name = row.get("name")
        if native_name and native_name != catalog_name:
            row["native_name"] = native_name
        row["name"] = catalog_name
        row["name_source"] = "gear-center:item_definitions"
        mapped_ids.add(item_id)

    def has_japanese_text(value: Any) -> bool:
        return any(
            "\u3040" <= ch <= "\u30ff" or "\u3400" <= ch <= "\u9fff"
            for ch in str(value or "")
        )

    resolved_ids = {
        int(row.get("item_id") or 0)
        for row in state.get("items") or []
        if row.get("name") and not has_japanese_text(row.get("name"))
    }
    resolved_ids.discard(0)
    status.update({
        "available": True,
        "catalog_mapped_unique_ids": len(mapped_ids),
        "live_name_unique_ids": len(resolved_ids - mapped_ids),
        "mapped_unique_ids": len(resolved_ids),
        "missing_unique_ids": len(item_ids - resolved_ids),
    })
    state["item_name_catalog"] = status
    state.setdefault("sources", {})["item_name_catalog"] = {
        "path": str(path),
        "authoritative_for": ["item_names"],
        "not_authoritative_for": ["ownership", "counts", "locations", "augments"],
    }
    return state, status

def load_snapshot(path: str | Path) -> dict[str, Any]:
    data = normalize_snapshot(_load_json(path))
    validate_snapshot(data)
    return data


def load_best_snapshot(native_path: str | Path, legacy_path: str | Path | None = None) -> tuple[dict[str, Any], Path]:
    """Prefer native 0.6; use legacy only when native is genuinely absent.

    A present-but-corrupt native file is a data-source failure and must not be
    hidden by a stale legacy file or converted to PRE-LIVE.
    """
    native = Path(native_path).expanduser()
    legacy = Path(legacy_path).expanduser() if legacy_path else None
    native_missing_message: str | None = None
    try:
        return load_snapshot(native), native
    except SnapshotUnavailableError as exc:
        native_missing_message = str(exc)
        if legacy is None:
            raise
    # Any other SnapshotError from native deliberately propagates.
    try:
        return load_snapshot(legacy), legacy
    except SnapshotUnavailableError as exc:
        raise SnapshotUnavailableError(f"{native_missing_message}; {exc}") from exc


def validate_snapshot(data: dict[str, Any]) -> None:
    if data.get("schema") != SCHEMA:
        raise SnapshotError(f"Snapshot was not normalized to {SCHEMA}")
    for key in ("items", "containers", "key_items", "currencies", "progression"):
        if key not in data:
            raise SnapshotError(f"Missing required field: {key}")
    if not isinstance(data["items"], list):
        raise SnapshotError("items must be an array")
    if not isinstance(data["containers"], list):
        raise SnapshotError("containers must be an array")
    if not isinstance(data["key_items"], list):
        raise SnapshotError("key_items must be an array")
    if not isinstance(data["currencies"], dict):
        raise SnapshotError("currencies must be an object")
    if not isinstance(data["progression"], dict):
        raise SnapshotError("progression must be an object")

    seen_locations: set[tuple[int, int]] = set()
    for i, item in enumerate(data["items"]):
        if not isinstance(item, dict):
            raise SnapshotError(f"items[{i}] must be an object")
        for key in ("item_id", "count", "container_id", "slot"):
            if key not in item:
                raise SnapshotError(f"items[{i}] missing {key}")
        if int(item["item_id"]) <= 0:
            raise SnapshotError(f"items[{i}].item_id must be > 0")
        if int(item["count"]) <= 0:
            raise SnapshotError(f"items[{i}].count must be > 0")
        loc = (int(item["container_id"]), int(item["slot"]))
        if loc in seen_locations:
            raise SnapshotError(f"Duplicate physical location: {loc}")
        seen_locations.add(loc)

    seen_key_items: set[int] = set()
    for i, row in enumerate(data.get("key_items") or []):
        if not isinstance(row, dict):
            raise SnapshotError(f"key_items[{i}] must be an object")
        if "key_item_id" not in row:
            raise SnapshotError(f"key_items[{i}] missing key_item_id")
        kid = int(row["key_item_id"])
        if kid < 0:
            raise SnapshotError(f"key_items[{i}].key_item_id must be >= 0")
        if kid in seen_key_items:
            raise SnapshotError(f"Duplicate key item id: {kid}")
        seen_key_items.add(kid)
        if row.get("owned") is not True:
            raise SnapshotError(f"key_items[{i}].owned must be true")


def _set_with_source(target: dict[str, Any], key: str, value: Any, provenance: dict[str, Any], source: str) -> None:
    if value is None:
        return
    target[key] = value
    provenance[key] = source


def merge_points_state(snapshot: dict[str, Any], points_state: dict[str, Any] | None, *, max_lag_seconds: int = 90) -> dict[str, Any]:
    """Overlay Points only for values it already owns and only when it is safe.

    GearplanerBridge remains authoritative for physical inventory and packet
    currencies. Points is authoritative for Sparks/Accolades and progression
    counters only when character identity and freshness checks pass.
    """
    out = copy.deepcopy(snapshot)
    out.setdefault("provenance", {})
    currency_prov = out["provenance"].setdefault("currencies", {})
    progression_prov = out["provenance"].setdefault("progression", {})

    if not points_state or points_state.get("ready") is not True:
        out["points_overlay"] = {"active": False, "reason": "missing-or-not-ready"}
        return out

    snapshot_name = str((snapshot.get("character") or {}).get("name") or "").strip().casefold()
    points_name = str((points_state.get("character") or {}).get("name") or "").strip().casefold()
    if snapshot_name and points_name and snapshot_name != points_name:
        out["points_overlay"] = {"active": False, "reason": "character-mismatch", "points_character": points_name}
        return out

    snapshot_ts = int(snapshot.get("exported_at_unix") or 0)
    points_ts = int(points_state.get("exported_at_unix") or 0)
    if snapshot_ts and points_ts and points_ts < snapshot_ts - max_lag_seconds:
        out["points_overlay"] = {"active": False, "reason": "points-state-stale", "lag_seconds": snapshot_ts - points_ts}
        return out

    default = points_state.get("default") or {}
    currencies = out.setdefault("currencies", {})
    progression = out.setdefault("progression", {})

    _set_with_source(currencies, "sparks", default.get("sparks"), currency_prov, "points")
    _set_with_source(currencies, "accolades", default.get("accolades"), currency_prov, "points")

    exp = default.get("exp") or {}
    limit = default.get("limit") or {}
    capacity = default.get("capacity") or {}
    mastery = default.get("mastery") or {}
    _set_with_source(progression, "exp_current", exp.get("curr"), progression_prov, "points")
    _set_with_source(progression, "exp_needed", exp.get("max"), progression_prov, "points")
    _set_with_source(progression, "limit_points", limit.get("curr"), progression_prov, "points")
    _set_with_source(progression, "merit_points", limit.get("points"), progression_prov, "points")
    _set_with_source(progression, "merit_points_max", limit.get("maxpoints"), progression_prov, "points")
    _set_with_source(progression, "capacity_points", capacity.get("curr"), progression_prov, "points")
    _set_with_source(progression, "job_points", capacity.get("points"), progression_prov, "points")
    _set_with_source(progression, "mastery_exp", mastery.get("curr"), progression_prov, "points")
    _set_with_source(progression, "mastery_exp_needed", mastery.get("max"), progression_prov, "points")
    _set_with_source(progression, "master_breaker", default.get("mBreaker"), progression_prov, "points")

    rate_keys = (
        "xpChain", "xpTimer", "estXpHour", "estMpHour",
        "cpChain", "cpTimer", "estCpHour", "estJpHour",
        "epChain", "epTimer", "estEpHour",
    )
    progression["rates"] = {k: default.get(k) for k in rate_keys if default.get(k) is not None}
    progression_prov["rates"] = "points"

    out["events"] = copy.deepcopy(points_state.get("events") or {})
    out["provenance"]["events"] = "points"
    out.setdefault("sources", {})["points"] = {
        **copy.deepcopy(points_state.get("source") or {}),
        "exported_at_unix": points_state.get("exported_at_unix"),
        "authoritative_for": ["sparks", "accolades", "xp", "merits", "capacity", "job_points", "mastery", "rates", "events"],
    }
    out["points_overlay"] = {"active": True, "schema": points_state.get("schema")}
    out.setdefault("capabilities", {})["points_overlay"] = True
    out["capabilities"]["points_events"] = True
    return out


def owned_totals(data: dict[str, Any]) -> dict[int, int]:
    totals: Counter[int] = Counter()
    for item in data.get("items", []):
        totals[int(item["item_id"])] += int(item.get("count", 0))
    return dict(totals)


def owned_locations(data: dict[str, Any]) -> dict[int, list[dict[str, Any]]]:
    out: defaultdict[int, list[dict[str, Any]]] = defaultdict(list)
    for item in data.get("items", []):
        out[int(item["item_id"])].append({
            "container_id": int(item["container_id"]),
            "container_name": item.get("container_name"),
            "slot": int(item["slot"]),
            "count": int(item.get("count", 0)),
            "equipped_slots": item.get("equipped_slots") or [],
            "extra_hex": item.get("extra_hex"),
            "decoded_augments": item.get("decoded_augments") or [],
            "augment_source": item.get("augment_source"),
        })
    return dict(out)


def v04_gear_compat(data: dict[str, Any]) -> list[dict[str, Any]]:
    rows = []
    for item in data.get("items", []):
        rows.append({
            "id": item.get("item_id"),
            "name": item.get("name"),
            "count": item.get("count", 0),
            "bag": item.get("container_id"),
            "bag_name": item.get("container_name"),
            "slot": item.get("slot"),
            "flags": item.get("flags"),
            "extra_hex": item.get("extra_hex"),
            "equipped_slots": item.get("equipped_slots") or [],
            "decoded_augments": item.get("decoded_augments") or [],
        })
    return rows


def summary(data: dict[str, Any], currency_catalog: dict[str, Any] | None = None) -> dict[str, Any]:
    totals = owned_totals(data)
    catalog_keys: set[str] = set()
    for fields in (currency_catalog or {}).get("packets", {}).values():
        for field in fields or []:
            if field.get("key"):
                catalog_keys.add(str(field["key"]))
    known_currency_keys = {str(k) for k, v in (data.get("currencies") or {}).items() if v is not None}
    packet_known = known_currency_keys & catalog_keys
    now = int(time.time())
    ts = int(data.get("exported_at_unix") or 0)
    return {
        "schema": data.get("schema"),
        "source_schema": data.get("source_schema"),
        "character": data.get("character") or {},
        "physical_stacks": len(data.get("items", [])),
        "unique_item_ids": len(totals),
        "total_item_quantity": sum(totals.values()),
        "container_count": len(data.get("containers") or []),
        "containers": data.get("containers") or [],
        "currency_fields_known": len(packet_known),
        "currency_fields_total": len(catalog_keys),
        "key_items": data.get("key_items") or [],
        "currencies": data.get("currencies") or {},
        "progression": data.get("progression") or {},
        "events": data.get("events") or {},
        "sources": data.get("sources") or {},
        "provenance": data.get("provenance") or {},
        "capabilities": data.get("capabilities") or {},
        "points_overlay": data.get("points_overlay") or {},
        "capture": data.get("capture") or {},
        "snapshot_age_seconds": max(0, now - ts) if ts else None,
        "availability": data.get("_availability") or {},
    }
