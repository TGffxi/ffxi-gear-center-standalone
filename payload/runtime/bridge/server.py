from __future__ import annotations

import argparse
import hashlib
import json
import mimetypes
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, unquote, urlparse

from augment_state import AugmentStateError, apply_augment_overlay, load_augment_state
from character_state import (
    SnapshotError,
    SnapshotUnavailableError,
    load_best_snapshot,
    enrich_item_names,
    merge_points_state,
    owned_locations,
    owned_totals,
    summary,
    v04_gear_compat,
)
from points_state import PointsStateError, load_points_state
from resource_planner import (
    PlannerDataError,
    aggregate_deficits,
    build_plan,
    build_resource_index,
    load_goals,
    load_requirements,
    remove_personal_goal,
    save_goals,
    set_personal_goal,
)
from source_health import build_source_health
from gearlab_adapter import build_performance_priorities, load_performance_cache, refresh_performance_cache
from farming_planner import build_farming_plan
from bgwiki_knowledge import enrich_farming, lookup_item
from runtime_overlay import load_runtime_overlay, merge_planning_catalogs, merge_progression_todos_into_farming

RELEASE = "1.0.2"
API_SCHEMA = "gearcenter.api/1.0"


class Handler(BaseHTTPRequestHandler):
    snapshot_path: Path
    legacy_snapshot_path: Path
    points_path: Path
    augment_path: Path
    currency_catalog_path: Path
    requirements_path: Path
    goals_path: Path
    web_root: Path
    performance_cache_path: Path
    gearlab_root: Path
    kastra_python: Path
    catalog_db_path: Path
    runtime_overlay_path: Path

    def _cors_origin(self) -> str | None:
        origin = (self.headers.get("Origin") or "").strip()
        if origin == "http://localhost" or origin.startswith("http://localhost:"):
            return origin
        if origin == "http://127.0.0.1" or origin.startswith("http://127.0.0.1:"):
            return origin
        return None

    def _send_headers(self, status: int, content_type: str, length: int, *, cache: str = "no-store") -> None:
        self.send_response(status)
        self.send_header("Content-Type", content_type)
        self.send_header("Content-Length", str(length))
        self.send_header("Cache-Control", cache)
        origin = self._cors_origin()
        if origin:
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.end_headers()

    def _send(self, status: int, payload: object) -> None:
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self._send_headers(status, "application/json; charset=utf-8", len(body))
        self.wfile.write(body)

    def _send_file(self, path: Path) -> None:
        try:
            body = path.read_bytes()
        except OSError:
            self._send(404, {"ok": False, "error": "not found"})
            return
        content_type = mimetypes.guess_type(path.name)[0] or "application/octet-stream"
        if content_type.startswith("text/") or content_type in {"application/javascript", "application/json"}:
            content_type += "; charset=utf-8"
        self._send_headers(200, content_type, len(body), cache="no-cache")
        self.wfile.write(body)

    def _serve_web(self, request_path: str) -> bool:
        prefixes = ("/v1", "/v0.8", "/v0.7", "/v0.6", "/v0.5")
        prefix = next((p for p in prefixes if request_path == p or request_path == p + "/" or request_path.startswith(p + "/")), None)
        if prefix is None:
            return False
        rel = "index.html" if request_path in {prefix, prefix + "/"} else unquote(request_path[len(prefix) + 1:])
        root = self.web_root.resolve()
        candidate = (root / rel).resolve()
        try:
            candidate.relative_to(root)
        except ValueError:
            self._send(403, {"ok": False, "error": "forbidden"})
            return True
        if candidate.is_dir():
            candidate = candidate / "index.html"
        self._send_file(candidate)
        return True

    @staticmethod
    def _canonical_path(path: str) -> str:
        # Older local clients remain compatible; 1.0 is canonical.
        for prefix in ("/api/v0.5/", "/api/v0.6/", "/api/v0.7/", "/api/v0.8/"):
            if path.startswith(prefix):
                return "/api/v1/" + path[len(prefix):]
        return path

    def _load_catalog(self) -> dict:
        try:
            data = json.loads(self.currency_catalog_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError) as exc:
            raise PlannerDataError(f"Currency catalog unavailable: {exc}") from exc
        if not isinstance(data, dict):
            raise PlannerDataError("Currency catalog root must be an object")
        return data

    def _points(self) -> tuple[dict | None, dict]:
        status = {"available": False, "ready": False, "path": str(self.points_path)}
        try:
            points = load_points_state(self.points_path)
        except PointsStateError as exc:
            status["error"] = str(exc)
            return None, status
        status.update({
            "available": True,
            "ready": points.get("ready") is True,
            "schema": points.get("schema"),
            "exported_at_unix": points.get("exported_at_unix"),
            "source": points.get("source") or {},
        })
        return points, status

    def _augments(self) -> tuple[dict | None, dict]:
        status = {"available": False, "ready": False, "path": str(self.augment_path)}
        try:
            data = load_augment_state(self.augment_path)
        except AugmentStateError as exc:
            status["error"] = str(exc)
            return None, status
        status.update({
            "available": True,
            "ready": bool(data.get("rows")),
            "schema": data.get("schema"),
            "version": data.get("version"),
            "exported_at_unix": data.get("exported_at_unix"),
            "parsed_rows": data.get("parsed_row_count", 0),
            "coverage_complete": data.get("coverage_complete") is True,
        })
        return data, status

    def _prelive_state(self) -> dict:
        return {
            "schema": "gearplaner.character-state/0.6",
            "source_schema": None,
            "bridge_version": RELEASE,
            "exported_at_unix": 0,
            "character": {"name": "Character"},
            "containers": [],
            "items": [],
            "equipped": [],
            "key_items": [],
            "currencies": {},
            "progression": {},
            "events": {},
            "raw": {},
            "capture": {"currency_0x113_seen": False, "currency_0x118_seen": False, "container_ids": []},
            "capabilities": {},
            "provenance": {"currencies": {}, "progression": {}},
            "points_overlay": {"active": False, "reason": "PRE-LIVE"},
            "_availability": {"inventory": False, "key_items": False, "currencies": False, "augments": False},
        }

    def _load_state(self, *, allow_preview: bool = True) -> tuple[dict, dict, dict, bool, Path | None]:
        try:
            state, used_path = load_best_snapshot(self.snapshot_path, self.legacy_snapshot_path)
            preview = False
        except SnapshotUnavailableError:
            if not allow_preview:
                raise
            state, used_path, preview = self._prelive_state(), None, True

        points, points_status = self._points()
        if not preview:
            state = merge_points_state(state, points)
            state, _ = enrich_item_names(state, self.catalog_db_path)

        augments, augment_status = self._augments()
        if not preview:
            state, overlay_status = apply_augment_overlay(state, augments)
            augment_status.update(overlay_status)

        catalog = self._load_catalog()
        health = build_source_health(
            state,
            snapshot_path=used_path,
            snapshot_source="native-v06" if used_path == self.snapshot_path else ("legacy-v05" if used_path else None),
            points_status=points_status,
            augment_status=augment_status,
            currency_catalog=catalog,
            requirements_path=self.requirements_path,
            goals_path=self.goals_path,
            pre_live=preview,
        )
        state["source_health"] = health
        state["release"] = RELEASE
        return state, points_status, augment_status, preview, used_path

    def _load_plan(self, state: dict) -> tuple[dict, dict, dict]:
        requirements = load_requirements(self.requirements_path)
        goals = load_goals(self.goals_path)
        overlay = load_runtime_overlay(self.runtime_overlay_path)
        requirements, goals = merge_planning_catalogs(requirements, goals, overlay)
        plan = build_plan(state, requirements, goals)
        plan["runtime_overlay"] = {
            "available": overlay.get("available") is True,
            "status": overlay.get("status"),
            "path": overlay.get("path"),
            "sqlite_goal_count": len((overlay.get("goals") or {}).get("goals") or []),
            "progression_todo_count": len(overlay.get("progression_todos") or []),
        }
        return plan, requirements, goals

    def _load_runtime_overlay(self) -> dict:
        return load_runtime_overlay(self.runtime_overlay_path)

    def _build_farming(self, state: dict, plan: dict, priorities: dict) -> dict:
        farming = build_farming_plan(plan, priorities)
        overlay = self._load_runtime_overlay()
        farming = merge_progression_todos_into_farming(farming, overlay, state)
        farming = enrich_farming(self.catalog_db_path, farming)
        farming["runtime_overlay"] = {
            "available": overlay.get("available") is True,
            "status": overlay.get("status"),
            "path": overlay.get("path"),
            "sqlite_goal_count": len((overlay.get("goals") or {}).get("goals") or []),
            "progression_todo_count": len(overlay.get("progression_todos") or []),
        }
        return farming

    @staticmethod
    def _dashboard_revision(state: dict, plan: dict, performance: dict, farming: dict, health: dict) -> str:
        # Hash the user-visible dynamic state, including the actual decoded
        # augment content. A pure augment change therefore invalidates the UI
        # even if the character snapshot timestamp itself did not change.
        items = [
            [
                int(row.get("item_id") or 0), int(row.get("container_id") or 0), int(row.get("slot") or 0),
                int(row.get("count") or 0), row.get("extra_hex"), row.get("decoded_augments") or [],
                row.get("augment_source"), row.get("equipped_slots") or [],
            ]
            for row in (state.get("items") or [])
        ]
        payload = {
            "snapshot": state.get("exported_at_unix"),
            "items": items,
            "key_items": [[row.get("key_item_id"), row.get("name"), row.get("owned")] for row in (state.get("key_items") or [])],
            "currencies": state.get("currencies") or {},
            "progression": state.get("progression") or {},
            "events": state.get("events") or {},
            "points_overlay": state.get("points_overlay") or {},
            "personal_goals": [
                [row.get("id"), row.get("status"), row.get("priority"),
                 [[r.get("resource_key"), r.get("owned"), r.get("missing"), r.get("known"), r.get("augment_match")] for r in (row.get("requirements") or [])]]
                for row in (plan.get("personal") or [])
            ],
            "performance_generated": performance.get("generated_at_unix"),
            "farming_counts": farming.get("counts") or {},
            "farming_knowledge": [[r.get("resource_key"), (r.get("bgwiki") or {}).get("page_title"), (r.get("bgwiki") or {}).get("revision_id"), (r.get("bgwiki") or {}).get("acquisition_hint")] for r in (farming.get("resources") or [])],
            "health": {"overall": health.get("overall"), "blocking": health.get("blocking_checks") or []},
        }
        raw = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(",", ":")).encode("utf-8")
        return hashlib.sha256(raw).hexdigest()[:24]

    def do_OPTIONS(self) -> None:
        self.send_response(204)
        origin = self._cors_origin()
        if origin:
            self.send_header("Access-Control-Allow-Origin", origin)
            self.send_header("Vary", "Origin")
        self.send_header("Access-Control-Allow-Methods", "GET, POST, OPTIONS")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.end_headers()

    def _read_json_body(self, max_bytes: int = 65536) -> dict:
        try:
            length = int(self.headers.get("Content-Length") or 0)
        except ValueError as exc:
            raise PlannerDataError("Invalid Content-Length") from exc
        if length <= 0 or length > max_bytes:
            raise PlannerDataError(f"JSON body must be between 1 and {max_bytes} bytes")
        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise PlannerDataError(f"Invalid JSON body: {exc}") from exc
        if not isinstance(payload, dict):
            raise PlannerDataError("JSON body must be an object")
        return payload

    def do_POST(self) -> None:
        path = self._canonical_path(urlparse(self.path).path)
        if path not in {"/api/v1/goals", "/api/v1/performance/refresh"}:
            self._send(404, {"ok": False, "error": "not found"})
            return
        origin = (self.headers.get("Origin") or "").strip()
        if origin and self._cors_origin() is None:
            self._send(403, {"ok": False, "error": "origin not allowed"})
            return
        try:
            if path == "/api/v1/performance/refresh":
                body = self._read_json_body()
                state, _, _, preview, _ = self._load_state()
                player_name = str((state.get("character") or {}).get("name") or "") if not preview else str(body.get("player_name") or "")
                cache = refresh_performance_cache(
                    self.performance_cache_path, self.gearlab_root, self.kastra_python,
                    player_name=player_name, run_selftest_flag=body.get("selftest", False) is True,
                )
                plan, _, _ = self._load_plan(state)
                priorities = build_performance_priorities(cache, plan)
                self._send(200, {"ok": True, "schema": API_SCHEMA, "release": RELEASE, "performance": cache, "priorities": priorities})
                return
            body = self._read_json_body()
            requirements = load_requirements(self.requirements_path)
            goals = load_goals(self.goals_path)
            action = str(body.get("action") or "set")
            task_id = str(body.get("task_id") or "").strip()
            known_ids = {str(t.get("id")) for t in requirements.get("tasks") or []}
            if task_id.startswith("sqlite-goal:"):
                raise PlannerDataError("SQLite goals are managed by the Gear Center goal repository, not the JSON goal endpoint")
            if action == "set":
                if task_id not in known_ids:
                    raise PlannerDataError("task_id is not present in the requirements catalog")
                goals = set_personal_goal(goals, task_id, priority=body.get("priority"), note=body.get("note"))
            elif action == "remove":
                goals = remove_personal_goal(goals, task_id)
            else:
                raise PlannerDataError("action must be 'set' or 'remove'")
            save_goals(self.goals_path, goals)
            state, _, _, preview, _ = self._load_state()
            plan, _, merged_goals = self._load_plan(state)
            self._send(200, {"ok": True, "schema": API_SCHEMA, "release": RELEASE, "goals": merged_goals, "plan": plan, "mode": "pre_live" if preview else "live"})
        except SnapshotError as exc:
            self._send(503, {"ok": False, "schema": API_SCHEMA, "release": RELEASE, "error": "data-source-failure", "detail": str(exc), "mode": "error"})
        except PlannerDataError as exc:
            self._send(400, {"ok": False, "schema": API_SCHEMA, "release": RELEASE, "error": "request-error", "detail": str(exc)})

    def do_GET(self) -> None:
        raw_path = urlparse(self.path).path
        if self._serve_web(raw_path):
            return
        path = self._canonical_path(raw_path)

        try:
            state, points_status, augment_status, preview, used_path = self._load_state()
            catalog = self._load_catalog()
        except SnapshotError as exc:
            self._send(503, {"ok": False, "schema": API_SCHEMA, "release": RELEASE, "error": "data-source-failure", "detail": str(exc), "mode": "error"})
            return
        except PlannerDataError as exc:
            self._send(503, {"ok": False, "schema": API_SCHEMA, "release": RELEASE, "error": "planner-data-failure", "detail": str(exc), "mode": "error"})
            return

        mode = "pre_live" if preview else "live"
        health = state.get("source_health") or {}

        if path == "/health":
            # API reachability is separate from live-data readiness. PRE-LIVE is
            # a successful API state and therefore returns 200.
            self._send(200, {
                "ok": True,
                "schema": API_SCHEMA,
                "release": RELEASE,
                "mode": mode,
                "snapshot_available": not preview,
                "snapshot_source": "native-v06" if used_path == self.snapshot_path else ("legacy-v05" if used_path else None),
                "points_available": points_status.get("available") is True,
                "augment_available": augment_status.get("available") is True,
                "requirements_available": self.requirements_path.is_file(),
                "goals_available": self.goals_path.is_file(),
                "currency_catalog_available": self.currency_catalog_path.is_file(),
                "live_ready": health.get("live_ready") is True,
            })
            return

        if path in {"/api/v1/prelive", "/api/v1/status"}:
            try:
                plan, requirements, goals = self._load_plan(state)
            except PlannerDataError:
                # Status remains reachable even when planning data is broken,
                # but it must never silently report the old JSON-only goal world.
                requirements = {"tasks": []}
                goals = {"goals": []}
                plan = {"runtime_overlay": {"available": False, "status": "planner-data-failure"}}
            currency_field_count = sum(len(v or []) for v in (catalog.get("packets") or {}).values())
            self._send(200, {
                "ok": True,
                "schema": API_SCHEMA,
                "release": RELEASE,
                "mode": mode,
                "snapshot_available": not preview,
                "points_available": points_status.get("available") is True,
                "augment_available": augment_status.get("available") is True,
                "task_count": len(requirements.get("tasks") or []),
                "personal_goal_count": len([g for g in goals.get("goals") or [] if g.get("enabled", True) is not False]),
                "runtime_overlay": plan.get("runtime_overlay") or {},
                "currency_field_count": currency_field_count,
                "live_ready": health.get("live_ready") is True,
                "overall": health.get("overall"),
                "gearlab_available": bool((lambda c: (c.get("gearlab_current") or c.get("gearlab") or {}).get("available", False))(load_performance_cache(self.performance_cache_path, self.gearlab_root, self.kastra_python))),
                "performance_cache_available": self.performance_cache_path.is_file(),
            })
            return

        if path == "/api/v1/dashboard":
            try:
                plan, requirements, goals = self._load_plan(state)
                performance = load_performance_cache(self.performance_cache_path, self.gearlab_root, self.kastra_python)
                priorities = build_performance_priorities(performance, plan)
                farming = self._build_farming(state, plan, priorities)
            except PlannerDataError as exc:
                self._send(503, {"ok": False, "error": str(exc)})
                return
            currency_field_count = sum(len(v or []) for v in (catalog.get("packets") or {}).values())
            status_payload = {
                "ok": True, "schema": API_SCHEMA, "release": RELEASE, "mode": mode,
                "snapshot_available": not preview,
                "points_available": points_status.get("available") is True,
                "augment_available": augment_status.get("available") is True,
                "task_count": len(requirements.get("tasks") or []),
                "personal_goal_count": len([g for g in goals.get("goals") or [] if g.get("enabled", True) is not False]),
                "currency_field_count": currency_field_count,
                "live_ready": health.get("live_ready") is True, "overall": health.get("overall"),
                "gearlab_available": bool((performance.get("gearlab_current") or performance.get("gearlab") or {}).get("available", False)),
                "performance_cache_available": self.performance_cache_path.is_file(),
            }
            revision = self._dashboard_revision(state, plan, performance, farming, health)
            self._send(200, {
                "schema": "gearcenter.dashboard/1.0", "release": RELEASE, "revision": revision, "status": status_payload,
                "state": state, "catalog": catalog, "plan": plan, "deficits": aggregate_deficits(plan),
                "health": health, "performance": performance, "priorities": priorities, "farming": farming,
            })
        elif path == "/api/v1/character":
            self._send(200, state)
        elif path == "/api/v1/summary":
            self._send(200, summary(state, catalog))
        elif path == "/api/v1/inventory":
            self._send(200, {"schema": "gearplaner.inventory/0.6", "mode": mode, "items": state.get("items") or [], "containers": state.get("containers") or [], "equipped": state.get("equipped") or [], "key_items": state.get("key_items") or []})
        elif path == "/api/v1/owned":
            self._send(200, {"schema": "gearplaner.owned/0.6", "mode": mode, "totals": owned_totals(state), "locations": owned_locations(state)})
        elif path == "/api/v1/resources":
            payload = build_resource_index(state); payload["schema"] = "gearplaner.resources/0.6"; payload["mode"] = mode; self._send(200, payload)
        elif path == "/api/v1/key-items":
            self._send(200, {"schema": "gearplaner.key-items/1.0", "mode": mode, "items": state.get("key_items") or [], "count": len(state.get("key_items") or []), "naming": "exported-or-id-fallback"})
        elif path == "/api/v1/currencies":
            self._send(200, {"schema": "gearplaner.currencies/0.6", "mode": mode, "values": state.get("currencies") or {}, "provenance": (state.get("provenance") or {}).get("currencies") or {}, "capture": state.get("capture") or {}})
        elif path == "/api/v1/currency-catalog":
            self._send(200, catalog)
        elif path == "/api/v1/progression":
            self._send(200, {"schema": "gearplaner.progression/0.6", "mode": mode, "values": state.get("progression") or {}, "provenance": (state.get("provenance") or {}).get("progression") or {}})
        elif path == "/api/v1/events":
            self._send(200, {"schema": "gearplaner.events/0.6", "mode": mode, "values": state.get("events") or {}})
        elif path in {"/api/v1/sources", "/api/v1/diagnostics", "/api/v1/live-readiness"}:
            self._send(200, health)
        elif path == "/api/v1/points":
            self._send(200, points_status)
        elif path == "/api/v1/augments":
            self._send(200, augment_status)
        elif path in {"/api/v1/plan", "/api/v1/deficits", "/api/v1/planning-status"}:
            try:
                plan, requirements, goals = self._load_plan(state)
            except PlannerDataError as exc:
                self._send(503, {"ok": False, "error": str(exc)})
                return
            if path == "/api/v1/plan":
                payload = dict(plan); payload["mode"] = mode; self._send(200, payload)
            elif path == "/api/v1/deficits":
                payload = aggregate_deficits(plan); payload["mode"] = mode; self._send(200, payload)
            else:
                self._send(200, {
                    "schema": "gearplaner.planning-status/0.6",
                    "mode": mode,
                    "task_count": len(requirements.get("tasks") or []),
                    "personal_goal_count": len([g for g in goals.get("goals") or [] if g.get("enabled", True) is not False]),
                    "orphan_personal_goal_count": len(plan.get("orphan_personal_goals") or []),
                    "rules": plan.get("rules") or {},
                })
        elif path == "/api/v1/goals":
            try:
                requirements = load_requirements(self.requirements_path)
                goals = load_goals(self.goals_path)
                _, goals = merge_planning_catalogs(requirements, goals, self._load_runtime_overlay())
                self._send(200, goals)
            except PlannerDataError as exc:
                self._send(503, {"ok": False, "error": str(exc)})
        elif path in {"/api/v1/performance", "/api/v1/gearlab", "/api/v1/priorities"}:
            cache = load_performance_cache(self.performance_cache_path, self.gearlab_root, self.kastra_python)
            if path == "/api/v1/performance":
                self._send(200, cache)
            elif path == "/api/v1/gearlab":
                self._send(200, {"schema": "gearplaner.gearlab/0.7", "release": RELEASE, "gearlab": cache.get("gearlab_current") or cache.get("gearlab") or {}, "diagnostics": cache.get("diagnostics") or {}, "results": cache.get("results") or {}})
            else:
                try:
                    plan, _, _ = self._load_plan(state)
                except PlannerDataError as exc:
                    self._send(503, {"ok": False, "error": str(exc)})
                    return
                self._send(200, build_performance_priorities(cache, plan))
        elif path == "/api/v1/item-knowledge":
            qs = parse_qs(urlparse(self.path).query)
            name = str((qs.get("name") or [""])[0]).strip()
            if not name:
                self._send(400, {"ok": False, "error": "name is required"})
            else:
                self._send(200, {"schema": "gearcenter.item-knowledge/1.0", "release": RELEASE, "item": lookup_item(self.catalog_db_path, name)})
        elif path in {"/api/v1/farming", "/api/v1/campaign", "/api/v1/farming-status"}:
            try:
                plan, _, _ = self._load_plan(state)
            except PlannerDataError as exc:
                self._send(503, {"ok": False, "error": str(exc)})
                return
            cache = load_performance_cache(self.performance_cache_path, self.gearlab_root, self.kastra_python)
            priorities = build_performance_priorities(cache, plan)
            farming = self._build_farming(state, plan, priorities)
            farming["mode"] = mode
            if path == "/api/v1/farming-status":
                self._send(200, {"schema":"gearcenter.farming-status/1.0","release":RELEASE,"mode":mode,"counts":farming.get("counts") or {},"has_personal_goals":farming.get("has_personal_goals") is True,"rules":farming.get("rules") or {}})
            else:
                self._send(200, farming)
        elif path == "/api/v0.4/gear-compat":
            self._send(200, {"items": v04_gear_compat(state)})
        else:
            self._send(404, {"ok": False, "error": "not found"})

    def log_message(self, fmt: str, *args: object) -> None:
        print("[gear-center-1.0.2] " + (fmt % args))


def main() -> None:
    ap = argparse.ArgumentParser(description="FFXI Gear Center 1.0.2 local state, planning and performance API")
    base = Path.home() / "Games/FFXI"
    ap.add_argument("--snapshot", type=Path, default=base / "ashita/config/addons/GearplanerBridge/snapshot-v06.json")
    ap.add_argument("--legacy-snapshot", type=Path, default=base / "ashita/config/addons/GearplanerBridge/snapshot-v05.json")
    ap.add_argument("--points", type=Path, default=base / "ashita/config/addons/points/gearplaner-points-v05.json")
    ap.add_argument("--character", default=(__import__("os").environ.get("GEAR_CENTER_CHARACTER") or ""))
    ap.add_argument("--augments", type=Path, default=None)
    ap.add_argument("--currency-catalog", type=Path, default=Path(__file__).resolve().with_name("currency_catalog.json"))
    ap.add_argument("--requirements", type=Path, default=base / "data/Gearplaner/requirements.json")
    ap.add_argument("--goals", type=Path, default=base / "data/Gearplaner/goals.json")
    ap.add_argument("--web", type=Path, default=Path(__file__).resolve().parent.parent / "web")
    ap.add_argument("--performance-cache", type=Path, default=base / "data/Gearplaner/performance-cache.json")
    ap.add_argument("--gearlab-root", type=Path, default=base / "tools/FFXI-Gear-Lab")
    ap.add_argument("--kastra-python", type=Path, default=base / "tools/IzaKastra-wsdist/.venv/bin/python")
    ap.add_argument("--catalog-db", type=Path, default=base / "tools/ffxi-gear-center/data/gear-center.sqlite")
    ap.add_argument("--runtime-overlay", type=Path, default=base / "data/Gearplaner/runtime-planning-overlay.json")
    ap.add_argument("--host", default="127.0.0.1")
    ap.add_argument("--port", type=int, default=8765)
    args = ap.parse_args()

    Handler.snapshot_path = args.snapshot.expanduser()
    Handler.legacy_snapshot_path = args.legacy_snapshot.expanduser()
    Handler.points_path = args.points.expanduser()

    if args.augments is not None:
        augment_path = args.augments.expanduser()
    else:
        lac_root = base / "ashita/config/addons/LuAshitacast"
        character = str(args.character or "").strip()
        pattern = f"{character}_*/gearcenter_augments.json" if character else "*/gearcenter_augments.json"
        candidates = [p for p in lac_root.glob(pattern) if p.is_file()] if lac_root.is_dir() else []
        if candidates:
            candidates.sort(key=lambda p: p.stat().st_mtime, reverse=True)
            augment_path = candidates[0]
        else:
            # Keep a deterministic missing path so PRE-LIVE/MISSING semantics are
            # preserved until LuAshitacast has produced its first snapshot.
            suffix = f"{character}_AUTO" if character else "AUTO"
            augment_path = lac_root / suffix / "gearcenter_augments.json"
    Handler.augment_path = augment_path
    Handler.currency_catalog_path = args.currency_catalog.expanduser()
    Handler.requirements_path = args.requirements.expanduser()
    Handler.goals_path = args.goals.expanduser()
    Handler.web_root = args.web.expanduser()
    Handler.performance_cache_path = args.performance_cache.expanduser()
    Handler.gearlab_root = args.gearlab_root.expanduser()
    Handler.kastra_python = args.kastra_python.expanduser()
    Handler.catalog_db_path = args.catalog_db.expanduser()
    Handler.runtime_overlay_path = args.runtime_overlay.expanduser()

    server = ThreadingHTTPServer((args.host, args.port), Handler)
    print(f"FFXI Gear Center {RELEASE} API: http://{args.host}:{args.port}")
    print(f"Mode endpoint: http://{args.host}:{args.port}/api/v1/status")
    print(f"Farming:       http://{args.host}:{args.port}/api/v1/farming")
    print(f"Snapshot v0.6: {Handler.snapshot_path}")
    print(f"Legacy v0.5:   {Handler.legacy_snapshot_path}")
    print(f"Points:        {Handler.points_path} (optional authoritative overlay)")
    print(f"Augments:      {Handler.augment_path} (optional exact-match overlay)")
    print(f"Gear Lab:      {Handler.gearlab_root} (read-only performance source)")
    print(f"Performance:   {Handler.performance_cache_path}")
    print(f"BG-Wiki cache: {Handler.catalog_db_path}")
    print(f"SQLite overlay: {Handler.runtime_overlay_path}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
