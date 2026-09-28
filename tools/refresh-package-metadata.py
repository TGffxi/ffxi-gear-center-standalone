#!/usr/bin/env python3
from __future__ import annotations

import hashlib
import json
import os
import shutil
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MANIFEST = ROOT / "PACKAGE-MANIFEST.json"
SUMS = ROOT / "SHA256SUMS"


def sha256(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(1024 * 1024), b""):
            h.update(chunk)
    return h.hexdigest()


def clean_build_debris() -> None:
    for p in sorted(ROOT.rglob("__pycache__"), reverse=True):
        if p.is_dir():
            shutil.rmtree(p)
    for p in ROOT.rglob("*.pyc"):
        p.unlink(missing_ok=True)


def package_files() -> list[Path]:
    excluded = {MANIFEST.resolve(), SUMS.resolve()}
    files: list[Path] = []
    for p in ROOT.rglob("*"):
        if not p.is_file() or p.resolve() in excluded:
            continue
        rel = p.relative_to(ROOT).as_posix()
        if "/__pycache__/" in f"/{rel}/" or rel.endswith(".pyc"):
            continue
        files.append(p)
    return sorted(files, key=lambda p: p.relative_to(ROOT).as_posix())


def main() -> None:
    clean_build_debris()
    release = json.loads((ROOT / "RELEASE.json").read_text(encoding="utf-8"))
    version = str(release.get("version") or "").strip()
    created = str(release.get("date") or "").strip()
    if not version or not created:
        raise SystemExit("RELEASE.json must contain version and date")

    rows = []
    for p in package_files():
        rows.append({
            "path": p.relative_to(ROOT).as_posix(),
            "size": p.stat().st_size,
            "sha256": sha256(p),
        })

    manifest = {
        "schema": "ffxi-gear-center.package-manifest/1",
        "release": version,
        "created": created,
        "file_count": len(rows),
        "files": rows,
    }
    MANIFEST.write_text(json.dumps(manifest, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")

    sum_paths = [MANIFEST] + package_files()
    lines = [f"{sha256(p)}  ./{p.relative_to(ROOT).as_posix()}" for p in sorted(sum_paths, key=lambda x: x.relative_to(ROOT).as_posix())]
    SUMS.write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"package metadata refreshed: release={version} files={len(rows)}")


if __name__ == "__main__":
    main()
