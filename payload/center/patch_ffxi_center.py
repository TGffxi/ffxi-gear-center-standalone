from __future__ import annotations

import argparse
import re
from pathlib import Path


LIFECYCLE_BLOCK_BEGIN = '# GEAR_CENTER_LIFECYCLE_OWNER_BEGIN'
LIFECYCLE_BLOCK_END = '# GEAR_CENTER_LIFECYCLE_OWNER_END'


def patch_starters(s: str) -> tuple[str, int]:
    pat = re.compile(r'(?ms)^(?P<i>[ \t]*)(?P<n>(?:HB_)?STARTERS)\s*=\s*\{\n(?P<b>.*?)^(?P=i)\}')
    count = 0

    def repl(m: re.Match[str]) -> str:
        nonlocal count
        lines = []
        for line in m.group('b').splitlines():
            if re.search(r'["\'](?:FFXI )?Gear Center["\']\s*:', line, re.I):
                continue
            if re.search(r'["\']Gearplaner(?:\s+0\.[0-9.]+)?["\']\s*:', line, re.I):
                continue
            lines.append(line)
        indent = m.group('i') + '    '
        entry = indent + '"Gear Center": SCRIPTS / "open-gear-center.sh",'
        pos = next((i + 1 for i, line in enumerate(lines) if '"FFXI / Ashita"' in line), len(lines))
        lines.insert(pos, entry)
        count += 1
        return f"{m.group('i')}{m.group('n')} = {{\n" + '\n'.join(lines) + f"\n{m.group('i')}}}"

    return pat.sub(repl, s), count


def strip_retired_capture_ui(s: str) -> str:
    # Complete-handbook V11: remove the dedicated capture entry while preserving Metrics.
    s = re.sub(
        r'\n\s{8}add\("capture",\s*"04 Gear / Analyse",\s*"Gear Lab Live Capture[^\n]*\n.*?(?=\n\s{8}add\("metrics",)',
        '\n', s, flags=re.S,
    )
    # V11 Gear Lab entry: commands should only describe theoretical/model tooling.
    s = re.sub(
        r"(''',\s*)\[\s*\"/addon load gearlab_capture\".*?\](,\s*HB_GEARLAB\))",
        r"\1[]\2", s, flags=re.S,
    )
    s = s.replace('Metrics bleibt als unabhängiger read-only Kontrollpfad neben Gear Labs Live Capture erhalten.',
                  'Metrics bleibt als unabhängiger read-only Kontrollpfad für Vergleichsdaten erhalten.')
    s = re.sub(r'^\s*5\.\s*/glcapture status\.\s*$', '', s, flags=re.M)
    s = s.replace('["/addon list", "/list", "/glcapture status"]', '["/addon list", "/list"]')

    # Older Gear-Lab help block: remove the live-capture workflow section from the embedded help text.
    s = re.sub(
        r'\n6\. Live Combat Capture aktivieren\n.*?(?=\nWICHTIGE SHELL-BEFEHLE\n)',
        '\n', s, flags=re.S,
    )
    # Remove capture command buttons / status rows that were inserted by the older Gear-Lab help patch.
    s = re.sub(r'^\s*\("Capture[^\n]*\n', '', s, flags=re.M)
    s = re.sub(r'^\s*\("GearLab Capture"[^\n]*\n', '', s, flags=re.M)
    s = re.sub(r'^\s*\("Capture Startmodus"[^\n]*\n', '', s, flags=re.M)
    s = re.sub(r'^\s*rows\.append\(\("Combat Capture Daten"[^\n]*\n', '', s, flags=re.M)
    s = re.sub(
        r'\n\s*cap_files\s*=\s*\[\]\n\s*if\s+GEARLAB_CAPTURE_DIR\.is_dir\(\):\n.*?(?=\n\s*for name, status, detail in rows:)',
        '\n', s, flags=re.S,
    )

    # Remove literal capture commands / stale help prose without deleting arbitrary Python source lines.
    s = re.sub(r'/addon\s+(?:load|reload|unload)\s+gearlab_capture\b', '', s, flags=re.I)
    s = re.sub(r'/glcapture(?:\s+(?:status|on|off|mark|start|stop))?\b', '', s, flags=re.I)
    s = re.sub(r'Gear Lab Live Capture(?:\s*[–—-]\s*Befehle)?', '', s, flags=re.I)
    s = re.sub(r'Combat Capture Daten', '', s, flags=re.I)
    s = re.sub(r'GearLab Capture', '', s, flags=re.I)
    s = re.sub(r'Capture-Dateien[^\n]*GearLab[^\n]*', '', s, flags=re.I)

    # Constants are deleted only when no code still refers to them after the UI cleanup.
    for symbol in ('HB_GEARLAB_CAPTURE', 'HB_GEARLAB_CAPTURE_CFG', 'GEARLAB_CAPTURE_ADDON', 'GEARLAB_CAPTURE_DIR'):
        if len(re.findall(rf'\b{re.escape(symbol)}\b', s)) == 1:
            s = re.sub(rf'^\s*{re.escape(symbol)}\s*=.*\n?', '', s, flags=re.M)
    return s


def patch_direct_lifecycle(s: str) -> tuple[str, int]:
    """Make the actual ffxi_center.py process the Gear Center lifecycle owner."""
    # Remove any previously generated lifecycle helper block so the patch is idempotent.
    s = re.sub(
        rf'\n?{re.escape(LIFECYCLE_BLOCK_BEGIN)}\n.*?{re.escape(LIFECYCLE_BLOCK_END)}\n?',
        '\n', s, flags=re.S,
    )

    helper = '''# GEAR_CENTER_LIFECYCLE_OWNER_BEGIN
_GEAR_CENTER_LIFECYCLE_TOKEN = f"ffxi-center-{os.getpid()}"
_GEAR_CENTER_LIFECYCLE_HELD = False


def _gear_center_lifecycle_services() -> Path:
    return Path.home() / "Games/FFXI/scripts/gear-center-services.sh"


def _gear_center_lifecycle_acquire() -> None:
    global _GEAR_CENTER_LIFECYCLE_HELD
    if _GEAR_CENTER_LIFECYCLE_HELD:
        return
    services = _gear_center_lifecycle_services()
    if not services.is_file():
        raise RuntimeError(f"Gear-Center Lifecycle fehlt: {services}")
    proc = subprocess.run(
        [str(services), "acquire", _GEAR_CENTER_LIFECYCLE_TOKEN, str(os.getpid())],
        stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True,
    )
    if proc.returncode != 0:
        detail = (proc.stderr or proc.stdout or "unbekannter Fehler").strip()
        raise RuntimeError(f"Gear Center konnte nicht mit FFXI Center starten: {detail}")
    _GEAR_CENTER_LIFECYCLE_HELD = True


def _gear_center_lifecycle_release() -> None:
    global _GEAR_CENTER_LIFECYCLE_HELD
    if not _GEAR_CENTER_LIFECYCLE_HELD:
        return
    _GEAR_CENTER_LIFECYCLE_HELD = False
    services = _gear_center_lifecycle_services()
    if services.is_file():
        subprocess.run(
            [str(services), "release", _GEAR_CENTER_LIFECYCLE_TOKEN],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
        )
# GEAR_CENTER_LIFECYCLE_OWNER_END'''

    class_pos = s.find('class Center(tk.Tk):')
    if class_pos < 0:
        raise ValueError('Center-Klasse nicht gefunden')
    s = s[:class_pos] + helper + '\n\n\n' + s[class_pos:]

    main_pat = re.compile(
        r'(?ms)^if\s+__name__\s*==\s*["\']__main__["\']\s*:\s*\n(?P<body>(?:[ \t]+.*\n?)*)\Z'
    )
    canonical_main = (
        'if __name__ == "__main__":\n'
        '    _gear_center_lifecycle_acquire()\n'
        '    try:\n'
        '        Center().mainloop()\n'
        '    finally:\n'
        '        _gear_center_lifecycle_release()\n'
    )
    m = main_pat.search(s)
    if m:
        s = s[:m.start()] + canonical_main
    else:
        s = s.rstrip() + '\n\n' + canonical_main
    return s, 1


def patch(path: Path) -> dict[str, int]:
    original = path.read_text(encoding='utf-8')
    required = ['class Center(tk.Tk):', 'STARTERS', 'import os', 'import subprocess', 'from pathlib import Path']
    missing = [x for x in required if x not in original]
    if missing:
        raise ValueError('Center-Struktur nicht kompatibel: ' + ', '.join(missing))
    s, dictionaries = patch_starters(original)
    if dictionaries < 1:
        raise ValueError('Kein STARTERS-Dictionary gefunden')
    s = strip_retired_capture_ui(s)
    s, lifecycle_blocks = patch_direct_lifecycle(s)
    path.write_text(s, encoding='utf-8')
    return {
        'starter_dictionaries': dictionaries,
        'gear_center_entries': len(re.findall(r'["\']Gear Center["\']\s*:', s)),
        'glcapture_commands': len(re.findall(r'/glcapture\b', s, re.I)),
        'direct_lifecycle_blocks': lifecycle_blocks,
    }


def main() -> None:
    ap = argparse.ArgumentParser()
    ap.add_argument('center', type=Path)
    args = ap.parse_args()
    result = patch(args.center)
    print('[OK] FFXI Center gepatcht:', result)


if __name__ == '__main__':
    main()
