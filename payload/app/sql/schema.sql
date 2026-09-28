PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS schema_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
INSERT INTO schema_meta(key, value) VALUES('schema_version', '1')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS characters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL COLLATE NOCASE,
    server_id INTEGER NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS ux_characters_name_server
ON characters(lower(name), server_id);

CREATE TABLE IF NOT EXISTS import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    status TEXT NOT NULL,
    gear_path TEXT NULL,
    res_path TEXT NULL,
    gear_sha256 TEXT NULL,
    res_sha256 TEXT NULL,
    item_count INTEGER NULL,
    warning_count INTEGER NOT NULL DEFAULT 0,
    warning_json TEXT NULL,
    error_message TEXT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_import_runs_character ON import_runs(character_id, id DESC);

CREATE TABLE IF NOT EXISTS snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    import_run_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    captured_at TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    item_count INTEGER NOT NULL,
    unique_item_count INTEGER NOT NULL,
    augmented_item_count INTEGER NOT NULL DEFAULT 0,
    porter_slip_count INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY(import_run_id) REFERENCES import_runs(id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS ix_snapshots_character_time ON snapshots(character_id, captured_at DESC, id DESC);

CREATE TABLE IF NOT EXISTS item_definitions (
    item_id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    jobs_mask INTEGER NOT NULL DEFAULT 0,
    equip_slots_mask INTEGER NOT NULL DEFAULT 0,
    level INTEGER NOT NULL DEFAULT 0,
    item_level INTEGER NOT NULL DEFAULT 0,
    superior_level INTEGER NOT NULL DEFAULT 0,
    stats_json TEXT NOT NULL DEFAULT '{}',
    raw_resource_json TEXT NOT NULL,
    first_seen_at TEXT NOT NULL,
    last_seen_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS ix_item_definitions_name ON item_definitions(name COLLATE NOCASE);

CREATE TABLE IF NOT EXISTS equipment_snapshot_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    bag_id INTEGER NOT NULL,
    bag_name TEXT NOT NULL,
    bag_slot INTEGER NOT NULL,
    slip_id INTEGER NOT NULL DEFAULT 0,
    working INTEGER NOT NULL DEFAULT 0,
    augment_signature TEXT NOT NULL DEFAULT 'none',
    augment_stats_json TEXT NOT NULL DEFAULT '{}',
    raw_source_json TEXT NOT NULL,
    FOREIGN KEY(snapshot_id) REFERENCES snapshots(id) ON DELETE CASCADE,
    FOREIGN KEY(item_id) REFERENCES item_definitions(item_id) ON DELETE RESTRICT
);
CREATE INDEX IF NOT EXISTS ix_snapshot_items_snapshot ON equipment_snapshot_items(snapshot_id);
CREATE INDEX IF NOT EXISTS ix_snapshot_items_item ON equipment_snapshot_items(item_id);
CREATE INDEX IF NOT EXISTS ix_snapshot_items_bag ON equipment_snapshot_items(snapshot_id, bag_id, bag_slot);
CREATE INDEX IF NOT EXISTS ix_snapshot_items_aug ON equipment_snapshot_items(snapshot_id, augment_signature);

CREATE VIEW IF NOT EXISTS v_latest_snapshots AS
SELECT s.*
FROM snapshots s
WHERE s.id = (
    SELECT s2.id
    FROM snapshots s2
    WHERE s2.character_id = s.character_id
    ORDER BY s2.captured_at DESC, s2.id DESC
    LIMIT 1
);

-- v0.2 / LAC + goals ---------------------------------------------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '2')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS lac_import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    status TEXT NOT NULL,
    profile_dir TEXT NOT NULL,
    profile_count INTEGER NOT NULL DEFAULT 0,
    set_count INTEGER NOT NULL DEFAULT 0,
    requirement_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_import_runs_character ON lac_import_runs(character_id,id DESC);

CREATE TABLE IF NOT EXISTS lac_profile_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    import_run_id INTEGER NOT NULL,
    job TEXT NOT NULL,
    source_path TEXT NOT NULL,
    source_sha256 TEXT NOT NULL,
    captured_at TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY(import_run_id) REFERENCES lac_import_runs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_profiles_job ON lac_profile_snapshots(character_id,job,id DESC);

CREATE TABLE IF NOT EXISTS lac_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_snapshot_id INTEGER NOT NULL,
    set_key TEXT NOT NULL,
    raw_name TEXT NOT NULL,
    category TEXT NOT NULL,
    activation_type TEXT NOT NULL,
    default_active INTEGER NOT NULL DEFAULT 0,
    runtime_detected INTEGER NOT NULL DEFAULT 0,
    source_path TEXT NOT NULL,
    source_line INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(profile_snapshot_id) REFERENCES lac_profile_snapshots(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_sets_snapshot ON lac_sets(profile_snapshot_id);
CREATE INDEX IF NOT EXISTS ix_lac_sets_key ON lac_sets(set_key);

CREATE TABLE IF NOT EXISTS lac_requirements (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    set_id INTEGER NOT NULL,
    slot_name TEXT NOT NULL,
    item_name TEXT NOT NULL,
    item_name_norm TEXT NOT NULL,
    aug_path TEXT NULL,
    aug_rank TEXT NULL,
    aug_trial INTEGER NULL,
    augment_strings_json TEXT NOT NULL DEFAULT '[]',
    source_line INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(set_id) REFERENCES lac_sets(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_requirements_set ON lac_requirements(set_id);
CREATE INDEX IF NOT EXISTS ix_lac_requirements_name ON lac_requirements(item_name_norm);

CREATE TABLE IF NOT EXISTS lac_options (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_snapshot_id INTEGER NOT NULL,
    cycle_name TEXT NOT NULL,
    option_value TEXT NOT NULL,
    is_default INTEGER NOT NULL DEFAULT 0,
    slot_name TEXT NOT NULL,
    item_name TEXT NOT NULL,
    item_name_norm TEXT NOT NULL,
    activation_type TEXT NOT NULL,
    aug_path TEXT NULL,
    aug_rank TEXT NULL,
    aug_trial INTEGER NULL,
    augment_strings_json TEXT NOT NULL DEFAULT '[]',
    source_path TEXT NOT NULL,
    source_line INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(profile_snapshot_id) REFERENCES lac_profile_snapshots(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_options_snapshot ON lac_options(profile_snapshot_id);

CREATE TABLE IF NOT EXISTS lac_supplies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    profile_snapshot_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    name_norm TEXT NOT NULL,
    quantity_text TEXT NOT NULL,
    source_line INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(profile_snapshot_id) REFERENCES lac_profile_snapshots(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_lac_supplies_snapshot ON lac_supplies(profile_snapshot_id);

CREATE TABLE IF NOT EXISTS goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    entity_type TEXT NOT NULL,
    entity_key TEXT NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL,
    priority TEXT NOT NULL DEFAULT 'normal',
    origin TEXT NOT NULL DEFAULT 'USER',
    note TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE,
    UNIQUE(character_id, entity_type, entity_key)
);

CREATE TABLE IF NOT EXISTS item_overrides (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    item_name_norm TEXT NOT NULL,
    item_name TEXT NOT NULL,
    completion_state TEXT NOT NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE,
    UNIQUE(character_id,item_name_norm)
);

CREATE VIEW IF NOT EXISTS v_latest_lac_profiles AS
SELECT p.* FROM lac_profile_snapshots p
WHERE p.id = (
    SELECT p2.id FROM lac_profile_snapshots p2
    WHERE p2.character_id=p.character_id AND p2.job=p.job
    ORDER BY p2.id DESC LIMIT 1
);

-- v0.2.1 / LuAshitacast augment bridge -------------------------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '3')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS augment_bridge_import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    source_path TEXT NOT NULL,
    source_sha256 TEXT NOT NULL,
    started_at TEXT NOT NULL,
    completed_at TEXT NULL,
    status TEXT NOT NULL,
    captured_at TEXT NULL,
    item_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_aug_bridge_runs_character ON augment_bridge_import_runs(character_id,id DESC);

CREATE TABLE IF NOT EXISTS augment_bridge_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    import_run_id INTEGER NOT NULL,
    captured_at TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    inventory_counter INTEGER NOT NULL DEFAULT 0,
    item_count INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE,
    FOREIGN KEY(import_run_id) REFERENCES augment_bridge_import_runs(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_aug_bridge_snapshots_character ON augment_bridge_snapshots(character_id,id DESC);

CREATE TABLE IF NOT EXISTS augment_bridge_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id INTEGER NOT NULL,
    item_id INTEGER NOT NULL,
    bag_id INTEGER NOT NULL,
    bag_slot INTEGER NOT NULL,
    count INTEGER NOT NULL DEFAULT 1,
    augment_type TEXT NULL,
    aug_path TEXT NULL,
    aug_rank INTEGER NULL,
    aug_trial INTEGER NULL,
    trial_complete INTEGER NULL,
    augment_strings_json TEXT NOT NULL DEFAULT '[]',
    raw_extra_hex TEXT NOT NULL DEFAULT '',
    augment_error TEXT NULL,
    raw_json TEXT NOT NULL,
    FOREIGN KEY(snapshot_id) REFERENCES augment_bridge_snapshots(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_aug_bridge_items_location ON augment_bridge_items(snapshot_id,bag_id,bag_slot);
CREATE INDEX IF NOT EXISTS ix_aug_bridge_items_item ON augment_bridge_items(snapshot_id,item_id);

CREATE VIEW IF NOT EXISTS v_latest_augment_bridge_snapshots AS
SELECT s.* FROM augment_bridge_snapshots s
WHERE s.id = (
    SELECT s2.id FROM augment_bridge_snapshots s2
    WHERE s2.character_id=s.character_id
    ORDER BY s2.id DESC LIMIT 1
);

-- v0.3 / pre-live workflow, goals, tasks, BG-Wiki cache ----------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '4')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS tasks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    title TEXT NOT NULL,
    entity_type TEXT NULL,
    entity_key TEXT NULL,
    task_type TEXT NOT NULL DEFAULT 'obtain',
    status TEXT NOT NULL DEFAULT 'open',
    priority TEXT NOT NULL DEFAULT 'normal',
    content_name TEXT NULL,
    note TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_tasks_character_status ON tasks(character_id,status,priority,id);

CREATE TABLE IF NOT EXISTS bgwiki_pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_title TEXT NOT NULL COLLATE NOCASE,
    canonical_url TEXT NOT NULL,
    revision_id INTEGER NULL,
    revision_timestamp TEXT NULL,
    fetched_at TEXT NOT NULL,
    exists_flag INTEGER NOT NULL DEFAULT 1,
    sections_json TEXT NOT NULL DEFAULT '{}',
    raw_excerpt TEXT NULL,
    error_message TEXT NULL,
    UNIQUE(page_title)
);

CREATE TABLE IF NOT EXISTS bgwiki_item_map (
    item_name_norm TEXT PRIMARY KEY,
    item_name TEXT NOT NULL,
    page_title TEXT NOT NULL,
    confidence TEXT NOT NULL DEFAULT 'exact_title',
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS user_preferences (
    character_id INTEGER NOT NULL,
    pref_key TEXT NOT NULL,
    pref_value TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    PRIMARY KEY(character_id,pref_key),
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS bgwiki_progression_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_title TEXT NOT NULL COLLATE NOCASE,
    section_name TEXT NOT NULL,
    record_index INTEGER NOT NULL,
    source_type TEXT NOT NULL DEFAULT 'table',
    fields_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    UNIQUE(page_title, section_name, record_index)
);
CREATE INDEX IF NOT EXISTS ix_bgwiki_progression_page ON bgwiki_progression_records(page_title,section_name,record_index);

-- v0.3.5 / actionable acquisition routes -----------------------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '6')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS task_route_details (
    task_id INTEGER PRIMARY KEY,
    route_key TEXT NOT NULL,
    route_kind TEXT NOT NULL,
    source_title TEXT NULL,
    source_url TEXT NULL,
    source_section TEXT NULL,
    summary TEXT NULL,
    meta_json TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_task_route_key ON task_route_details(route_key);

CREATE TABLE IF NOT EXISTS task_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    task_id INTEGER NOT NULL,
    step_index INTEGER NOT NULL,
    label TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'open',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY(task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE(task_id, step_index)
);
CREATE INDEX IF NOT EXISTS ix_task_steps_task ON task_steps(task_id,step_index);

-- v0.3.9 / progression resource ledger ------------------------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '7')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS resource_inventory_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    character_id INTEGER NOT NULL,
    source_type TEXT NOT NULL,
    source_ref TEXT NULL,
    source_sha256 TEXT NULL,
    captured_at TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    entry_count INTEGER NOT NULL DEFAULT 0,
    FOREIGN KEY(character_id) REFERENCES characters(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_resource_inventory_snapshots_character
    ON resource_inventory_snapshots(character_id,id DESC);

CREATE TABLE IF NOT EXISTS resource_inventory_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id INTEGER NOT NULL,
    resource_name_norm TEXT NOT NULL,
    resource_name TEXT NOT NULL,
    resource_kind TEXT NOT NULL DEFAULT 'item_or_resource',
    item_id INTEGER NULL,
    quantity INTEGER NOT NULL DEFAULT 0,
    location_label TEXT NULL,
    raw_json TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY(snapshot_id) REFERENCES resource_inventory_snapshots(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_resource_inventory_entries_snapshot_name
    ON resource_inventory_entries(snapshot_id,resource_name_norm);

CREATE VIEW IF NOT EXISTS v_latest_resource_inventory_snapshots AS
SELECT s.* FROM resource_inventory_snapshots s
WHERE s.id=(
    SELECT s2.id FROM resource_inventory_snapshots s2
    WHERE s2.character_id=s.character_id
    ORDER BY s2.id DESC LIMIT 1
);

-- v0.4 / global target catalog ----------------------------------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '8')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS target_sources (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_key TEXT NOT NULL UNIQUE,
    label TEXT NOT NULL,
    source_kind TEXT NOT NULL,
    root_title TEXT NULL,
    root_url TEXT NULL,
    scope TEXT NOT NULL DEFAULT 'global',
    enabled INTEGER NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS target_job_snapshots (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id INTEGER NOT NULL,
    job TEXT NOT NULL,
    page_title TEXT NOT NULL,
    canonical_url TEXT NOT NULL,
    revision_id INTEGER NULL,
    revision_timestamp TEXT NULL,
    fetched_at TEXT NOT NULL,
    imported_at TEXT NOT NULL,
    status TEXT NOT NULL,
    set_count INTEGER NOT NULL DEFAULT 0,
    target_count INTEGER NOT NULL DEFAULT 0,
    assumptions_json TEXT NOT NULL DEFAULT '{}',
    warnings_json TEXT NOT NULL DEFAULT '[]',
    error_message TEXT NULL,
    FOREIGN KEY(source_id) REFERENCES target_sources(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_target_job_snapshots_source_job ON target_job_snapshots(source_id,job,id DESC);

CREATE TABLE IF NOT EXISTS target_sets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    snapshot_id INTEGER NOT NULL,
    set_key TEXT NOT NULL,
    set_name TEXT NOT NULL,
    variant_name TEXT NOT NULL,
    source_order INTEGER NOT NULL DEFAULT 0,
    notes TEXT NULL,
    FOREIGN KEY(snapshot_id) REFERENCES target_job_snapshots(id) ON DELETE CASCADE,
    UNIQUE(snapshot_id,set_key)
);
CREATE INDEX IF NOT EXISTS ix_target_sets_snapshot ON target_sets(snapshot_id,source_order);

CREATE TABLE IF NOT EXISTS target_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    set_id INTEGER NOT NULL,
    position INTEGER NOT NULL,
    slot_name TEXT NULL,
    source_item_name TEXT NOT NULL,
    item_name_norm TEXT NOT NULL,
    details_json TEXT NOT NULL DEFAULT '[]',
    canonical_item_name TEXT NULL,
    canonical_item_norm TEXT NULL,
    canonical_item_id INTEGER NULL,
    canonical_page_title TEXT NULL,
    identity_status TEXT NOT NULL DEFAULT 'PENDING',
    route_status TEXT NOT NULL DEFAULT 'PENDING',
    augment_status TEXT NOT NULL DEFAULT 'PENDING',
    freshness_status TEXT NOT NULL DEFAULT 'PENDING',
    chain_status TEXT NOT NULL DEFAULT 'PENDING',
    strict_status TEXT NOT NULL DEFAULT 'PENDING',
    system_keys_json TEXT NOT NULL DEFAULT '[]',
    review_reason TEXT NULL,
    verification_json TEXT NOT NULL DEFAULT '{}',
    verified_at TEXT NULL,
    FOREIGN KEY(set_id) REFERENCES target_sets(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_target_items_set ON target_items(set_id,position);
CREATE INDEX IF NOT EXISTS ix_target_items_norm ON target_items(item_name_norm);
CREATE INDEX IF NOT EXISTS ix_target_items_strict ON target_items(strict_status,identity_status,route_status,freshness_status);

-- Source labels remain immutable target-source truth.  Link targets and the
-- derived identity audit live beside the target row so canonicalization never
-- overwrites what the guide actually displayed.
CREATE TABLE IF NOT EXISTS target_item_identity_evidence (
    target_item_id INTEGER PRIMARY KEY,
    source_link_title TEXT NULL,
    source_link_url TEXT NULL,
    identity_match_kind TEXT NOT NULL DEFAULT 'PENDING',
    source_anomaly_status TEXT NOT NULL DEFAULT 'PENDING',
    evidence_json TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL,
    FOREIGN KEY(target_item_id) REFERENCES target_items(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS ix_target_identity_anomaly
    ON target_item_identity_evidence(source_anomaly_status,identity_match_kind);

CREATE VIEW IF NOT EXISTS v_latest_target_job_snapshots AS
SELECT s.* FROM target_job_snapshots s
WHERE s.id=(
    SELECT s2.id FROM target_job_snapshots s2
    WHERE s2.source_id=s.source_id AND s2.job=s.job AND s2.status='OK'
    ORDER BY s2.id DESC LIMIT 1
);

DROP VIEW IF EXISTS v_latest_target_items;
CREATE VIEW v_latest_target_items AS
SELECT ti.*,ts.set_key,ts.set_name,ts.variant_name,ts.source_order,
       js.job,js.page_title AS target_page_title,js.canonical_url AS target_page_url,
       js.assumptions_json AS target_assumptions_json,
       src.source_key,src.label AS source_label,
       ev.source_link_title,ev.source_link_url,
       COALESCE(ev.identity_match_kind,'PENDING') AS identity_match_kind,
       COALESCE(ev.source_anomaly_status,'PENDING') AS source_anomaly_status,
       COALESCE(ev.evidence_json,'{}') AS identity_evidence_json
FROM target_items ti
JOIN target_sets ts ON ts.id=ti.set_id
JOIN v_latest_target_job_snapshots js ON js.id=ts.snapshot_id
JOIN target_sources src ON src.id=js.source_id
LEFT JOIN target_item_identity_evidence ev ON ev.target_item_id=ti.id;

-- v0.4 checkpoint 2 / unified live-snapshot collector health --------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '9')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

CREATE TABLE IF NOT EXISTS resource_inventory_collection_status (
    snapshot_id INTEGER PRIMARY KEY,
    inventory_status TEXT NOT NULL DEFAULT 'UNKNOWN',
    key_items_status TEXT NOT NULL DEFAULT 'UNKNOWN',
    currencies_status TEXT NOT NULL DEFAULT 'UNKNOWN',
    unresolved_item_names INTEGER NOT NULL DEFAULT 0,
    detail_json TEXT NOT NULL DEFAULT '{}',
    FOREIGN KEY(snapshot_id) REFERENCES resource_inventory_snapshots(id) ON DELETE CASCADE
);

-- v0.4 checkpoint 4 / canonical target identity evidence ------------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '10')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;

-- v0.4 target detail + downstream/latest-tier reconciliation ------------
INSERT INTO schema_meta(key, value) VALUES('schema_version', '11')
ON CONFLICT(key) DO UPDATE SET value=excluded.value;
