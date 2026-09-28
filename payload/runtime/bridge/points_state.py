from __future__ import annotations

import json
from pathlib import Path
from typing import Any

SCHEMA = "gearplaner.points-state/0.5"


class PointsStateError(ValueError):
    pass


def load_points_state(path: str | Path) -> dict[str, Any]:
    p = Path(path).expanduser()
    try:
        data = json.loads(p.read_text(encoding="utf-8"))
    except FileNotFoundError as exc:
        raise PointsStateError(f"Points state not found: {p}") from exc
    except json.JSONDecodeError as exc:
        raise PointsStateError(f"Invalid JSON in {p}: {exc}") from exc
    validate_points_state(data)
    return data


def validate_points_state(data: dict[str, Any]) -> None:
    if not isinstance(data, dict):
        raise PointsStateError("Points state root must be an object")
    if data.get("schema") != SCHEMA:
        raise PointsStateError(f"Unsupported Points state schema: {data.get('schema')!r}")
    for key in ("source", "default", "events"):
        if key not in data or not isinstance(data[key], dict):
            raise PointsStateError(f"Points state missing object: {key}")
    if "ready" not in data:
        raise PointsStateError("Points state missing ready flag")
