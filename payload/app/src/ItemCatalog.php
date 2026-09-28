<?php
declare(strict_types=1);

/**
 * Builds the character's logical item catalog from all currently known sources.
 *
 * Important: an item does not have to exist in GearFinder to exist here. LAC
 * requirements, user goals and BG-Wiki mappings can introduce catalog entries.
 */
final class ItemCatalog
{
    private ?array $catalog = null;
    /** @var array<string,string> alias norm => canonical logical norm */
    private array $aliasToCanonical = [];

    public function __construct(
        private PDO $pdo,
        private int $characterId,
        private int $snapshotId,
        private ?int $augmentSnapshotId = null,
    ) {}

    public function search(string $query = '', string $status = 'all', string $source = 'all', int $limit = 500): array
    {
        $queryNorm = LacProfileParser::normalizeName($query);
        $status = strtolower(trim($status));
        $source = strtolower(trim($source));
        $rows = [];

        foreach ($this->build() as $item) {
            if ($queryNorm !== '') {
                $haystacks = array_merge([$item['name_norm']], $item['alias_norms'] ?? []);
                $matched = false;
                foreach ($haystacks as $haystack) {
                    if (str_contains((string)$haystack, $queryNorm)) { $matched = true; break; }
                }
                if (!$matched) continue;
            }
            if ($status !== 'all' && strtolower($item['status']) !== $status) continue;
            if ($source !== 'all') {
                $sourceNorms = array_map(static fn(string $s): string => strtolower($s), $item['sources']);
                if (!in_array($source, $sourceNorms, true)) continue;
            }
            $rows[] = $item;
        }

        usort($rows, static function (array $a, array $b): int {
            $priority = ['MISSING'=>0, 'OWNED'=>1, 'OPTIONAL'=>2, 'REFERENCE'=>3];
            $pa = $priority[$a['status']] ?? 9;
            $pb = $priority[$b['status']] ?? 9;
            if ($pa !== $pb) return $pa <=> $pb;
            return strcasecmp($a['name'], $b['name']);
        });

        return array_slice($rows, 0, max(1, min($limit, 2000)));
    }

    public function summary(): array
    {
        $out = ['total'=>0, 'owned'=>0, 'missing'=>0, 'optional'=>0, 'reference'=>0, 'bgwiki'=>0];
        foreach ($this->build() as $item) {
            $out['total']++;
            $key = strtolower($item['status']);
            if (isset($out[$key])) $out[$key]++;
            if ($item['bgwiki_cached']) $out['bgwiki']++;
        }
        return $out;
    }

    public function find(string $name): ?array
    {
        $norm = LacProfileParser::normalizeName($name);
        $catalog = $this->build();
        if (isset($catalog[$norm])) return $catalog[$norm];
        $canonical = $this->aliasToCanonical[$norm] ?? null;
        return $canonical !== null ? ($catalog[$canonical] ?? null) : null;
    }

    public function findById(int $itemId): ?array
    {
        foreach ($this->build() as $item) {
            if (($item['item_id'] ?? null) !== null && (int)$item['item_id'] === $itemId) return $item;
        }
        return null;
    }

    public function ownedCopies(string $name): array
    {
        $norm = LacProfileParser::normalizeName($name);
        $params = [':sid'=>$this->snapshotId, ':norm'=>$norm];
        if ($this->augmentSnapshotId !== null) {
            $params[':asid'] = $this->augmentSnapshotId;
            $sql = "SELECT esi.*,d.name,d.level,d.item_level,
                           abi.id AS bridge_item_id,abi.augment_type AS bridge_type,
                           abi.aug_path AS bridge_path,abi.aug_rank AS bridge_rank,
                           abi.aug_trial AS bridge_trial,abi.trial_complete AS bridge_trial_complete,
                           abi.augment_strings_json AS bridge_strings_json,abi.raw_extra_hex AS bridge_raw_extra,
                           abi.augment_error AS bridge_error
                    FROM equipment_snapshot_items esi
                    JOIN item_definitions d ON d.item_id=esi.item_id
                    LEFT JOIN augment_bridge_items abi
                      ON abi.snapshot_id=:asid AND abi.item_id=esi.item_id
                     AND abi.bag_id=esi.bag_id AND abi.bag_slot=esi.bag_slot
                    WHERE esi.snapshot_id=:sid AND lower(trim(d.name))=:norm
                    ORDER BY esi.bag_id,esi.bag_slot";
        } else {
            $sql = "SELECT esi.*,d.name,d.level,d.item_level,
                           NULL AS bridge_item_id,NULL AS bridge_type,NULL AS bridge_path,NULL AS bridge_rank,
                           NULL AS bridge_trial,NULL AS bridge_trial_complete,NULL AS bridge_strings_json,
                           NULL AS bridge_raw_extra,NULL AS bridge_error
                    FROM equipment_snapshot_items esi
                    JOIN item_definitions d ON d.item_id=esi.item_id
                    WHERE esi.snapshot_id=:sid AND lower(trim(d.name))=:norm
                    ORDER BY esi.bag_id,esi.bag_slot";
        }
        $s = $this->pdo->prepare($sql);
        $s->execute($params);
        return $s->fetchAll();
    }

    public function lacUsage(string $name): array
    {
        $norm = LacProfileParser::normalizeName($name);
        $rows = [];
        $s = $this->pdo->prepare(
            "SELECT 'requirement' AS usage_type,p.job,s.set_key,s.raw_name,s.activation_type,
                    r.slot_name,r.aug_path,r.aug_rank,r.aug_trial,r.augment_strings_json,
                    r.source_line,s.source_path,NULL AS cycle_name,NULL AS option_value,NULL AS is_default
             FROM lac_requirements r
             JOIN lac_sets s ON s.id=r.set_id
             JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id
             WHERE p.character_id=:cid AND r.item_name_norm=:norm
             ORDER BY p.job,s.raw_name,r.slot_name"
        );
        $s->execute([':cid'=>$this->characterId, ':norm'=>$norm]);
        $rows = $s->fetchAll();

        $o = $this->pdo->prepare(
            "SELECT 'option' AS usage_type,p.job,NULL AS set_key,NULL AS raw_name,o.activation_type,
                    o.slot_name,o.aug_path,o.aug_rank,o.aug_trial,o.augment_strings_json,
                    o.source_line,o.source_path,o.cycle_name,o.option_value,o.is_default
             FROM lac_options o
             JOIN v_latest_lac_profiles p ON p.id=o.profile_snapshot_id
             WHERE p.character_id=:cid AND o.item_name_norm=:norm
             ORDER BY p.job,o.cycle_name,o.is_default DESC,o.option_value"
        );
        $o->execute([':cid'=>$this->characterId, ':norm'=>$norm]);
        return array_merge($rows, $o->fetchAll());
    }

    public function relatedGoals(string $name): array
    {
        $norm = LacProfileParser::normalizeName($name);
        $direct = $this->pdo->prepare(
            "SELECT g.*, 'direct' AS relation
             FROM goals g
             WHERE g.character_id=:cid AND g.entity_type='item' AND g.entity_key=:norm"
        );
        $direct->execute([':cid'=>$this->characterId, ':norm'=>$norm]);
        $rows = $direct->fetchAll();

        $set = $this->pdo->prepare(
            "SELECT DISTINCT g.*, 'set_requirement' AS relation
             FROM goals g
             JOIN lac_sets s ON s.set_key=g.entity_key
             JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id AND p.character_id=g.character_id
             JOIN lac_requirements r ON r.set_id=s.id
             WHERE g.character_id=:cid AND g.entity_type='set' AND r.item_name_norm=:norm
             ORDER BY g.id"
        );
        $set->execute([':cid'=>$this->characterId, ':norm'=>$norm]);
        foreach ($set->fetchAll() as $g) {
            $seen = false;
            foreach ($rows as $x) if ((int)$x['id'] === (int)$g['id']) { $seen = true; break; }
            if (!$seen) $rows[] = $g;
        }
        return $rows;
    }

    public function relatedTasks(string $name): array
    {
        $norm = LacProfileParser::normalizeName($name);
        $s = $this->pdo->prepare(
            "SELECT * FROM tasks
             WHERE character_id=:cid AND entity_type='item' AND entity_key=:norm
             ORDER BY CASE status WHEN 'open' THEN 0 WHEN 'doing' THEN 1 ELSE 2 END,id DESC"
        );
        $s->execute([':cid'=>$this->characterId, ':norm'=>$norm]);
        return $s->fetchAll();
    }

    public function targetUsage(string $name): array
    {
        if (!$this->tableOrViewExists('v_latest_target_items')) return [];
        return (new TargetRepository($this->pdo))->usages($name);
    }

    private function tableOrViewExists(string $name): bool
    {
        $s=$this->pdo->prepare("SELECT 1 FROM sqlite_master WHERE (type='table' OR type='view') AND name=:name LIMIT 1");
        $s->execute([':name'=>$name]);
        return (bool)$s->fetchColumn();
    }

    private function build(): array
    {
        if ($this->catalog !== null) return $this->catalog;
        $map = [];

        $ensure = static function (array &$map, string $name, string $norm): array {
            if (!isset($map[$norm])) {
                $map[$norm] = [
                    'name'=>$name,
                    'name_norm'=>$norm,
                    'item_id'=>null,
                    'level'=>null,
                    'item_level'=>null,
                    'superior_level'=>null,
                    'owned_count'=>0,
                    'requirement_count'=>0,
                    'option_count'=>0,
                    'target_count'=>0,
                    'goal_count'=>0,
                    'active_goal_count'=>0,
                    'bgwiki_cached'=>false,
                    'progression_reference_count'=>0,
                    'bgwiki_page_title'=>null,
                    'canonical_variant_title'=>null,
                    'aliases'=>[],
                    'alias_norms'=>[],
                    'jobs'=>[],
                    'sources'=>[],
                    'status'=>'REFERENCE',
                ];
            }
            return $map[$norm];
        };

        // GearFinder/item resource layer: known definitions + current ownership.
        $s = $this->pdo->prepare(
            "SELECT d.item_id,d.name,d.level,d.item_level,d.superior_level,COUNT(esi.id) AS owned_count
             FROM item_definitions d
             LEFT JOIN equipment_snapshot_items esi
               ON esi.item_id=d.item_id AND esi.snapshot_id=:sid
             GROUP BY d.item_id,d.name,d.level,d.item_level,d.superior_level"
        );
        $s->execute([':sid'=>$this->snapshotId]);
        foreach ($s->fetchAll() as $r) {
            $norm = LacProfileParser::normalizeName((string)$r['name']);
            $item = $ensure($map, (string)$r['name'], $norm);
            $item['name'] = (string)$r['name'];
            $item['item_id'] = (int)$r['item_id'];
            $item['level'] = (int)$r['level'];
            $item['item_level'] = (int)$r['item_level'];
            $item['superior_level'] = (int)$r['superior_level'];
            $item['owned_count'] = (int)$r['owned_count'];
            $item['sources']['GearFinder'] = true;
            $map[$norm] = $item;
        }

        // Required LAC items.
        $s = $this->pdo->prepare(
            "SELECT r.item_name,r.item_name_norm,p.job,COUNT(*) AS n
             FROM lac_requirements r
             JOIN lac_sets s ON s.id=r.set_id
             JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id
             WHERE p.character_id=:cid
             GROUP BY r.item_name,r.item_name_norm,p.job"
        );
        $s->execute([':cid'=>$this->characterId]);
        foreach ($s->fetchAll() as $r) {
            $norm = (string)$r['item_name_norm'];
            $item = $ensure($map, (string)$r['item_name'], $norm);
            $item['requirement_count'] += (int)$r['n'];
            $item['jobs'][(string)$r['job']] = true;
            $item['sources']['LAC'] = true;
            $map[$norm] = $item;
        }

        // Selectable/conditional LAC items. These do not automatically count as missing.
        $s = $this->pdo->prepare(
            "SELECT o.item_name,o.item_name_norm,p.job,COUNT(*) AS n
             FROM lac_options o
             JOIN v_latest_lac_profiles p ON p.id=o.profile_snapshot_id
             WHERE p.character_id=:cid
             GROUP BY o.item_name,o.item_name_norm,p.job"
        );
        $s->execute([':cid'=>$this->characterId]);
        foreach ($s->fetchAll() as $r) {
            $norm = (string)$r['item_name_norm'];
            $item = $ensure($map, (string)$r['item_name'], $norm);
            $item['option_count'] += (int)$r['n'];
            $item['jobs'][(string)$r['job']] = true;
            $item['sources']['LAC'] = true;
            $map[$norm] = $item;
        }

        // Global guide targets (v0.4). These expand the catalog but are deliberately
        // references, not personal missing requirements. A guide target only becomes
        // MISSING when the user/LAC explicitly adopts it as a goal.
        if ($this->tableOrViewExists('v_latest_target_items')) {
            $s = $this->pdo->query(
                "SELECT source_item_name,item_name_norm,canonical_item_name,canonical_item_norm,canonical_item_id,
                        identity_match_kind,source_anomaly_status,job,COUNT(*) AS n
                 FROM v_latest_target_items
                 GROUP BY source_item_name,item_name_norm,canonical_item_name,canonical_item_norm,canonical_item_id,
                          identity_match_kind,source_anomaly_status,job"
            );
            foreach ($s->fetchAll() as $r) {
                $sourceNorm=(string)$r['item_name_norm'];$sourceName=(string)$r['source_item_name'];
                $safeCanonical=($r['canonical_item_id']??null)!==null
                    && !in_array((string)$r['source_anomaly_status'],['SOURCE_LINK_CONFLICT','AMBIGUOUS_IDENTITY','UNRESOLVED'],true);
                $norm=$safeCanonical&&trim((string)$r['canonical_item_norm'])!==''?(string)$r['canonical_item_norm']:$sourceNorm;
                $display=$safeCanonical&&trim((string)$r['canonical_item_name'])!==''?(string)$r['canonical_item_name']:$sourceName;
                $item=$ensure($map,$display,$norm);
                if($safeCanonical && $sourceNorm!==$norm){
                    $this->aliasToCanonical[$sourceNorm]=$norm;$item['aliases'][$sourceName]=true;$item['alias_norms'][$sourceNorm]=true;
                }
                $item['target_count'] += (int)$r['n'];
                $item['jobs'][(string)$r['job']] = true;
                $item['sources']['TargetGuide'] = true;
                $map[$norm]=$item;
            }
        }

        // Direct item goals.
        $s = $this->pdo->prepare("SELECT * FROM goals WHERE character_id=:cid AND entity_type='item'");
        $s->execute([':cid'=>$this->characterId]);
        foreach ($s->fetchAll() as $r) {
            $norm = (string)$r['entity_key'];
            $name = trim((string)$r['label']) !== '' ? (string)$r['label'] : $norm;
            $item = $ensure($map, $name, $norm);
            $item['goal_count']++;
            if (in_array((string)$r['status'], ['active','planned'], true)) $item['active_goal_count']++;
            $item['sources']['Goals'] = true;
            $map[$norm] = $item;
        }

        // Items inherited from set goals (e.g. Holy Water Set -> Purity Ring).
        $s = $this->pdo->prepare(
            "SELECT r.item_name,r.item_name_norm,COUNT(DISTINCT g.id) AS n,
                    COUNT(DISTINCT CASE WHEN g.status IN ('active','planned') THEN g.id END) AS active_n
             FROM goals g
             JOIN lac_sets s ON s.set_key=g.entity_key
             JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id AND p.character_id=g.character_id
             JOIN lac_requirements r ON r.set_id=s.id
             WHERE g.character_id=:cid AND g.entity_type='set'
             GROUP BY r.item_name,r.item_name_norm"
        );
        $s->execute([':cid'=>$this->characterId]);
        foreach ($s->fetchAll() as $r) {
            $norm = (string)$r['item_name_norm'];
            $item = $ensure($map, (string)$r['item_name'], $norm);
            $item['goal_count'] += (int)$r['n'];
            $item['active_goal_count'] += (int)$r['active_n'];
            $item['sources']['Goals'] = true;
            $map[$norm] = $item;
        }

        // Cached external references can introduce items even when neither inventory nor LAC knows them.
        // A resolved BG-Wiki title may be a concrete variant of a shorter in-game display name
        // (e.g. GearFinder `Kikoku` -> BG-Wiki `Kikoku (Level 119 III)`).  Keep one
        // logical catalog entity and register the wiki title as a navigable alias instead of
        // creating a second REFERENCE item.
        $wikiMapRows = $this->pdo->query('SELECT item_name_norm,item_name,page_title FROM bgwiki_item_map')->fetchAll();
        foreach ($wikiMapRows as $r) {
            $mapNorm = (string)$r['item_name_norm'];
            $title = (string)$r['page_title'];
            $titleNorm = LacProfileParser::normalizeName($title);
            $norm = isset($map[$mapNorm]) ? $mapNorm : (isset($map[$titleNorm]) ? $titleNorm : $mapNorm);
            $display = isset($map[$norm]) ? (string)$map[$norm]['name'] : (string)$r['item_name'];
            $item = $ensure($map, $display, $norm);
            $item['bgwiki_cached'] = true;
            $item['bgwiki_page_title'] = $title;
            if ($titleNorm !== $norm) {
                $this->aliasToCanonical[$titleNorm] = $norm;
                $item['canonical_variant_title'] = $title;
                $item['aliases'][$title] = true;
                $item['alias_norms'][$titleNorm] = true;
            }
            $item['sources']['BG-Wiki'] = true;
            $map[$norm] = $item;
        }

        // Explicit item names found inside structured BG-Wiki progression rows also
        // become catalog references. This keeps upgrade-chain nodes navigable even
        // before their own page has been cached. No names are inferred from patterns.
        $bgRepo = new BgWikiRepository($this->pdo);
        $relationEdges = (new ProgressionChainService($bgRepo))->edges($bgRepo->graphRecords());
        foreach ($relationEdges as $edge) {
            foreach (['from','to'] as $side) {
                $name = trim((string)($edge[$side] ?? ''));
                $rawNorm = LacProfileParser::normalizeName($name);
                if ($name === '' || $rawNorm === '') continue;
                $norm = $this->aliasToCanonical[$rawNorm] ?? $rawNorm;
                $display = isset($map[$norm]) ? (string)$map[$norm]['name'] : $name;
                $item = $ensure($map, $display, $norm);
                if ($rawNorm !== $norm) {
                    $item['aliases'][$name] = true;
                    $item['alias_norms'][$rawNorm] = true;
                }
                $item['progression_reference_count']++;
                $item['sources']['BG-Wiki'] = true;
                $map[$norm] = $item;
            }
        }

        foreach ($map as $norm => $item) {
            $item['jobs'] = array_keys($item['jobs']);
            sort($item['jobs']);
            $item['sources'] = array_keys($item['sources']);
            $item['aliases'] = array_keys($item['aliases']);
            $item['alias_norms'] = array_keys($item['alias_norms']);
            if ($item['owned_count'] > 0) $item['status'] = 'OWNED';
            elseif ($item['requirement_count'] > 0 || $item['active_goal_count'] > 0) $item['status'] = 'MISSING';
            elseif ($item['option_count'] > 0) $item['status'] = 'OPTIONAL';
            else $item['status'] = 'REFERENCE';
            $map[$norm] = $item;
        }

        return $this->catalog = $map;
    }
}
