# Privacy

FFXI Gear Center is designed as a local-first application.

By default it binds its API and web interface to `127.0.0.1` and reads local FFXI/Ashita/LuAshitacast/GearFinder state. The project does not include telemetry, analytics, an account system, or a remote Gear Center backend.

Local files may contain character names, server ids, inventory, augments, currencies, goals, notes and task state. Treat backups and runtime data as personal data and do not publish them accidentally.

The public distribution intentionally ships a schema-only SQLite seed and no player/runtime snapshots.
