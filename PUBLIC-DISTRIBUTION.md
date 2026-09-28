# Public standalone distribution

This repository is the sanitized public standalone distribution of FFXI Gear Center 1.0.2.

It is derived from the live-accepted 1.0.1 product line, then sanitized for public release and extended with an independent standalone lifecycle.

The public build intentionally ships without player runtime data and without a bundled BG-Wiki/job-guide cache. The SQLite seed is schema-only.

## Standalone

FFXI Center is not required. The installed service manager provides explicit `start`, `stop`, `restart`, `status` and `open` operations. FFXI Center remains an optional compatibility integration only when its executable is already present locally.

## Privacy

No original player identity, inventory, personal goals, LuAshitacast profile snapshots, augment/resource snapshots or private runtime state is bundled.

## Third-party data

Cached BG-Wiki and job-guide material from the private build is excluded. Users may fetch selected data locally with the shipped importers.

Public distribution basis: public-sanitized FFXI Gear Center 1.0.1 plus the tested 1.0.2 standalone lifecycle delta.
