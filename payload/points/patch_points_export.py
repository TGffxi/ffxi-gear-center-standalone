#!/usr/bin/env python3
from __future__ import annotations

import argparse
import datetime as dt
import shutil
from pathlib import Path

BEGIN = "-- GEARPLANER_POINTS_EXPORT_BEGIN_v05"
END = "-- GEARPLANER_POINTS_EXPORT_END_v05"


def verify_source(text: str) -> None:
    required = {
        "Points state table": "local points",
        "Points tracked values": "local tValues",
        "Points addon metadata": "addon.name",
        "Ashita event API": "ashita.events.register",
    }
    missing = [label for label, token in required.items() if token not in text]
    if missing:
        raise SystemExit("Points preflight failed; missing: " + ", ".join(missing))


def strip_block(text: str) -> tuple[str, bool]:
    start = text.find(BEGIN)
    if start < 0:
        return text, False
    end = text.find(END, start)
    if end < 0:
        raise SystemExit("Found Gearplaner Points begin marker without end marker; refusing to modify file.")
    end += len(END)
    while end < len(text) and text[end] in "\r\n":
        end += 1
    clean = text[:start].rstrip() + "\n" + text[end:].lstrip("\r\n")
    return clean, True


def backup_file(path: Path, backup_dir: Path | None = None) -> Path:
    stamp = dt.datetime.now().strftime("%Y%m%d-%H%M%S")
    if backup_dir is None:
        target = path.with_name(path.name + f".gearplaner-backup-{stamp}")
    else:
        backup_dir.mkdir(parents=True, exist_ok=True)
        target = backup_dir / f"{path.name}.before-gearplaner-{stamp}"
    shutil.copy2(path, target)
    return target


def main() -> None:
    ap = argparse.ArgumentParser(description="Safely add/remove the Gearplaner 0.5 export hook in an existing Points addon.")
    ap.add_argument("--file", type=Path, required=True, help="Existing points.lua")
    ap.add_argument("--hook", type=Path, default=Path(__file__).with_name("gearplaner_points_export.lua.inc"))
    ap.add_argument("--backup-dir", type=Path)
    ap.add_argument("--check", action="store_true")
    ap.add_argument("--remove", action="store_true")
    args = ap.parse_args()

    path = args.file.expanduser().resolve()
    if not path.is_file():
        raise SystemExit(f"Points file not found: {path}")
    text = path.read_text(encoding="utf-8", errors="strict")
    verify_source(text)

    has_begin = BEGIN in text
    has_end = END in text
    if has_begin != has_end:
        raise SystemExit("Incomplete existing Gearplaner Points hook; refusing to modify file.")

    if args.check:
        print(f"Points preflight: OK ({path})")
        print("Gearplaner hook: " + ("installed" if has_begin else "not installed"))
        return

    if args.remove:
        clean, changed = strip_block(text)
        if not changed:
            print("Gearplaner Points hook is not installed; nothing to remove.")
            return
        backup = backup_file(path, args.backup_dir)
        path.write_text(clean, encoding="utf-8")
        print(f"Backup: {backup}")
        print("Gearplaner Points hook removed.")
        return

    hook = args.hook.expanduser().resolve().read_text(encoding="utf-8")
    if not hook.startswith(BEGIN) or END not in hook:
        raise SystemExit(f"Invalid hook payload: {args.hook}")
    if has_begin:
        print("Gearplaner Points hook already installed; no change.")
        return

    backup = backup_file(path, args.backup_dir)
    patched = text.rstrip() + "\n\n" + hook.rstrip() + "\n"
    path.write_text(patched, encoding="utf-8")
    print(f"Backup: {backup}")
    print("Gearplaner Points hook installed.")


if __name__ == "__main__":
    main()
