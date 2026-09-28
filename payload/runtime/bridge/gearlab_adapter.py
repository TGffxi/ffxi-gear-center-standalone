from __future__ import annotations

import importlib.util
import json
import os
import re
import statistics
import subprocess
import time
from pathlib import Path
from typing import Any

CACHE_SCHEMA = "gearcenter.performance-cache/1.0"
RELEASE = "1.0.2"

_CAPTURE_KEY_PARTS = ("combat_capture", "gearlab_capture", "capture_", "combat_module")
_CAPTURE_EXACT_KEYS = {"fight_statistics", "observed_skillchain_magic_followups", "tp_snapshot_not_mislabeled_as_tp_spent"}

def _strip_retired_capture(obj: Any) -> Any:
    """Remove metadata for the retired Combat-Capture subsystem.

    Gear Lab remains a read-only theory/optimizer source. Gear Center must not
    re-expose old capture files, paths, feature flags or validation metadata.
    """
    if isinstance(obj, dict):
        out = {}
        for key, value in obj.items():
            low = str(key).casefold().replace("-", "_")
            if low in _CAPTURE_EXACT_KEYS or any(part in low for part in _CAPTURE_KEY_PARTS):
                continue
            out[key] = _strip_retired_capture(value)
        return out
    if isinstance(obj, list):
        return [_strip_retired_capture(v) for v in obj]
    if isinstance(obj, str):
        low = obj.casefold().replace("-", "_")
        if "gearlab_capture" in low or "combat_capture.py" in low:
            return ""
    return obj


def _mtime(path: Path) -> int | None:
    try:
        return int(path.stat().st_mtime)
    except OSError:
        return None


def detect_gearlab(root: Path, kastra_python: Path | None = None) -> dict[str, Any]:
    root = Path(root).expanduser()
    kpy = Path(kastra_python).expanduser() if kastra_python else root.parent / "IzaKastra-wsdist/.venv/bin/python"
    release = {}
    release_path = root / "RELEASE.json"
    if release_path.is_file():
        try:
            obj = json.loads(release_path.read_text(encoding="utf-8"))
            if isinstance(obj, dict):
                release = _strip_retired_capture(obj)
        except Exception:
            pass
    return {
        "root": str(root),
        "available": (root / "engine.py").is_file(),
        "engine": str(root / "engine.py"),
        "engine_mtime": _mtime(root / "engine.py"),
        "selftest": str(root / "selftest.py"),
        "selftest_available": (root / "selftest.py").is_file(),
        "results": str(root / "results"),
        "results_available": (root / "results").is_dir(),
        "logs": str(root / "logs"),
        "kastra_python": str(kpy),
        "kastra_python_available": kpy.is_file() and os.access(kpy, os.X_OK),
        "release": release,
        "version": str(release.get("version") or release.get("release") or "unknown"),
    }


def _last_json_line(text: str) -> dict[str, Any] | None:
    for line in reversed([x.strip() for x in (text or "").splitlines() if x.strip()]):
        try:
            obj = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(obj, dict):
            return obj
    return None


def run_engine_json(kpy: Path, engine: Path, args: list[str], timeout: int = 120) -> dict[str, Any]:
    started = time.time()
    try:
        cp = subprocess.run(
            [str(kpy), str(engine), *args],
            cwd=str(engine.parent),
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
            timeout=timeout,
            check=False,
        )
    except Exception as exc:
        return {"ok": False, "error": str(exc), "args": args, "elapsed_seconds": round(time.time() - started, 3)}
    obj = _last_json_line(cp.stdout)
    payload: dict[str, Any] = {
        "ok": cp.returncode == 0 and isinstance(obj, dict) and obj.get("ok", True) is not False,
        "exit_code": cp.returncode,
        "args": args,
        "elapsed_seconds": round(time.time() - started, 3),
    }
    if obj is not None:
        obj = _strip_retired_capture(obj)
        payload["raw"] = obj
        payload["data"] = obj.get("data") if isinstance(obj.get("data"), dict) else obj
    if cp.stderr.strip():
        payload["stderr_tail"] = cp.stderr.strip()[-2000:]
    if not payload["ok"] and obj is None:
        payload["error"] = (cp.stderr or cp.stdout or "Gear Lab command failed").strip()[-2000:]
    return payload


def run_selftest(kpy: Path, selftest: Path, timeout: int = 180) -> dict[str, Any]:
    started = time.time()
    try:
        cp = subprocess.run(
            [str(kpy), str(selftest)], cwd=str(selftest.parent), text=True,
            stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout, check=False,
        )
    except Exception as exc:
        return {"ok": False, "error": str(exc), "elapsed_seconds": round(time.time() - started, 3)}
    obj = _last_json_line(cp.stdout)
    return {
        "ok": cp.returncode == 0,
        "exit_code": cp.returncode,
        "elapsed_seconds": round(time.time() - started, 3),
        "json": obj,
        "output_tail": (cp.stdout or "")[-4000:],
        "stderr_tail": (cp.stderr or "")[-2000:],
    }


def _json_files(root: Path, limit: int = 250) -> list[Path]:
    if not root.is_dir():
        return []
    files = []
    for p in root.rglob("*.json"):
        try:
            files.append((p.stat().st_mtime, p))
        except OSError:
            pass
    files.sort(reverse=True)
    return [p for _, p in files[:limit]]


def _unwrap_doc(obj: Any) -> Any:
    if isinstance(obj, dict) and obj.get("ok") is True and isinstance(obj.get("data"), (dict, list)):
        return obj["data"]
    return obj


def _first_scalar(d: Any, keys: tuple[str, ...]) -> Any:
    if not isinstance(d, dict):
        return None
    lower = {str(k).lower(): v for k, v in d.items()}
    for key in keys:
        if key.lower() in lower and not isinstance(lower[key.lower()], (dict, list)):
            return lower[key.lower()]
    return None


def _find_numeric(obj: Any, names: set[str], *, max_depth: int = 6, depth: int = 0) -> float | None:
    if depth > max_depth:
        return None
    if isinstance(obj, dict):
        for k, v in obj.items():
            if str(k).lower() in names and isinstance(v, (int, float)) and not isinstance(v, bool):
                return float(v)
        for v in obj.values():
            n = _find_numeric(v, names, max_depth=max_depth, depth=depth + 1)
            if n is not None:
                return n
    elif isinstance(obj, list):
        for v in obj[:100]:
            n = _find_numeric(v, names, max_depth=max_depth, depth=depth + 1)
            if n is not None:
                return n
    return None


def _find_scalar(obj: Any, names: set[str], *, max_depth: int = 5, depth: int = 0) -> Any:
    if depth > max_depth:
        return None
    if isinstance(obj, dict):
        for k, v in obj.items():
            if str(k).lower() in names and isinstance(v, (str, int, float)) and not isinstance(v, bool):
                return v
        for v in obj.values():
            x = _find_scalar(v, names, max_depth=max_depth, depth=depth + 1)
            if x is not None:
                return x
    elif isinstance(obj, list):
        for v in obj[:100]:
            x = _find_scalar(v, names, max_depth=max_depth, depth=depth + 1)
            if x is not None:
                return x
    return None


def summarize_result(path: Path, obj: Any) -> dict[str, Any]:
    data = _unwrap_doc(obj)
    sustained = _find_numeric(data, {"total_dps", "sustained_dps", "cycle_dps"})
    ws_value = _find_numeric(data, {"ws_damage", "weaponskill_damage", "isolated_ws", "average_ws", "mean_ws"})
    stability = _find_numeric(data, {"gap_to_second_percent", "stability_gap_percent"})
    objective = _find_scalar(data, {"objective", "optimization_target", "mode", "goal"})
    ws_name = _find_scalar(data, {"ws_name", "weaponskill", "weapon_skill"})
    main = _find_scalar(data, {"main_weapon", "main"})
    sub = _find_scalar(data, {"sub_weapon", "sub"})
    enemy = _find_scalar(data, {"enemy", "enemy_name", "target"})
    return {
        "file": path.name,
        "path": str(path),
        "mtime": _mtime(path),
        "objective": objective,
        "ws_name": ws_name,
        "main": main,
        "sub": sub,
        "enemy": enemy,
        "sustained_dps": sustained,
        "ws_value": ws_value,
        "stability_gap_percent": stability,
    }


_GAIN_KEYS = {
    "gain_percent", "dps_gain_percent", "delta_percent", "improvement_percent", "benefit_percent",
    "gain_pct", "delta_pct", "percent_gain",
}
_GAIN_ABS_KEYS = {"dps_gain", "gain_dps", "delta_dps", "improvement_dps"}
_ITEM_KEYS = ("item_name", "item", "upgrade_item", "candidate_name", "candidate")


def _signal_context(obj: dict[str, Any], inherited: dict[str, Any]) -> dict[str, Any]:
    context = dict(inherited)
    fields = {
        "job": ("job", "player_job"),
        "set_name": ("set_name", "gear_set", "set"),
        "variant_name": ("variant_name", "variant"),
        "objective": ("objective", "optimization_target", "mode", "profile"),
        "enemy": ("enemy", "enemy_name", "target"),
        "scenario": ("scenario", "scenario_name", "buff_profile", "buffs"),
    }
    for target, names in fields.items():
        value = _first_scalar(obj, names)
        if value not in (None, ""):
            context[target] = value
    return context


def _extract_upgrade_signals(
    obj: Any,
    source: str,
    out: list[dict[str, Any]],
    depth: int = 0,
    context: dict[str, Any] | None = None,
    source_mtime: int | None = None,
) -> None:
    if depth > 10 or len(out) >= 2000:
        return
    if isinstance(obj, dict):
        context = _signal_context(obj, context or {})
        name = _first_scalar(obj, _ITEM_KEYS)
        pct = None
        absolute = None
        for k, v in obj.items():
            lk = str(k).lower()
            if isinstance(v, (int, float)) and not isinstance(v, bool):
                if lk in _GAIN_KEYS:
                    pct = float(v)
                elif lk in _GAIN_ABS_KEYS:
                    absolute = float(v)
        if isinstance(name, str) and name.strip() and (pct is not None or absolute is not None):
            out.append({
                "item": name.strip(),
                "gain_percent": pct,
                "dps_gain": absolute,
                "slot": _first_scalar(obj, ("slot", "gear_slot")),
                "item_id": _first_scalar(obj, ("item_id", "itemid", "id")),
                "job": context.get("job"),
                "set_name": context.get("set_name"),
                "variant_name": context.get("variant_name"),
                "objective": context.get("objective"),
                "enemy": context.get("enemy"),
                "scenario": context.get("scenario"),
                "source_file": source,
                "source_mtime": source_mtime,
                "evidence_only": True,
            })
        for v in obj.values():
            _extract_upgrade_signals(v, source, out, depth + 1, context, source_mtime)
    elif isinstance(obj, list):
        for v in obj[:1000]:
            _extract_upgrade_signals(v, source, out, depth + 1, context, source_mtime)

def scan_results(results_dir: Path, limit: int = 250) -> dict[str, Any]:
    rows = []
    upgrades: list[dict[str, Any]] = []
    parse_errors = []
    result_files = _json_files(results_dir, limit=limit)
    for p in result_files:
        try:
            obj = json.loads(p.read_text(encoding="utf-8"))
        except Exception as exc:
            parse_errors.append({"file": p.name, "error": str(exc)})
            continue
        summary = summarize_result(p, obj)
        if any(summary.get(k) is not None for k in ("sustained_dps", "ws_value", "objective", "ws_name")):
            rows.append(summary)
        _extract_upgrade_signals(_unwrap_doc(obj), p.name, upgrades, source_mtime=_mtime(p))
    dedup: dict[tuple[str, ...], dict[str, Any]] = {}
    for row in upgrades:
        key = tuple(str(x or "").casefold() for x in (
            row.get("item_id"), row["item"], row.get("job"), row.get("objective"), row.get("slot"),
            row.get("set_name"), row.get("variant_name"), row.get("enemy"), row.get("scenario"),
        ))
        old = dedup.get(key)
        score = row.get("gain_percent") if row.get("gain_percent") is not None else row.get("dps_gain")
        old_score = None if old is None else (old.get("gain_percent") if old.get("gain_percent") is not None else old.get("dps_gain"))
        if old is None or (score is not None and (old_score is None or score > old_score)):
            dedup[key] = row
    upgrades = list(dedup.values())
    upgrades.sort(key=lambda r: (r.get("gain_percent") is None, -(r.get("gain_percent") or 0), r.get("dps_gain") is None, -(r.get("dps_gain") or 0), r["item"].casefold()))
    return {
        "result_file_count": len(result_files),
        "latest_result_mtime": _mtime(result_files[0]) if result_files else None,
        "summaries": rows[:100],
        "upgrade_signals": upgrades[:300],
        "parse_errors": parse_errors[:20],
    }

def _atomic_write_json(path: Path, data: dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    tmp.replace(path)


def refresh_performance_cache(
    cache_path: Path,
    gearlab_root: Path,
    kastra_python: Path,
    *,
    player_name: str = "",
    run_selftest_flag: bool = True,
) -> dict[str, Any]:
    gearlab_root = Path(gearlab_root).expanduser()
    kastra_python = Path(kastra_python).expanduser()
    detection = detect_gearlab(gearlab_root, kastra_python)
    payload: dict[str, Any] = {
        "schema": CACHE_SCHEMA,
        "release": RELEASE,
        "generated_at_unix": int(time.time()),
        "gearlab": detection,
        "diagnostics": {},
        "results": scan_results(gearlab_root / "results"),
    }
    if detection["available"] and detection["kastra_python_available"]:
        engine = gearlab_root / "engine.py"
        payload["diagnostics"]["catalog_gearfinder"] = run_engine_json(kastra_python, engine, ["catalog", "--source", "gearfinder"], timeout=150)
        payload["diagnostics"]["lac_day"] = run_engine_json(kastra_python, engine, ["lac-diagnose", "--time-mode", "Day"], timeout=150)
        payload["diagnostics"]["lac_night"] = run_engine_json(kastra_python, engine, ["lac-diagnose", "--time-mode", "Night"], timeout=150)
        payload["diagnostics"]["metrics_scan"] = run_engine_json(kastra_python, engine, ["metrics-scan"], timeout=90)
        if run_selftest_flag and (gearlab_root / "selftest.py").is_file():
            payload["diagnostics"]["selftest"] = run_selftest(kastra_python, gearlab_root / "selftest.py", timeout=240)
    payload["ready"] = bool(detection["available"] and detection["kastra_python_available"])
    _atomic_write_json(Path(cache_path).expanduser(), payload)
    return payload


def load_performance_cache(cache_path: Path, gearlab_root: Path, kastra_python: Path) -> dict[str, Any]:
    path = Path(cache_path).expanduser()
    detection = detect_gearlab(Path(gearlab_root).expanduser(), Path(kastra_python).expanduser())
    try:
        obj = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(obj, dict) or obj.get("schema") not in {CACHE_SCHEMA, "gearplaner.performance-cache/0.7"}:
            raise ValueError("unsupported cache schema")
        # Keep theoretical Gear-Lab results, but never expose the retired combat-capture layer.
        obj.pop("combat", None)
        if obj.get("schema") != CACHE_SCHEMA:
            obj["schema"] = CACHE_SCHEMA
            obj["release"] = RELEASE
    except Exception as exc:
        return {
            "schema": CACHE_SCHEMA,
            "release": RELEASE,
            "generated_at_unix": 0,
            "ready": False,
            "gearlab": detection,
            "diagnostics": {},
            "results": {"result_file_count": 0, "latest_result_mtime": None, "summaries": [], "upgrade_signals": [], "parse_errors": []},
            "cache_available": False,
            "cache_error": str(exc),
        }
    obj = _strip_retired_capture(obj)
    obj["release"] = RELEASE
    obj["cache_available"] = True
    obj["gearlab_current"] = detection
    return obj


def build_performance_priorities(cache: dict[str, Any], plan: dict[str, Any]) -> dict[str, Any]:
    signals = (cache.get("results") or {}).get("upgrade_signals") or []
    task_rows = [*(plan.get("personal") or []), *(plan.get("guide") or [])]
    refs_by_name: dict[str, list[dict[str, Any]]] = {}
    refs_by_id: dict[int, list[dict[str, Any]]] = {}
    for task in task_rows:
        for req in task.get("requirements") or []:
            label = str(req.get("item_name") or req.get("label") or "").strip()
            if not label:
                continue
            ref = {
                "task_id": task.get("id"), "job": task.get("job"), "set_name": task.get("set_name"),
                "variant_name": task.get("variant_name"), "slots": req.get("slots") or [],
                "item_id": req.get("item_id"), "personal_goal": bool(task.get("personal_goal")),
            }
            refs_by_name.setdefault(label.casefold(), []).append(ref)
            if int(req.get("item_id") or 0) > 0:
                refs_by_id.setdefault(int(req["item_id"]), []).append(ref)
    rows = []
    for sig in signals:
        signal_item_id = int(sig.get("item_id") or 0)
        linked = list(refs_by_id.get(signal_item_id, [])) if signal_item_id else list(refs_by_name.get(str(sig.get("item") or "").casefold(), []))
        signal_job = str(sig.get("job") or "").casefold()
        signal_slot = str(sig.get("slot") or "").casefold()
        if signal_job:
            linked = [ref for ref in linked if not ref.get("job") or str(ref.get("job")).casefold() == signal_job]
        if signal_slot:
            linked = [ref for ref in linked if not ref.get("slots") or signal_slot in {str(x).casefold() for x in ref.get("slots") or []}]
        row = dict(sig)
        row["linked_tasks"] = linked[:40]
        row["linked_personal_goal"] = any(x.get("personal_goal") for x in linked)
        rows.append(row)
    rows.sort(key=lambda r: (
        not r.get("linked_personal_goal"),
        r.get("gain_percent") is None,
        -(r.get("gain_percent") or 0),
        r.get("dps_gain") is None,
        -(r.get("dps_gain") or 0),
        str(r.get("item") or "").casefold(),
    ))
    return {
        "schema": "gearcenter.performance-priorities/1.0",
        "release": RELEASE,
        "evidence_only": True,
        "rules": {
            "no_invented_stat_score": True,
            "rank_only_when_gearlab_output_contains_measured_or_modeled_gain": True,
            "guide_recommendations_are_not_personal_goals": True,
        },
        "rows": rows[:300],
    }
