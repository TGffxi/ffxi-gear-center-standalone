from __future__ import annotations

import time
from pathlib import Path
from typing import Any

EXPECTED_CONTAINER_IDS = list(range(17))


def _age(ts: Any, now: int) -> int | None:
    try:
        v = int(ts or 0)
    except (TypeError, ValueError):
        return None
    return max(0, now - v) if v > 0 else None


def _freshness(age: int | None, fresh: int, stale: int) -> str:
    if age is None:
        return "unknown"
    if age <= fresh:
        return "fresh"
    if age <= stale:
        return "aging"
    return "stale"


def build_source_health(
    state: dict[str, Any],
    *,
    snapshot_path: Path | None = None,
    snapshot_source: str | None = None,
    points_status: dict[str, Any] | None = None,
    augment_status: dict[str, Any] | None = None,
    currency_catalog: dict[str, Any] | None = None,
    requirements_path: Path | None = None,
    goals_path: Path | None = None,
    pre_live: bool = False,
) -> dict[str, Any]:
    now = int(time.time())
    snapshot_age = _age(state.get("exported_at_unix"), now)
    seen_containers = sorted({int(c.get("id")) for c in state.get("containers") or [] if c.get("id") is not None})
    expected = set(EXPECTED_CONTAINER_IDS)
    seen = set(seen_containers)
    missing_containers = sorted(expected - seen)

    catalog_keys: list[str] = []
    for packet_fields in (currency_catalog or {}).get("packets", {}).values():
        for field in packet_fields or []:
            key = field.get("key")
            if key and str(key) not in catalog_keys:
                catalog_keys.append(str(key))
    currency_map = state.get("currencies") or {}
    known_catalog_keys = [k for k in catalog_keys if currency_map.get(k) is not None]

    capture = state.get("capture") or {}
    points_status = points_status or {}
    augment_status = augment_status or {}
    points_age = _age(points_status.get("exported_at_unix"), now)
    augment_age = _age(augment_status.get("exported_at_unix"), now)

    bridge_ready = not pre_live and bool(state.get("items") is not None)
    packet113 = capture.get("currency_0x113_seen") is True
    packet118 = capture.get("currency_0x118_seen") is True
    currencies_complete_capture = packet113 and packet118
    inventory_coverage = len(seen & expected) / len(expected) if expected else 1.0
    capture_containers = {int(x) for x in capture.get("container_ids") or []}
    physical_stacks = len(state.get("items") or [])
    capture_stack_count = capture.get("physical_stack_count")
    inventory_capture_consistent = (
        capture_containers == expected
        and capture_stack_count is not None
        and int(capture_stack_count) == physical_stacks
    )
    currency_decoder_complete = bool(catalog_keys) and len(known_catalog_keys) == len(catalog_keys)
    points_overlay_active = (state.get("points_overlay") or {}).get("active") is True
    points_overlay_valid = points_status.get("available") is not True or points_overlay_active

    live_checks = {
        "snapshot_present": not pre_live,
        "snapshot_fresh": (snapshot_age is not None and snapshot_age <= 90) if not pre_live else False,
        "character_identified": bool(str((state.get("character") or {}).get("name") or "").strip()) and not pre_live,
        "inventory_collected": not pre_live and inventory_capture_consistent,
        "all_container_records_seen": not pre_live and not missing_containers,
        "currency_packet_0x113_seen": packet113,
        "currency_packet_0x118_seen": packet118,
        "currency_decoder_complete": not pre_live and currency_decoder_complete,
        "points_overlay_valid_or_optional": points_overlay_valid,
        "planning_catalog_present": requirements_path.is_file() if requirements_path else False,
        "goals_store_present": goals_path.is_file() if goals_path else False,
    }
    # The augment bridge remains optional for the unified state itself. It only
    # controls exact augmented-gear proof in the planner.
    live_checks["augment_overlay_optional"] = True

    blocking = [k for k, v in live_checks.items() if not v]
    live_ready = not pre_live and not blocking

    return {
        "schema": "gearplaner.source-health/0.6",
        "mode": "pre_live" if pre_live else "live",
        "generated_at_unix": now,
        "overall": "WARTET AUF FFXI" if pre_live else ("READY" if live_ready else "PARTIAL"),
        "live_ready": live_ready,
        "sources": {
            "gearplaner_bridge": {
                "available": not pre_live,
                "path": str(snapshot_path) if snapshot_path else None,
                "source": snapshot_source,
                "age_seconds": snapshot_age,
                "freshness": _freshness(snapshot_age, 45, 300),
                "bridge_version": state.get("bridge_version"),
                "source_schema": state.get("source_schema"),
            },
            "points": {
                "available": points_status.get("available") is True,
                "ready": points_status.get("ready") is True,
                "age_seconds": points_age,
                "freshness": _freshness(points_age, 90, 300),
                "overlay_active": points_overlay_active,
                "overlay_reason": (state.get("points_overlay") or {}).get("reason"),
            },
            "augment_bridge": {
                "available": augment_status.get("available") is True,
                "ready": augment_status.get("ready") is True,
                "age_seconds": augment_age,
                "freshness": _freshness(augment_age, 120, 600),
                "parsed_rows": augment_status.get("parsed_rows", 0),
                "matched_rows": augment_status.get("matched_rows", 0),
                "coverage_complete": augment_status.get("coverage_complete") is True,
            },
        },
        "inventory": {
            "physical_stacks": physical_stacks,
            "capture_physical_stacks": capture_stack_count,
            "capture_consistent": inventory_capture_consistent,
            "containers_expected": len(expected),
            "containers_seen": len(seen & expected),
            "coverage": round(inventory_coverage, 4),
            "missing_container_ids": missing_containers,
            "equipped_slots": len(state.get("equipped") or []),
        },
        "key_items": {
            "owned_count": len(state.get("key_items") or []),
            "available": bool((state.get("capabilities") or {}).get("key_items")) or bool(state.get("key_items")),
        },
        "currencies": {
            "catalog_fields": len(catalog_keys),
            "known_catalog_fields": len(known_catalog_keys),
            "unknown_catalog_fields": max(0, len(catalog_keys) - len(known_catalog_keys)),
            "packet_0x113_seen": packet113,
            "packet_0x118_seen": packet118,
            "both_packets_seen": currencies_complete_capture,
            "decoder_complete": currency_decoder_complete,
        },
        "checks": live_checks,
        "blocking_checks": blocking,
        "note": "Ohne aktuellen FFXI-Snapshot bleibt der Datenstatus wartend. Unbekannte Werte werden nie als null/0 erfunden.",
    }
