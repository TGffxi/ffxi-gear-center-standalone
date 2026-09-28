from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
APP = ROOT / "payload/app"
MANIFEST = ROOT / "PACKAGE-MANIFEST.json"
SUMS = ROOT / "SHA256SUMS"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def package_files() -> list[Path]:
    excluded = {MANIFEST.resolve(), SUMS.resolve()}
    return sorted(
        (p for p in ROOT.rglob("*") if p.is_file() and p.resolve() not in excluded),
        key=lambda p: p.relative_to(ROOT).as_posix(),
    )


def test_release_identity() -> None:
    assert (APP / "VERSION").read_text(encoding="utf-8").strip() == "1.0.2"
    rel = json.loads((ROOT / "RELEASE.json").read_text(encoding="utf-8"))
    assert rel["version"] == "1.0.2"
    assert rel["release_status"] == "PUBLIC-STANDALONE"
    assert rel["feature_contract"]["historical_versions"] == "0.1-0.8"
    assert rel["public_distribution"]["player_runtime_data_bundled"] is False
    assert rel["public_distribution"]["third_party_cache_bundled"] is False
    assert (ROOT / "PUBLIC-DISTRIBUTION.md").is_file()
    assert (ROOT / "PRIVACY.md").is_file()
    assert (ROOT / "THIRD_PARTY-DATA.md").is_file()
    assert (ROOT / "LICENSE").is_file()
    assert rel["standalone"]["ffxi_center_required"] is False


def test_no_packaging_debris() -> None:
    bad = []
    for p in ROOT.rglob("*"):
        rel = p.relative_to(ROOT).as_posix()
        name = p.name.casefold()
        if p.is_dir() and name == "__pycache__":
            bad.append(rel)
        if p.is_file() and (
            name.endswith(".pyc")
            or name.endswith(".bak")
            or ".pre-" in name
            or name.endswith("-wal")
            or name.endswith("-shm")
            or name.endswith(".tmp")
        ):
            bad.append(rel)
    assert not bad, f"packaging debris: {bad}"


def test_seed_has_no_runtime_logs_or_personal_home_paths() -> None:
    seed = ROOT / "seed-data"
    runtime_logs = [p.relative_to(ROOT).as_posix() for p in seed.rglob("*.log")]
    assert not runtime_logs, f"runtime logs shipped as seed data: {runtime_logs}"

    # Package text may contain generic service-user paths (shared php.ini), but
    # must not leak a developer/player home directory such as /home/<username>.
    allow_users = {"web_user"}
    bad: list[tuple[str, str]] = []
    text_suffixes = {".md", ".txt", ".json", ".py", ".php", ".js", ".mjs", ".sh", ".lua", ".ini", ".css", ".webmanifest"}
    home_re = re.compile(r"/home/([A-Za-z0-9._-]+)")
    for p in package_files():
        if p.suffix.casefold() not in text_suffixes and p.name not in {"VERSION"}:
            continue
        try:
            s = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for user in home_re.findall(s):
            if user not in allow_users:
                bad.append((p.relative_to(ROOT).as_posix(), user))
    assert not bad, f"personal absolute home paths in package: {bad}"


def test_one_frontend_and_one_api_path() -> None:
    assets = APP / "public/assets"
    dirs = sorted(p.name for p in assets.iterdir() if p.is_dir())
    assert dirs == ["gp10"], dirs
    assert not (APP / "public/api").exists(), "duplicate PHP API returned"
    for old in ("gp05", "gp06", "gp07", "gp08", "gcwork5"):
        assert not any(old in p.as_posix().casefold() for p in APP.rglob("*"))
    for f in ("serve.sh", "watch.sh", "sync.sh"):
        assert not (APP / "bin" / f).exists(), f


def test_no_embedded_old_release_trees() -> None:
    forbidden = ("checkpoint", "payload-old", "gearplaner-0.5", "gearplaner-0.6", "gearplaner-0.7", "gearplaner-0.8")
    bad = []
    for p in ROOT.rglob("*"):
        rel = p.relative_to(ROOT)
        # Rules deliberately name historical hotfixes; documentation is not an embedded payload.
        if rel.parts and rel.parts[0] == "Rules":
            continue
        if any(x in p.name.casefold() for x in forbidden):
            bad.append(rel.as_posix())
        if p.is_dir() and "hotfix" in p.name.casefold():
            bad.append(rel.as_posix())
    assert not bad, bad


def test_no_dead_end_contract_is_documented_and_tested() -> None:
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    acceptance = (APP / "bin/v1-acceptance.sh").read_text(encoding="utf-8")
    regress = (ROOT / "tests/test_regressions.py").read_text(encoding="utf-8")
    rules = (ROOT / "tests/test_rules_regressions.py").read_text(encoding="utf-8")
    hotfix = (ROOT / "tests/test_hotfix_contract.py").read_text(encoding="utf-8")
    historical_runtime = (ROOT / "tests/test_historical_runtime_contract.py").read_text(encoding="utf-8")
    assert "Kein-Sackgassen-Prinzip" in readme
    assert "Sackgasse" in acceptance
    assert "test_no_dead_end_user_flow_contract" in regress
    assert "physical" in rules.casefold() and "sqlite-todo" in rules
    assert "test_live_ux_hotfix_capabilities_are_locked" in hotfix
    assert "test_augment_v4_kikoku_ranked_effects_survive" in historical_runtime
    assert "test_source_health_requires_capture_consistency_and_complete_currency_decode" in historical_runtime



def test_public_sanitization_contract() -> None:
    # Public package must contain no private character/runtime seed and must not
    # auto-create the original user's personal goals.
    forbidden = ("The" + "grouch", "ste" + "vel", "The" + "grouch_" + "422" + "21")
    bad = []
    text_suffixes = {".md", ".txt", ".json", ".py", ".php", ".js", ".mjs", ".sh", ".lua", ".ini", ".css", ".webmanifest"}
    for p in package_files():
        if p.suffix.casefold() not in text_suffixes and p.name not in {"VERSION"}:
            continue
        try:
            text = p.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            continue
        for needle in forbidden:
            if needle.casefold() in text.casefold():
                bad.append((p.relative_to(ROOT).as_posix(), needle))
    assert not bad, f"private identity leaked into public package: {bad}"

    seed = ROOT / "seed-data/gear-center.sqlite"
    assert seed.is_file()
    import sqlite3
    con = sqlite3.connect(seed)
    try:
        for table in ("characters", "goals", "snapshots", "equipment_snapshot_items", "lac_profile_snapshots", "augment_bridge_snapshots", "resource_inventory_snapshots", "bgwiki_pages", "bgwiki_progression_records", "target_items"):
            assert con.execute(f'SELECT COUNT(*) FROM "{table}"').fetchone()[0] == 0, table
        assert con.execute("PRAGMA integrity_check").fetchone()[0] == "ok"
    finally:
        con.close()

    assert not (ROOT / "seed-data/cache").exists()
    installer = (ROOT / "install-1.0.sh").read_text(encoding="utf-8")
    config = (APP / "src/Config.php").read_text(encoding="utf-8")
    lac = (APP / "src/LacImporter.php").read_text(encoding="utf-8")
    assert "GEAR_CENTER_CHARACTER" in installer
    assert "data/character.txt" in config
    assert "ensureUserSeeds" not in lac

def test_manifest_matches_exact_release_tree() -> None:
    rel = json.loads((ROOT / "RELEASE.json").read_text(encoding="utf-8"))
    data = json.loads(MANIFEST.read_text(encoding="utf-8"))
    assert data["schema"] == "ffxi-gear-center.package-manifest/1"
    assert data["release"] == rel["version"] == "1.0.2"
    assert data["created"] == rel["date"]

    expected_files = package_files()
    expected_paths = [p.relative_to(ROOT).as_posix() for p in expected_files]
    rows = data.get("files") or []
    assert data["file_count"] == len(rows) == len(expected_paths)
    manifest_paths = [row["path"] for row in rows]
    assert manifest_paths == expected_paths, "PACKAGE-MANIFEST.json file list is stale or incomplete"
    for p, row in zip(expected_files, rows):
        assert row["size"] == p.stat().st_size, f"manifest size mismatch: {row['path']}"
        assert row["sha256"] == sha256(p), f"manifest hash mismatch: {row['path']}"


def test_sha256sums_matches_manifest_and_release_tree() -> None:
    expected_paths = [MANIFEST] + package_files()
    expected_paths = sorted(expected_paths, key=lambda p: p.relative_to(ROOT).as_posix())
    expected = [f"{sha256(p)}  ./{p.relative_to(ROOT).as_posix()}" for p in expected_paths]
    actual = [line.rstrip("\n") for line in SUMS.read_text(encoding="utf-8").splitlines() if line.strip()]
    assert actual == expected, "SHA256SUMS is stale, incomplete or contains unexpected files"
    assert not any(line.endswith("  ./SHA256SUMS") for line in actual), "SHA256SUMS must not self-hash"


if __name__ == "__main__":
    tests = [
        test_release_identity,
        test_no_packaging_debris,
        test_seed_has_no_runtime_logs_or_personal_home_paths,
        test_one_frontend_and_one_api_path,
        test_no_embedded_old_release_trees,
        test_no_dead_end_contract_is_documented_and_tested,
        test_public_sanitization_contract,
        test_manifest_matches_exact_release_tree,
        test_sha256sums_matches_manifest_and_release_tree,
    ]
    for fn in tests:
        fn()
    print(f"PASS: package cleanliness ({len(tests)} groups)")
