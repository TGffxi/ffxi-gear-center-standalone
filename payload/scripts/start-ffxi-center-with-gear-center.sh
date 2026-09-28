#!/usr/bin/env bash
set -Eeuo pipefail
# Compatibility shim only. The actual ffxi_center.py process owns the
# Gear-Center lifecycle; this wrapper must never acquire/release services.
exec python3 "$HOME/Games/FFXI/tools/FFXI-Center/ffxi_center.py"
