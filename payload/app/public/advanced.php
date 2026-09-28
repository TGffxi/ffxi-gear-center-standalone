<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
require_once dirname(__DIR__) . '/src/RuntimePlanningOverlayExporter.php';

$root = dirname(__DIR__);
$config = Config::load($root);
$error = null;
$notice = null;
$snapshot = null;
$characterId = 0;
$matcher = null;
$bridgeSnapshot = null;
$profiles = [];

$view = (string)($_GET['view'] ?? 'sources');
$job = strtoupper((string)($_GET['job'] ?? 'NIN'));
$setId = (int)($_GET['set'] ?? 0);
$itemId = (int)($_GET['item'] ?? 0);
$itemNameQuery = trim((string)($_GET['name'] ?? ''));
$instanceContainer = isset($_GET['instance_container']) && $_GET['instance_container'] !== '' ? (int)$_GET['instance_container'] : null;
$instanceSlot = isset($_GET['instance_slot']) && $_GET['instance_slot'] !== '' ? (int)$_GET['instance_slot'] : null;
$instanceExtra = strtolower((string)preg_replace('/[^0-9a-f]/i', '', (string)($_GET['instance_extra'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$bag = isset($_GET['bag']) && $_GET['bag'] !== '' ? (int)$_GET['bag'] : null;
$augmentedOnly = ($_GET['augmented'] ?? '') === '1';
$itemStatusFilter = strtolower((string)($_GET['status'] ?? 'all'));
$itemSourceFilter = strtolower((string)($_GET['source'] ?? 'all'));
$coverageJob = strtoupper((string)($_GET['covjob'] ?? 'ALL'));
$coverageFilter = strtoupper((string)($_GET['coverage'] ?? 'ALL'));
$targetJob = strtoupper((string)($_GET['target_job'] ?? 'ALL'));
$targetStrict = strtoupper((string)($_GET['target_strict'] ?? 'ALL'));
$targetOwnership = strtoupper((string)($_GET['target_owned'] ?? 'ALL'));
$compact = (($_GET['compact'] ?? '') === '1');
$returnTab = (string)($_GET['return'] ?? 'overview');
if (!in_array($returnTab, ['overview','inventory','plan','farming','performance','character','diagnostics'], true)) $returnTab = 'overview';

function h(mixed $v): string
{
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function advancedUrl(string $view, array $params = []): string
{
    global $compact, $returnTab;
    $query = array_merge(['view'=>$view], $params);
    if ($compact) {
        $query['compact'] = '1';
        $query['return'] = $returnTab;
    }
    return '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
}

function itemUrl(string $name): string
{
    return advancedUrl('item', ['name'=>$name]);
}

function normalizedExtraHex(mixed $value): string
{
    return strtolower((string)preg_replace('/[^0-9a-f]/i', '', (string)$value));
}

/** Preserve the exact physical inventory copy when drilling into an owned item. */
function physicalItemUrl(array $row): string
{
    $params = [];
    if ((int)($row['item_id'] ?? 0) > 0) $params['item'] = (int)$row['item_id'];
    else $params['name'] = (string)($row['name'] ?? '');
    if (isset($row['bag_id'], $row['bag_slot'])) {
        $params['instance_container'] = (int)$row['bag_id'];
        $params['instance_slot'] = (int)$row['bag_slot'];
    }
    $extra = normalizedExtraHex($row['bridge_raw_extra'] ?? '');
    if ($extra !== '') $params['instance_extra'] = $extra;
    return advancedUrl('item', $params);
}

function statusClass(string $s): string
{
    return match ($s) {
        'OWNED', 'EXACT' => 'good',
        'OWNED_UNVERIFIED', 'OPTIONAL' => 'warn',
        'AUGMENT_MISMATCH', 'MISSING' => 'bad',
        default => 'muted',
    };
}

function coverageClass(string $s): string
{
    return match ($s) {
        'FULL' => 'good',
        'ACQUISITION_ONLY', 'PARTIAL', 'SYSTEM_KNOWN_DATA_MISSING' => 'warn',
        'UNRESOLVED' => 'bad',
        default => 'muted',
    };
}

function verificationClass(string $s): string
{
    return match ($s) {
        'VERIFIED', 'CURRENT_VERIFIED', 'NOT_APPLICABLE', 'NONE' => 'good',
        'NEWER_TIER_AVAILABLE', 'REVIEW_REQUIRED', 'REVIEW', 'SOURCE_LABEL_MISMATCH', 'UNVERIFIED_ALIAS', 'AMBIGUOUS_IDENTITY' => 'warn',
        'ERROR', 'UNRESOLVED', 'SOURCE_LINK_CONFLICT' => 'bad',
        default => 'muted',
    };
}

function targetText(array $r): string
{
    $p = [];
    if (($r['aug_path'] ?? null) !== null) $p[] = 'Path ' . $r['aug_path'];
    if (($r['aug_rank'] ?? null) !== null) $p[] = 'Rank ' . $r['aug_rank'];
    if (($r['aug_trial'] ?? null) !== null) $p[] = 'Trial ' . $r['aug_trial'];
    $a = json_decode((string)($r['augment_strings_json'] ?? '[]'), true);
    if (is_array($a) && $a) $p[] = implode(' · ', $a);
    return $p ? implode(' | ', $p) : 'Base item';
}

function augText(string $json): string
{
    $data = json_decode($json, true);
    if (!is_array($data) || $data === []) return '—';
    $parts = [];
    foreach ($data as $name => $v) {
        if (!is_array($v)) continue;
        $aug = $v['aug'] ?? null;
        if ($aug === null) continue;
        $parts[] = $name . '=' . $aug . (!empty($v['percent']) ? '%' : '');
    }
    return $parts ? implode(', ', $parts) : '—';
}

function bridgeAugText(array $r): string
{
    $p = [];
    $type = (string)($r['bridge_type'] ?? '');
    if ($type !== '' && $type !== 'Unaugmented') $p[] = $type;
    if (($r['bridge_path'] ?? null) !== null) $p[] = 'Path ' . $r['bridge_path'];
    if (($r['bridge_rank'] ?? null) !== null) $p[] = 'Rank ' . $r['bridge_rank'];
    if (($r['bridge_trial'] ?? null) !== null) {
        $p[] = 'Trial ' . $r['bridge_trial'] . (($r['bridge_trial_complete'] ?? null) === 1 ? ' ✓' : '');
    }
    $a = json_decode((string)($r['bridge_strings_json'] ?? '[]'), true);
    if (is_array($a)) foreach ($a as $x) $p[] = (string)$x;
    return $p ? implode(' · ', $p) : ($type === 'Unaugmented' ? 'Unaugmented' : '—');
}

function bgSearch(string $name): string
{
    return BgWikiClient::searchUrl($name);
}

function bgSectionLabel(string $section): string
{
    $key = strtolower(trim($section, " \t\n\r\0\x0B.:"));
    $map = [
        'obtained from'=>'Erhältlich durch', 'obtained from instance'=>'Erhältlich aus Instanz',
        'purchased from'=>'Kaufbar bei', 'redeemed from'=>'Eintausch bei', 'dropped from'=>'Drop von',
        'synthesis'=>'Synthese', 'synthesis information'=>'Synthese', 'synergy'=>'Synergie', 'synergy information'=>'Synergie',
        'augmented via'=>'Augment via', 'upgraded from'=>'Upgrade von', 'used in'=>'Verwendet für', 'upgrade info'=>'Upgrade-Information',
    ];
    if (isset($map[$key])) return $map[$key];
    foreach ($map as $prefix=>$label) if (str_starts_with($key, $prefix)) return $label;
    return $section;
}

$rows = [];
$bagSummary = [];
$sets = [];
$selectedSet = null;
$setProgress = null;
$goalCards = [];
$tasks = [];
$health = [];
$diff = null;
$readiness = null;
$item = null;
$itemOwned = [];
$selectedOwned = null;
$selectedInstanceError = null;
$itemUsage = [];
$itemGoals = [];
$itemTasks = [];
$bgPage = null;
$bgRecords = [];
$acquisitionRoutes = [];
$progressionChain = ['has_data'=>false,'nodes'=>[],'upstream_paths'=>[],'downstream_paths'=>[],'actions'=>[],'augment_options'=>[],'plans'=>[]];
$progressionResources = ['has_data'=>false,'plans'=>[],'holding_count'=>0];
$resourceInventoryStatus = ['status'=>'PENDING','message'=>'Resource inventory not initialized.','snapshot'=>null];
$liveSqliteSync = ['status'=>'NOT_RUN','message'=>'Live SQLite synchronization has not run yet.'];
$options = [];
$structuredAug = [];
$catalogRows = [];
$catalogSummary = ['total'=>0,'owned'=>0,'missing'=>0,'optional'=>0,'reference'=>0,'bgwiki'=>0];
$catalog = null;
$coverageReport = ['total'=>0,'counts'=>[],'need_counts'=>[],'modeled_percent'=>0,'full_percent'=>0,'gap_systems'=>[],'items'=>[]];
$latestTierAudit = ['knowledge_version'=>SystemFreshnessRegistry::KNOWLEDGE_VERSION,'verified_through'=>SystemFreshnessRegistry::VERIFIED_THROUGH,'total'=>0,'tier_relevant'=>0,'counts'=>[],'latest_awareness_percent'=>0,'post_2024_verified_percent'=>0,'rows'=>[],'timeline'=>[]];
$targetSystems = [];
$targetReport = ['summary'=>['jobs'=>0,'sets'=>0,'targets'=>0,'unique_items'=>0,'statuses'=>[]],'jobs'=>[],'systems'=>[],'queues'=>[]];
$targetRows = [];
$globalTargetSummary = ['jobs'=>0,'sets'=>0,'targets'=>0,'unique_items'=>0,'statuses'=>[]];

try {
    $pdo = Database::connect($config['db_path']);
    Database::migrate($pdo, $root . '/sql/schema.sql');

    $st = $pdo->prepare(
        'SELECT s.*,c.id AS character_id,c.name AS character_name,c.server_id
         FROM v_latest_snapshots s
         JOIN characters c ON c.id=s.character_id
         WHERE lower(c.name)=lower(:name)
         LIMIT 1'
    );
    $st->execute([':name'=>$config['character']]);
    $snapshot = $st->fetch() ?: null;

    if ($snapshot) {
        $characterId = (int)$snapshot['character_id'];
        // Keep the historical SQLite item-detail/resource world aligned with the
        // latest complete local LuAshitacast snapshot.  This is lazy, hash-idempotent
        // and last-known-good safe; partial/pre-login frames never clear DB state.
        $liveSqliteSync = (new LiveStateSynchronizer($pdo, $config))->sync(
            $characterId,
            $snapshot['server_id'] !== null ? (int)$snapshot['server_id'] : null,
        );
        $matcher = new RequirementMatcher($pdo, $characterId, (int)$snapshot['id']);
        $goalRepo = new GoalRepository($pdo, $characterId);
        $taskRepo = new TaskRepository($pdo, $characterId);

        $bs = $pdo->prepare('SELECT * FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');
        $bs->execute([':cid'=>$characterId]);
        $bridgeSnapshot = $bs->fetch() ?: null;

        $ps = $pdo->prepare(
            "SELECT * FROM v_latest_lac_profiles
             WHERE character_id=:cid
             ORDER BY CASE job WHEN 'NIN' THEN 0 WHEN 'THF' THEN 1 WHEN 'COMMON' THEN 2 ELSE 9 END,job"
        );
        $ps->execute([':cid'=>$characterId]);
        $profiles = $ps->fetchAll();

        // Available before mutations so progression To-Dos can be bound to the
        // exact live physical copy instead of silently choosing another duplicate.
        $mutationCatalog = new ItemCatalog(
            $pdo,
            $characterId,
            (int)$snapshot['id'],
            $bridgeSnapshot ? (int)$bridgeSnapshot['id'] : null,
        );

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = (string)($_POST['action'] ?? '');
            if ($action === 'goal_save') {
                $goalRepo->update((int)$_POST['id'], (string)$_POST['status'], (string)$_POST['priority'], trim((string)$_POST['note']));
                $notice = 'Ziel aktualisiert.';
            } elseif ($action === 'goal_delete') {
                $goalRepo->delete((int)$_POST['id']);
                $notice = 'Ziel gelöscht.';
            } elseif ($action === 'goal_set') {
                $goalRepo->upsert('set', (string)$_POST['entity_key'], (string)$_POST['label'], 'active', (string)($_POST['priority'] ?? 'normal'), trim((string)($_POST['note'] ?? '')));
                $notice = 'Set zu den Zielen hinzugefügt.';
            } elseif ($action === 'goal_item') {
                $name = trim((string)$_POST['item_name']);
                $goalRepo->upsert('item', LacProfileParser::normalizeName($name), $name, 'active', (string)($_POST['priority'] ?? 'normal'), trim((string)($_POST['note'] ?? '')));
                $notice = 'Item zu den Zielen hinzugefügt.';
            } elseif ($action === 'task_add') {
                $taskRepo->create(
                    trim((string)$_POST['title']),
                    (string)($_POST['task_type'] ?? 'obtain'),
                    (string)($_POST['priority'] ?? 'normal'),
                    trim((string)($_POST['content_name'] ?? '')) ?: null,
                    trim((string)($_POST['note'] ?? '')),
                    trim((string)($_POST['entity_type'] ?? '')) ?: null,
                    trim((string)($_POST['entity_key'] ?? '')) ?: null,
                );
                $notice = 'Aufgabe erstellt.';
            } elseif ($action === 'task_save') {
                $taskRepo->update((int)$_POST['id'], (string)$_POST['status'], (string)$_POST['priority'], trim((string)$_POST['note']));
                $notice = 'Aufgabe aktualisiert.';
            } elseif ($action === 'task_step') {
                $taskRepo->updateStep((int)$_POST['step_id'], (string)$_POST['step_status']);
                $notice = 'Aufgabenschritt aktualisiert.';
            } elseif ($action === 'route_task_add') {
                $routeItem = trim((string)($_POST['item_name'] ?? ''));
                $routeKey = trim((string)($_POST['route_key'] ?? ''));
                $bgForRoute = new BgWikiRepository($pdo);
                $pageForRoute = $bgForRoute->byItem($routeItem);
                $recordsForRoute = $bgForRoute->progressionRecordsByItem($routeItem);
                $route = (new AcquisitionRouteService())->find($routeItem, $recordsForRoute, $pageForRoute, $routeKey);
                if (!$route) throw new RuntimeException('Acquisition route is no longer available; refresh the BG-Wiki cache.');
                $taskRepo->createRoute($route, (string)($_POST['priority'] ?? 'normal'), $routeItem, LacProfileParser::normalizeName($routeItem));
                $notice = 'Bezugsweg als To-Do hinzugefügt.';
            } elseif ($action === 'progression_task_add') {
                $progressItem = trim((string)($_POST['item_name'] ?? ''));
                $planKey = trim((string)($_POST['plan_key'] ?? ''));
                $bgForPlan = new BgWikiRepository($pdo);
                $plan = (new ProgressionChainService($bgForPlan))->findPlan($progressItem, $planKey);
                if (!$plan) throw new RuntimeException('Progression plan is no longer available; refresh the BG-Wiki cache.');
                $onlyAugmentActions = !empty($plan['actions']);
                foreach (($plan['actions'] ?? []) as $pa) {
                    if (($pa['kind'] ?? '') !== 'augment') { $onlyAugmentActions = false; break; }
                }

                if (!empty($plan['fields']['current_owned']) && $onlyAugmentActions) {
                    if (!$bridgeSnapshot) {
                        throw new RuntimeException('Live-Augment verification is required before creating an augment/rank task for an already-owned item.');
                    }

                    $copies = $mutationCatalog->ownedCopies($progressItem);
                    $liveCopies = array_values(array_filter($copies, static fn(array $copy): bool => ($copy['bridge_item_id'] ?? null) !== null));
                    $postedContainer = isset($_POST['instance_container']) && $_POST['instance_container'] !== '' ? (int)$_POST['instance_container'] : null;
                    $postedSlot = isset($_POST['instance_slot']) && $_POST['instance_slot'] !== '' ? (int)$_POST['instance_slot'] : null;
                    $postedExtra = normalizedExtraHex($_POST['instance_extra'] ?? '');
                    $selectedForTask = null;

                    if ($postedContainer !== null && $postedSlot !== null) {
                        foreach ($copies as $copy) {
                            if ((int)$copy['bag_id'] !== $postedContainer || (int)$copy['bag_slot'] !== $postedSlot) continue;
                            if (($copy['bridge_item_id'] ?? null) === null) {
                                throw new RuntimeException('The selected physical item has no current live augment reading. Refresh FFXI inventory data and select the item again.');
                            }
                            $sourceExtra = normalizedExtraHex($copy['bridge_raw_extra'] ?? '');
                            if (strlen($postedExtra) === 48 && strlen($sourceExtra) === 48 && $postedExtra !== $sourceExtra) {
                                throw new RuntimeException('The selected physical item no longer matches the current live augment snapshot. Refresh inventory and select that copy again.');
                            }
                            $selectedForTask = $copy;
                            break;
                        }
                        if ($selectedForTask === null) {
                            throw new RuntimeException('The selected physical item is no longer present at that container/slot. Refresh inventory and select it again.');
                        }
                    } elseif (count($liveCopies) === 1) {
                        $selectedForTask = $liveCopies[0];
                    } elseif (count($liveCopies) > 1) {
                        throw new RuntimeException('Multiple live copies of this item exist. Open the exact copy from Inventory before creating an augment/rank To-Do.');
                    } else {
                        throw new RuntimeException('No current live augment reading exists for this owned item. Refresh FFXI inventory data first.');
                    }

                    $taskExtra = normalizedExtraHex($selectedForTask['bridge_raw_extra'] ?? '');
                    $plan['fields']['selected_instance'] = [
                        'item_id'=>(int)$selectedForTask['item_id'],
                        'container'=>(int)$selectedForTask['bag_id'],
                        'slot'=>(int)$selectedForTask['bag_slot'],
                        'extra_hex'=>$taskExtra !== '' ? $taskExtra : null,
                    ];
                }

                $taskRepo->createRoute($plan, (string)($_POST['priority'] ?? 'normal'), $progressItem, LacProfileParser::normalizeName($progressItem));
                $notice = 'Progression chain added to To-Do.';
            }
            // Keep the normal gp10 runtime in sync with the live-tested SQLite
            // goals/To-Dos immediately after every successful mutation. A failed
            // sync is visible to the user; it must never silently leave two goal worlds.
            $overlayPath = trim((string)(getenv('GEARCENTER_RUNTIME_OVERLAY') ?: ''));
            if ($overlayPath !== '') {
                $bridgeId = $bridgeSnapshot ? (int)$bridgeSnapshot['id'] : null;
                (new RuntimePlanningOverlayExporter($pdo, $characterId, (int)$snapshot['id'], $bridgeId))->write($overlayPath);
            }
        }

        $catalog = new ItemCatalog(
            $pdo,
            $characterId,
            (int)$snapshot['id'],
            $bridgeSnapshot ? (int)$bridgeSnapshot['id'] : null,
        );
        $catalogSummary = $catalog->summary();
        $globalTargetSummary = (new TargetRepository($pdo))->summary();

        $health = (new SourceHealth($pdo, $config, $root, $characterId))->report();
        $diff = (new SnapshotDiffer($pdo, $characterId))->latest();
        $tasks = $taskRepo->all();

        if ($view === 'inventory') {
            $sum = $pdo->prepare(
                'SELECT bag_id,bag_name,COUNT(*) AS n
                 FROM equipment_snapshot_items
                 WHERE snapshot_id=:sid
                 GROUP BY bag_id,bag_name
                 ORDER BY bag_id'
            );
            $sum->execute([':sid'=>$snapshot['id']]);
            $bagSummary = $sum->fetchAll();

            $where = ['esi.snapshot_id=:sid'];
            $params = [':sid'=>$snapshot['id']];
            if ($q !== '') {
                $where[] = 'd.name LIKE :q';
                $params[':q'] = '%' . $q . '%';
            }
            if ($bag !== null) {
                $where[] = 'esi.bag_id=:bag';
                $params[':bag'] = $bag;
            }
            if ($augmentedOnly) $where[] = "esi.augment_signature <> 'none'";

            if ($bridgeSnapshot) {
                $params[':asid'] = $bridgeSnapshot['id'];
                $sql = 'SELECT esi.*,d.name,d.level,d.item_level,d.superior_level,
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
                        WHERE ' . implode(' AND ', $where) . '
                        ORDER BY esi.bag_id,esi.bag_slot';
            } else {
                $sql = 'SELECT esi.*,d.name,d.level,d.item_level,d.superior_level,
                               NULL AS bridge_item_id,NULL AS bridge_type,NULL AS bridge_path,NULL AS bridge_rank,
                               NULL AS bridge_trial,NULL AS bridge_trial_complete,NULL AS bridge_strings_json,
                               NULL AS bridge_raw_extra,NULL AS bridge_error
                        FROM equipment_snapshot_items esi
                        JOIN item_definitions d ON d.item_id=esi.item_id
                        WHERE ' . implode(' AND ', $where) . '
                        ORDER BY esi.bag_id,esi.bag_slot';
            }
            $it = $pdo->prepare($sql);
            $it->execute($params);
            $rows = $it->fetchAll();

            $ua = $pdo->prepare(
                'SELECT DISTINCT item_name_norm FROM (
                   SELECT r.item_name_norm
                   FROM lac_requirements r
                   JOIN lac_sets s ON s.id=r.set_id
                   JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id
                   WHERE p.character_id=:cid1
                     AND (r.aug_path IS NOT NULL OR r.aug_rank IS NOT NULL OR r.aug_trial IS NOT NULL)
                   UNION
                   SELECT o.item_name_norm
                   FROM lac_options o
                   JOIN v_latest_lac_profiles p ON p.id=o.profile_snapshot_id
                   WHERE p.character_id=:cid2
                     AND (o.aug_path IS NOT NULL OR o.aug_rank IS NOT NULL OR o.aug_trial IS NOT NULL)
                 )'
            );
            $ua->execute([':cid1'=>$characterId, ':cid2'=>$characterId]);
            foreach ($ua->fetchAll() as $r) $structuredAug[$r['item_name_norm']] = true;
        } elseif ($view === 'items') {
            $catalogRows = $catalog->search($q, $itemStatusFilter, $itemSourceFilter, 1000);
        } elseif ($view === 'coverage') {
            // GP08_CONTEXT_COVERAGE_CACHE_BEGIN
            $coverageReport = (new CoverageCache(
                $pdo, $root, (string)$config['db_path'], $characterId, (int)$snapshot['id'],
                $bridgeSnapshot ? (int)$bridgeSnapshot['id'] : null
            ))->loadOrTrigger($coverageJob);
            // GP08_CONTEXT_COVERAGE_CACHE_END
            $latestTierAudit = (new LatestTierAudit())->report($coverageReport);
        } elseif ($view === 'targets') {
            $targetRepo = new TargetRepository($pdo);
            $targetReport = (new TargetCoverageReport($pdo))->report();
            foreach ($targetRepo->browse($targetJob, $targetStrict, $q, 5000) as $targetRow) {
                $displayName = trim((string)($targetRow['canonical_item_name'] ?: $targetRow['source_item_name']));
                $catalogItem = $catalog->find($displayName) ?? $catalog->find((string)$targetRow['source_item_name']);
                $targetRow['display_item_name'] = $displayName;
                $targetRow['owned_count'] = (int)($catalogItem['owned_count'] ?? 0);
                $targetRow['catalog_status'] = (string)($catalogItem['status'] ?? 'REFERENCE');
                $targetRow['details'] = json_decode((string)($targetRow['details_json'] ?? '[]'), true) ?: [];
                if ($targetOwnership === 'OWNED' && $targetRow['owned_count'] < 1) continue;
                if ($targetOwnership === 'NOT_OWNED' && $targetRow['owned_count'] > 0) continue;
                $targetRows[] = $targetRow;
            }
        } elseif ($view === 'jobs') {
            $profileByJob = [];
            foreach ($profiles as $p) $profileByJob[$p['job']] = $p;
            if (!isset($profileByJob[$job]) && $profiles) $job = $profiles[0]['job'];
            if (isset($profileByJob[$job])) {
                $readiness = (new ReadinessService($pdo, $matcher))->profile((int)$profileByJob[$job]['id']);
                $sets = $readiness['sets'];
                if ($setId) {
                    foreach ($sets as $s) {
                        if ((int)$s['id'] === $setId) {
                            $selectedSet = $s;
                            $setProgress = $s['progress'];
                            break;
                        }
                    }
                }
                $os = $pdo->prepare('SELECT * FROM lac_options WHERE profile_snapshot_id=:pid ORDER BY cycle_name,is_default DESC,option_value');
                $os->execute([':pid'=>$profileByJob[$job]['id']]);
                $options = $os->fetchAll();
            }
        } elseif ($view === 'goals') {
            foreach ($goalRepo->all() as $g) {
                $card = ['goal'=>$g, 'set'=>null, 'progress'=>null];
                if ($g['entity_type'] === 'set') {
                    $s = $pdo->prepare(
                        'SELECT ls.*
                         FROM lac_sets ls
                         JOIN v_latest_lac_profiles p ON p.id=ls.profile_snapshot_id
                         WHERE p.character_id=:cid AND ls.set_key=:key
                         ORDER BY ls.id DESC LIMIT 1'
                    );
                    $s->execute([':cid'=>$characterId, ':key'=>$g['entity_key']]);
                    $set = $s->fetch() ?: null;
                    if ($set) {
                        $card['set'] = $set;
                        $card['progress'] = $matcher->setProgress((int)$set['id']);
                    }
                } elseif ($g['entity_type'] === 'item') {
                    $card['item'] = $catalog->find((string)$g['entity_key']);
                }
                $goalCards[] = $card;
            }
        } elseif ($view === 'item') {
            if ($itemNameQuery !== '') $item = $catalog->find($itemNameQuery);
            elseif ($itemId > 0) $item = $catalog->findById($itemId);

            if ($item) {
                $itemOwned = $catalog->ownedCopies($item['name']);
                if ($instanceContainer !== null && $instanceSlot !== null) {
                    foreach ($itemOwned as $copy) {
                        if ((int)$copy['bag_id'] !== $instanceContainer || (int)$copy['bag_slot'] !== $instanceSlot) continue;
                        $sourceExtra = strtolower((string)preg_replace('/[^0-9a-f]/i', '', (string)($copy['bridge_raw_extra'] ?? '')));
                        $requestedComplete = strlen($instanceExtra) === 48;
                        $sourceComplete = strlen($sourceExtra) === 48;
                        if ($requestedComplete && $sourceComplete && $instanceExtra !== $sourceExtra) {
                            $selectedInstanceError = 'Das angeklickte physische Exemplar passt nicht mehr zum aktuellen Augment-Snapshot. Bitte Inventar aktualisieren und das Item erneut öffnen.';
                        } else {
                            $selectedOwned = $copy;
                        }
                        break;
                    }
                    if ($selectedOwned === null && $selectedInstanceError === null) {
                        $selectedInstanceError = 'Das angeklickte physische Exemplar ist im aktuellen Snapshot nicht mehr an diesem Container/Slot vorhanden.';
                    }
                }
                $itemUsage = $catalog->lacUsage($item['name']);
                $itemGoals = $catalog->relatedGoals($item['name']);
                $itemTasks = $taskRepo->enrichMany($catalog->relatedTasks($item['name']));
                $bgRepo = new BgWikiRepository($pdo);
                $bgPage = $bgRepo->byItem($item['name']);
                $bgRecords = $bgRepo->progressionRecordsByItem($item['name']);
                $targetSystems = (new ProgressionSystemService())->targetHints($item['name'], $itemUsage, $bgRecords);
                $acquisitionRoutes = array_values(array_filter((new AcquisitionRouteService())->build($item['name'], $bgRecords, $bgPage), static fn($r)=>($r['group']??'')==='Acquisition'));
                $chainService = new ProgressionChainService($bgRepo);
                $progressionChain = $chainService->build($item['name']);
                if (($item['status'] ?? '') === 'OWNED') {
                    $progressionChain = $chainService->forOwnedFocus($progressionChain);
                    if (!$bridgeSnapshot) $progressionChain['live_verification_pending'] = true;
                }
                $resourceRepo = new ResourceInventoryRepository($pdo, $characterId);
                $resourceInventoryStatus = $resourceRepo->status();
                $liveRows = $selectedOwned !== null
                    ? ((($selectedOwned['bridge_item_id'] ?? null) !== null) ? [$selectedOwned] : [])
                    : array_values(array_filter($itemOwned, static fn(array $r): bool => ($r['bridge_item_id'] ?? null) !== null));
                $liveAugment = [
                    'copy_count'=>count($liveRows),
                    'rank'=>count($liveRows) === 1 && ($liveRows[0]['bridge_rank'] ?? null) !== null ? (int)$liveRows[0]['bridge_rank'] : null,
                    'path'=>count($liveRows) === 1 ? ($liveRows[0]['bridge_path'] ?? null) : null,
                    'type'=>count($liveRows) === 1 ? ($liveRows[0]['bridge_type'] ?? null) : null,
                    'selected_physical_instance'=>$selectedOwned !== null,
                ];
                $progressionResources = (new ProgressionResourceService())->summarize($progressionChain, $resourceRepo->holdings(), $liveAugment, $item);
            }
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$profileCount = count($profiles);
$healthBad = count(array_filter($health, static fn($x) => $x['status'] === 'ERROR'));
$healthPending = count(array_filter($health, static fn($x) => $x['status'] === 'PENDING'));
$appVersion = trim((string)file_get_contents($root.'/VERSION'));
?>
<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($config['app_name'])?></title>
<meta name="theme-color" content="#151b23">
<link rel="manifest" href="/manifest.webmanifest">
<style>
:root{color-scheme:dark;--bg:#0b0f14;--panel:#151b23;--panel2:#1b2430;--text:#edf2f7;--muted:#9aa7b5;--line:#2b3746;--good:#6ee7a8;--warn:#ffd166;--bad:#ff7b7b;--accent:#7cb7ff}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.45 system-ui,sans-serif}a{color:inherit;text-decoration:none}.wrap{max-width:1580px;margin:auto;padding:22px}.top{display:flex;justify-content:space-between;align-items:end;gap:18px;flex-wrap:wrap}h1,h2,h3,h4{margin:.25em 0}.muted{color:var(--muted)}.good{color:var(--good)}.warn{color:var(--warn)}.bad{color:var(--bad)}.nav,.chips{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.nav a,.pill,.chip{padding:7px 11px;background:var(--panel2);border:1px solid var(--line);border-radius:999px}.nav a.active{border-color:var(--accent);color:var(--accent)}.cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin:16px 0}.card,.setcard{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:14px}.big{display:block;font-size:22px;margin-top:3px}.setgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));gap:10px}.setcard:hover{border-color:#52647a}.toolbar{display:flex;gap:8px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--line);padding:10px;border-radius:12px;margin-bottom:12px}input,select,textarea,button{background:#0f141b;color:var(--text);border:1px solid var(--line);border-radius:8px;padding:8px 10px}input[type=text]{min-width:220px}textarea{width:100%;min-height:64px}button{cursor:pointer}.tablebox{overflow:auto;border:1px solid var(--line);border-radius:12px;background:var(--panel)}table{border-collapse:collapse;width:100%;min-width:850px}th,td{padding:9px 10px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{position:sticky;top:0;background:var(--panel2);z-index:1}.badge{display:inline-block;padding:2px 7px;border:1px solid var(--line);border-radius:999px;font-size:12px}.progress{height:7px;background:#0d1218;border-radius:999px;overflow:hidden;margin:8px 0}.progress>span{display:block;height:100%;background:var(--good)}.error,.notice{padding:12px;border-radius:10px;margin:12px 0}.error{background:#3a1515;border:1px solid #733}.notice{background:#123023;border:1px solid #285d45}.group{margin:22px 0}.inlineform{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.bgbox{white-space:pre-wrap;background:#0f141b;padding:10px;border-radius:8px;max-height:320px;overflow:auto}.recordgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:10px;margin:8px 0 16px}.record{background:#0f141b;border:1px solid var(--line);border-radius:9px;padding:10px}.record dl{display:grid;grid-template-columns:minmax(90px,auto) 1fr;gap:5px 10px;margin:0}.record dt{color:var(--muted);font-weight:600}.record dd{margin:0;white-space:pre-line}.empty{padding:25px;text-align:center;color:var(--muted)}details{font-size:12px;color:var(--muted);margin-top:4px}details.routesref{font-size:14px;color:var(--text);background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:12px}details.routesref>summary{cursor:pointer;color:var(--text);font-size:18px;font-weight:700}details.routesref[open]>summary{margin-bottom:12px}code{background:#0f141b;padding:2px 5px;border-radius:5px}.sourcebadges{display:flex;gap:5px;flex-wrap:wrap}.itemstatus{font-weight:700}.mini{font-size:12px}.rowactions{display:flex;gap:5px;flex-wrap:wrap}.nowrap{white-space:nowrap}.routegrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:12px}.route{background:#0f141b;border:1px solid var(--line);border-radius:10px;padding:12px}.route ol{padding-left:22px}.route li{margin:7px 0}.routehead{display:flex;align-items:center;gap:8px;flex-wrap:wrap}.routehead h4{margin-right:auto}.steps{list-style:none;padding:0;margin:10px 0}.steps li{display:flex;gap:8px;align-items:flex-start;margin:7px 0}.steps form{margin:0}.stepdone{text-decoration:line-through;color:var(--muted)}.tinybtn{padding:2px 7px;min-width:28px}.routechoice{border-color:#40556d}.chainpath{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}.chainnode{padding:7px 10px;border:1px solid var(--line);border-radius:9px;background:#0f141b}.chainarrow{color:var(--muted);font-weight:700}.chainaction{border-left:3px solid var(--accent);padding-left:10px;margin:8px 0}.chainmeta{font-size:12px;color:var(--muted)}
@media(max-width:720px){.wrap{padding:12px}.cards{grid-template-columns:1fr 1fr}.setgrid{grid-template-columns:1fr}.toolbar>*{flex:1 1 100%}input[type=text]{min-width:0;width:100%}}
</style>
</head>
<body>
<div class="wrap">
<?php if($compact): ?>
<div class="top">
  <?php $compactTitle = match($view) { 'item' => $item ? 'Itemdetails · '.h($item['name']) : 'Itemdetails', 'items' => 'Item-Katalog', 'tasks' => 'Meine Aufgaben / To-Dos', 'goals' => 'Meine Ziele & Notizen', 'jobs' => 'Meine LuAshitacast-Sets', 'changes' => 'Inventaränderungen', default => 'Gear Center · Details' }; ?>
  <div><h1><?=$compactTitle?></h1><div class="muted">Vertiefende Informationen aus deinem Gear-Center-Datenbestand</div></div>
</div>
<nav class="nav"><a href="/?tab=<?=h($returnTab)?>">← Zurück zum Gear Center</a></nav>
<?php else: ?>
<div class="top">
  <div><h1>Gear Center · Expertendiagnose</h1><div class="muted">Technische Wartungsansicht · <?=h($appVersion)?></div></div>
</div>
<nav class="nav">
  <a href="/">← Zurück zum Gear Center</a>
  <a class="<?=$view==='sources'?'active':''?>" href="?view=sources">Datenquellen</a>
  <a class="<?=$view==='targets'?'active':''?>" href="?view=targets">Guide-Ziele / Verifikation</a>
  <a class="<?=$view==='coverage'?'active':''?>" href="?view=coverage">Coverage / QA</a>
  <a class="<?=$view==='changes'?'active':''?>" href="?view=changes">Snapshot-Änderungen</a>
</nav>
<?php endif; ?>

<?php if($notice):?><div class="notice"><?=h($notice)?></div><?php endif;?>
<?php if($error):?>
  <div class="error"><?=h($error)?></div>
<?php elseif(!$snapshot):?>
  <div class="empty">Noch kein GearFinder-Snapshot importiert.<div class="rowactions" style="justify-content:center;margin-top:10px"><a class="pill good" href="/?tab=diagnostics">Datenstatus prüfen</a><a class="pill" href="/?tab=inventory">Zur Inventaransicht</a></div></div>
<?php else:?>

<?php if($view==='dashboard'):?>
<div class="cards">
  <div class="card"><span class="muted">Character</span><b class="big"><?=h($snapshot['character_name'])?></b><small><?=h($snapshot['item_count'])?> equipment · <?=h($snapshot['unique_item_count'])?> unique owned IDs</small></div>
  <div class="card"><span class="muted">Unified catalog</span><b class="big"><?=h($catalogSummary['total'])?></b><small><?=h($catalogSummary['owned'])?> owned · <?=h($catalogSummary['missing'])?> missing target/required</small></div>
  <div class="card"><span class="muted">LAC profiles</span><b class="big"><?=$profileCount?></b><small>NIN / THF / COMMON</small></div>
  <div class="card"><span class="muted">Live-Augment layer</span><b class="big"><?=$bridgeSnapshot?'READY':'DEFERRED'?></b><small><?=$bridgeSnapshot?h($bridgeSnapshot['item_count']).' live readings':'wartet auf Live-Daten'?></small></div>
  <div class="card"><span class="muted">Source health</span><b class="big"><?=$healthBad?'ERROR':($healthPending?'PARTIAL':'OK')?></b><small><?=$healthBad?> errors · <?=$healthPending?> pending</small></div>
</div>
<div class="cards">
  <a class="card" href="?view=items&status=missing"><h3>Missing catalog items</h3><b class="big bad"><?=h($catalogSummary['missing'])?></b><p>Required by LAC or an active goal, but not owned in the current GearFinder snapshot.</p></a>
  <a class="card" href="?view=items&source=bg-wiki"><h3>BG-Wiki coverage</h3><b class="big"><?=h($catalogSummary['bgwiki'])?></b><p>Cached item references available for offline progression views.</p></a>
  <a class="card" href="?view=targets"><h3>Global job targets</h3><b class="big"><?=h($globalTargetSummary['targets'])?></b><p>Browse the imported 22-job target catalog with strict identity, route, augment and freshness status.</p></a>
  <div class="card"><h3>Live-Datenstatus</h3><p>Augment-Bridge und Equipped-Mapping werden automatisch aus den aktuellen lokalen FFXI-Daten geprüft. Diese Ansicht dient nur technischen Nachkontrollen.</p></div>
</div>

<?php elseif($view==='inventory'):?>
<div class="chips"><?php foreach($bagSummary as $b):?><span class="chip"><?=h($b['bag_name'])?> <b><?=h($b['n'])?></b></span><?php endforeach;?></div>
<form class="toolbar">
  <input type="hidden" name="view" value="inventory">
  <input type="text" name="q" value="<?=h($q)?>" placeholder="Search owned item name…">
  <select name="bag"><option value="">All containers</option><?php foreach($bagSummary as $b):?><option value="<?=h($b['bag_id'])?>" <?=$bag===(int)$b['bag_id']?'selected':''?>><?=h($b['bag_name'])?> (<?=h($b['n'])?>)</option><?php endforeach;?></select>
  <label class="pill"><input type="checkbox" name="augmented" value="1" <?=$augmentedOnly?'checked':''?>> augmented only</label>
  <button>Filter</button>
</form>
<div class="tablebox"><table><thead><tr><th>Item</th><th>ID</th><th>Location</th><th>Slot</th><th>Lv/iLv</th><th>Augment / progression</th><th>Source</th></tr></thead><tbody>
<?php foreach($rows as $r):$unsafe=isset($structuredAug[LacProfileParser::normalizeName($r['name'])]);$raw=augText($r['augment_stats_json']);?>
<tr>
  <td><a class="good" href="<?=h(physicalItemUrl($r))?>"><strong><?=h($r['name'])?></strong></a></td>
  <td><?=h($r['item_id'])?></td><td><?=h($r['bag_name'])?></td><td><?=h($r['bag_slot'])?></td><td><?=h($r['level'])?> / <?=h($r['item_level'])?></td>
  <td><?php if(($r['bridge_item_id']??null)!==null):?><span class="good"><?=h(bridgeAugText($r))?></span><?php if($raw!=='—'):?><details><summary>GearFinder-Ableitung stats</summary><?=h($raw)?></details><?php endif;?><?php elseif($unsafe):?><span class="warn">Live Path/Rank/Trial read pending</span><?php if($raw!=='—'):?><details><summary>GearFinder raw-derived stats (not authoritative)</summary><?=h($raw)?></details><?php endif;?><?php else:?><span class="muted"><?=h($raw)?></span><?php endif;?></td>
  <td><?=($r['bridge_item_id']??null)!==null?'<span class="badge good">LAC LIVE</span>':($unsafe?'<span class="badge warn">LIVE PENDING</span>':'GearFinder')?></td>
</tr>
<?php endforeach;?></tbody></table><?php if(!$rows):?><div class="empty">No matching owned items. Use <a class="good" href="?view=items&q=<?=rawurlencode($q)?>">Items</a> to search required or missing gear too.</div><?php endif;?></div>

<?php elseif($view==='items'):?>
<div class="cards">
  <div class="card"><span class="muted">Items im Katalog</span><b class="big"><?=h($catalogSummary['total'])?></b><small>GearFinder + LAC + Goals + BG-Wiki</small></div>
  <div class="card"><span class="muted">Owned</span><b class="big good"><?=h($catalogSummary['owned'])?></b><small>mindestens ein aktuelles Exemplar</small></div>
  <div class="card"><span class="muted">Missing</span><b class="big bad"><?=h($catalogSummary['missing'])?></b><small>von LAC oder einem aktiven Ziel benötigt</small></div>
  <div class="card"><span class="muted">BG-Wiki im Cache</span><b class="big"><?=h($catalogSummary['bgwiki'])?></b><small>gespeicherte Bezugs-/Progressionsdaten</small></div>
</div>
<form class="toolbar">
  <input type="hidden" name="view" value="items">
  <input type="text" name="q" value="<?=h($q)?>" placeholder="Alle bekannten Items durchsuchen…">
  <select name="status">
    <?php foreach(['all'=>'Alle Zustände','owned'=>'Owned','missing'=>'Fehlend / benötigt','optional'=>'Optional / auswählbar','reference'=>'Nur Referenz'] as $v=>$label):?><option value="<?=h($v)?>" <?=$itemStatusFilter===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <select name="source">
    <?php foreach(['all'=>'Alle Quellen','gearfinder'=>'GearFinder','lac'=>'LuAshitacast','goals'=>'Goals','bg-wiki'=>'BG-Wiki'] as $v=>$label):?><option value="<?=h($v)?>" <?=$itemSourceFilter===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <button>Filter</button>
</form>
<div class="tablebox"><table><thead><tr><th>Item</th><th>Status</th><th>Owned</th><th>LAC</th><th>Goals</th><th>Sources</th><th>ID / Level</th></tr></thead><tbody>
<?php foreach($catalogRows as $r):?>
<tr>
  <td><a class="good" href="<?=h(itemUrl($r['name']))?>"><strong><?=h($r['name'])?></strong></a></td>
  <td class="<?=statusClass($r['status'])?>"><span class="itemstatus"><?=h($r['status'])?></span></td>
  <td><?=h($r['owned_count'])?></td>
  <td><?php if($r['requirement_count']):?><span class="bad"><?=h($r['requirement_count'])?> required</span><?php endif;?><?php if($r['option_count']):?><?= $r['requirement_count']?' · ':'' ?><span class="warn"><?=h($r['option_count'])?> optional</span><?php endif;?><?php if(!$r['requirement_count']&&!$r['option_count']):?>—<?php endif;?><?php if($r['jobs']):?><div class="mini muted"><?=h(implode(' · ',$r['jobs']))?></div><?php endif;?></td>
  <td><?=h($r['goal_count'])?></td>
  <td><div class="sourcebadges"><?php foreach($r['sources'] as $src):?><span class="badge"><?=h($src)?></span><?php endforeach;?></div></td>
  <td><?=($r['item_id']??null)!==null?'ID '.h($r['item_id']).' · Lv '.h($r['level']).'/'.h($r['item_level']):'<span class="muted">not in GearFinder resources</span>'?></td>
</tr>
<?php endforeach;?></tbody></table><?php if(!$catalogRows):?><div class="empty">Keine Items passen zu diesen Filtern.<div class="rowactions" style="justify-content:center;margin-top:10px"><a class="pill good" href="<?=h(advancedUrl('items'))?>">Filter zurücksetzen</a><?php if($q!==''):?><a class="pill" href="<?=h(bgSearch($q))?>" target="_blank" rel="noreferrer">„<?=h($q)?>“ auf BG-Wiki suchen ↗</a><?php endif;?><a class="pill" href="/?tab=plan">Zur Gearplanung</a></div></div><?php endif;?></div>

<?php elseif($view==='targets'):?>
<?php
  $targetSummary=$targetReport['summary'];
  $targetStrictVerified=(int)($targetSummary['statuses']['strict_status']['VERIFIED']??0);
  $targetReview=max(0,(int)$targetSummary['targets']-$targetStrictVerified);
  $targetSourceAnomalies=0;
  foreach(($targetSummary['statuses']['source_anomaly_status']??[]) as $state=>$count)if(!in_array((string)$state,['NONE','PENDING'],true))$targetSourceAnomalies+=(int)$count;
?>
<div class="cards">
  <div class="card"><span class="muted">Jobs cached</span><b class="big <?=((int)$targetSummary['jobs']===22?'good':'warn')?>"><?=h($targetSummary['jobs'])?> / 22</b><small>BG-Wiki All Jobs target snapshots</small></div>
  <div class="card"><span class="muted">Target sets</span><b class="big"><?=h($targetSummary['sets'])?></b><small><?=h($targetSummary['targets'])?> slot-level target rows</small></div>
  <div class="card"><span class="muted">Unique target items</span><b class="big"><?=h($targetSummary['unique_items'])?></b><small>canonical identity where verified</small></div>
  <div class="card"><span class="muted">Strict verified</span><b class="big good"><?=h($targetStrictVerified)?></b><small>identity + route + augment + freshness + latest chain complete</small></div>
  <div class="card"><span class="muted">Source anomalies</span><b class="big <?=($targetSourceAnomalies?'warn':'good')?>"><?=h($targetSourceAnomalies)?></b><small>aliases/conflicts remain source-visible</small></div>
  <div class="card"><span class="muted">Review queue</span><b class="big <?=($targetReview?'warn':'good')?>"><?=h($targetReview)?></b><small>unsupported links remain visible</small></div>
</div>

<form class="toolbar">
  <input type="hidden" name="view" value="targets">
  <input type="text" name="q" value="<?=h($q)?>" placeholder="Search target, set or variant…">
  <select name="target_job"><option value="ALL">All 22 jobs</option><?php foreach(AllJobsTargetParser::jobs() as $targetJobKey=>$targetJobTitle):?><option value="<?=h($targetJobKey)?>" <?=$targetJob===$targetJobKey?'selected':''?>><?=h($targetJobKey.' · '.$targetJobTitle)?></option><?php endforeach;?></select>
  <select name="target_strict">
    <?php foreach(['ALL'=>'All verification states','VERIFIED'=>'Strict verified','REVIEW'=>'Needs review','PENDING'=>'Pending','REVIEW_REQUIRED'=>'Review required'] as $v=>$label):?><option value="<?=h($v)?>" <?=$targetStrict===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <select name="target_owned">
    <?php foreach(['ALL'=>'Owned + not owned','OWNED'=>'Owned now','NOT_OWNED'=>'Not owned now'] as $v=>$label):?><option value="<?=h($v)?>" <?=$targetOwnership===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <button>Filter</button>
</form>

<?php if((int)$targetSummary['jobs']===0):?>
<div class="card"><h2>Global target catalog not imported yet</h2><p>The browser is ready. Run the global sync when you want to fetch the current 22-job target pages and strict progression metadata.</p><code>php bin/global-target-sync.php --jobs=ALL</code></div>
<?php else:?>
<details class="routesref"><summary>22-job QA matrix</summary>
  <div class="setgrid">
  <?php foreach($targetReport['jobs'] as $j):?>
    <a class="setcard" href="?view=targets&amp;target_job=<?=h($j['job'])?>">
      <div><span class="badge"><?=h($j['job'])?></span> <span class="<?=($j['review']?'warn':'good')?>"><?=h($j['review'])?> review</span></div>
      <h3><?=h($j['sets'])?> sets · <?=h($j['targets'])?> targets</h3>
      <div class="mini muted">strict <?=h($j['strict_percent'])?>% · identity <?=h($j['identity_percent'])?>% · route <?=h($j['route_percent'])?>% · augment <?=h($j['augment_percent'])?>% · freshness <?=h($j['freshness_percent'])?>% · chain <?=h($j['chain_percent'])?>% · source anomalies <?=h($j['source_anomalies'])?></div>
      <div class="progress"><span style="width:<?=h($j['strict_percent'])?>%"></span></div>
    </a>
  <?php endforeach;?>
  </div>
</details>

<section class="group"><h2>Target rows <span class="muted">(<?=count($targetRows)?> shown)</span></h2>
<p class="muted">These are global recommendations, not personal goals. “Not owned” therefore does not automatically create a missing item or To-Do.</p>
<div class="tablebox"><table><thead><tr><th>Job / set</th><th>Target item</th><th>Owned</th><th>Strict</th><th>Verification chain</th><th>System / review</th><th>Source</th></tr></thead><tbody>
<?php foreach($targetRows as $r): $systems=json_decode((string)($r['system_keys_json']??'[]'),true)?:[]; ?>
<tr>
  <td><span class="badge"><?=h($r['job'])?></span><br><strong><?=h($r['set_name'])?></strong><div class="mini muted"><?=h($r['variant_name'])?><?php if($r['slot_name']):?> · <?=h($r['slot_name'])?><?php endif;?></div></td>
  <td><a class="good" href="<?=h(itemUrl($r['display_item_name']))?>"><strong><?=h($r['display_item_name'])?></strong></a><?php if($r['canonical_item_name']&&strcasecmp((string)$r['canonical_item_name'],(string)$r['source_item_name'])!==0):?><div class="mini muted">preserved source label: <?=h($r['source_item_name'])?></div><?php endif;?><?php if(!in_array((string)$r['source_anomaly_status'],['NONE','PENDING'],true)):?><div class="mini <?=verificationClass((string)$r['source_anomaly_status'])?>">source <?=h($r['source_anomaly_status'])?></div><?php endif;?><?php if($r['details']):?><details><summary>target details</summary><?=h(json_encode($r['details'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?></details><?php endif;?></td>
  <td class="<?=($r['owned_count']?'good':'muted')?>"><strong><?=$r['owned_count']?h($r['owned_count']).' owned':'not owned'?></strong></td>
  <td class="<?=verificationClass((string)$r['strict_status'])?>"><strong><?=h($r['strict_status'])?></strong></td>
  <td class="mini"><span class="<?=verificationClass((string)$r['identity_status'])?>">identity <?=h($r['identity_status'])?></span><br><span class="muted"><?=h($r['identity_match_kind'])?></span><br><span class="<?=verificationClass((string)$r['source_anomaly_status'])?>">source <?=h($r['source_anomaly_status'])?></span><br><span class="<?=verificationClass((string)$r['route_status'])?>">route <?=h($r['route_status'])?></span><br><span class="<?=verificationClass((string)$r['augment_status'])?>">augment <?=h($r['augment_status'])?></span><br><span class="<?=verificationClass((string)$r['freshness_status'])?>">freshness <?=h($r['freshness_status'])?></span><br><span class="<?=verificationClass((string)$r['chain_status'])?>">chain <?=h($r['chain_status'])?></span></td>
  <td><div class="sourcebadges"><?php foreach($systems as $system):?><span class="badge"><?=h($system)?></span><?php endforeach;?></div><?php if($r['review_reason']):?><div class="mini warn" style="margin-top:5px"><?=h($r['review_reason'])?></div><?php endif;?></td>
  <td><a class="good" href="<?=h($r['target_page_url'])?>" target="_blank" rel="noreferrer"><?=h($r['target_page_title'])?></a><?php if($r['source_link_url']):?><div class="mini"><a class="good" href="<?=h($r['source_link_url'])?>" target="_blank" rel="noreferrer">guide item link: <?=h($r['source_link_title']?:$r['source_item_name'])?></a></div><?php endif;?><?php if($r['canonical_page_title']):?><div class="mini muted">cached canonical page: <?=h($r['canonical_page_title'])?></div><?php endif;?></td>
</tr>
<?php endforeach;?>
<?php if(!$targetRows):?><tr><td colspan="7" class="empty">Keine Guide-Ziele passen zu diesen Filtern.<div class="rowactions" style="justify-content:center;margin-top:10px"><a class="pill good" href="<?=h(advancedUrl('targets'))?>">Filter zurücksetzen</a><a class="pill" href="/?tab=plan">Zur Gearplanung</a></div></td></tr><?php endif;?>
</tbody></table></div></section>
<?php endif;?>

<?php elseif($view==='item'&&$item):?>
<p><a class="muted" href="<?=h(advancedUrl('items'))?>">← Zum Item-Katalog</a></p>
<div class="cards">
  <div class="card"><span class="muted">Item</span><b class="big"><?=h($item['name'])?></b><small><?php if($item['item_id']!==null):?>ID <?=h($item['item_id'])?> · Lv <?=h($item['level'])?> / iLv <?=h($item['item_level'])?><?php else:?>No GearFinder resource record; item known through other sources.<?php endif;?></small><?php if(!empty($item['canonical_variant_title'])):?><small class="good" style="display:block;margin-top:5px">BG-Wiki variant: <?=h($item['canonical_variant_title'])?></small><?php endif;?></div>
  <div class="card"><span class="muted">Katalogstatus</span><b class="big <?=statusClass($item['status'])?>"><?=h($item['status'])?></b><small><?php if($item['status']==='MISSING'):?>benötigt, aber aktuell nicht vorhanden<?php elseif($item['status']==='OWNED'):?>im aktuellen Inventar vorhanden<?php elseif($item['status']==='OPTIONAL'):?>auswählbare LAC-Option, nicht zwingend<?php else:?>nur Referenzdaten<?php endif;?></small></div>
  <div class="card"><span class="muted">Vorhandene Exemplare</span><b class="big"><?=count($itemOwned)?></b><small><?= $itemOwned ? h(implode(' · ',array_map(static fn($x)=>$x['bag_name'].'/'.$x['bag_slot'],$itemOwned))) : 'aktuell kein Exemplar vorhanden' ?></small></div>
  <div class="card"><span class="muted">LuAshitacast-Verwendung</span><b class="big"><?=count($itemUsage)?></b><small><?=h($item['requirement_count'])?> required · <?=h($item['option_count'])?> selectable</small></div>
  <div class="card"><span class="muted">Zugehörige Ziele</span><b class="big"><?=count($itemGoals)?></b><small>direkt oder über Set-Ziele</small></div>
</div>
<div class="sourcebadges" style="margin:0 0 16px"><?php foreach($item['sources'] as $src):?><span class="badge"><?=h($src)?></span><?php endforeach;?></div>
<?php if($selectedOwned):?><div class="notice"><strong>Ausgewähltes physisches Exemplar:</strong> <?=h($selectedOwned['bag_name'])?> / Slot <?=h($selectedOwned['bag_slot'])?><?php if(($selectedOwned['bridge_item_id']??null)!==null):?> · <span class="good"><?=h(bridgeAugText($selectedOwned))?></span><?php else:?><span class="warn"> · Live-Augment für dieses Exemplar steht noch aus</span><?php endif;?></div><?php elseif($selectedInstanceError):?><div class="notice"><strong class="warn">Exemplar-Kontext nicht mehr gültig.</strong> <?=h($selectedInstanceError)?></div><?php endif;?>

<div class="cards">
  <div class="card">
    <h3>Verwendung in LuAshitacast</h3>
    <?php foreach($itemUsage as $u):?>
      <div style="margin:8px 0">
        <span class="badge"><?=h($u['job'])?></span>
        <?php if($u['usage_type']==='requirement'):?>
          <a class="good" href="<?=h(advancedUrl('jobs',['job'=>$u['job']]))?>"><strong><?=h($u['raw_name'])?></strong></a> · <?=h($u['slot_name'])?>
        <?php else:?>
          <a class="good" href="<?=h(advancedUrl('jobs',['job'=>$u['job']]))?>"><strong><?=h($u['cycle_name'])?></strong> → <?=h($u['option_value'])?></a> · <?=h($u['slot_name'])?> <?php if($u['is_default']):?><span class="badge good">DEFAULT</span><?php endif;?>
        <?php endif;?>
        · <span class="muted"><?=h(targetText($u))?></span>
      </div>
    <?php endforeach;?>
    <?php if(!$itemUsage):?><p class="muted">Keine aktuelle LAC-Anforderung oder auswählbare Option.</p><?php endif;?>
  </div>

  <div class="card">
    <h3>Ziele & Notizen</h3>
    <?php foreach($itemGoals as $g):?><p><span class="badge"><?=h(strtoupper($g['status']))?></span> <a class="good" href="<?=h(advancedUrl('goals'))?>"><strong><?=h($g['label'])?></strong></a> <span class="muted">· <?=h($g['relation']==='direct'?'direktes Item-Ziel':'über Set-Ziel')?></span></p><?php endforeach;?>
    <?php $hasDirect=false;foreach($itemGoals as $g)if($g['relation']==='direct')$hasDirect=true;?>
    <?php if(!$hasDirect):?>
      <form method="post"><input type="hidden" name="action" value="goal_item"><input type="hidden" name="item_name" value="<?=h($item['name'])?>"><div class="inlineform"><select name="priority"><option>normal</option><option>high</option><option>low</option></select><button>Item als Ziel/Notiz hinzufügen</button></div></form>
    <?php endif;?>
  </div>

  <div class="card">
    <h3>Aufgaben / To-Dos</h3>
    <?php foreach($itemTasks as $t):?><p><span class="badge"><?=h($t['status'])?></span> <a class="good" href="<?=h(advancedUrl('tasks'))?>"><?=h($t['title'])?></a></p><?php endforeach;?>
    <form method="post"><input type="hidden" name="action" value="task_add"><input type="hidden" name="title" value="Obtain <?=h($item['name'])?>"><input type="hidden" name="task_type" value="obtain"><input type="hidden" name="priority" value="normal"><input type="hidden" name="entity_type" value="item"><input type="hidden" name="entity_key" value="<?=h($item['name_norm'])?>"><button><?= $item['status']==='OWNED'?'Aufgabe für dieses Item anlegen':'Beschaffungsaufgabe anlegen' ?></button></form>
  </div>
</div>

<?php if($targetSystems):?>
<section class="group">
  <h2>LAC-Zielsystem</h2>
  <p class="muted">Einordnung des in LuAshitacast hinterlegten Ziels. Die tatsächliche physische Item-Instanz und ihre Augments werden weiterhin live geprüft.</p>
  <div class="routegrid">
  <?php foreach($targetSystems as $sys):?>
    <div class="route">
      <div class="routehead"><span class="badge"><?=h(strtoupper(str_replace('_',' ',(string)($sys['mode']??'system'))))?></span><h4><?=h($sys['label'])?></h4></div>
      <?php if(!empty($sys['target_trials'])):?><p><strong>Target trial marker:</strong> <?=h(implode(', ',$sys['target_trials']))?></p><?php endif;?>
      <?php if(!empty($sys['target_augments'])):?><p><strong>Target augments:</strong> <?=h(implode(' · ',$sys['target_augments']))?></p><?php endif;?>
      <?php if(!empty($sys['advisory_only'])):?>
        <p class="good"><strong>Next-tier upgrade available</strong></p>
        <?php if(!empty($sys['next_item'])):?><p><strong>Next item:</strong> <a class="good" href="<?=h(itemUrl($sys['next_item']))?>"><?=h($sys['next_item'])?></a></p><?php endif;?>
        <p><strong><?=h($sys['npc']??'')?></strong><?php if(!empty($sys['location'])):?> · <?=h($sys['location'])?><?php endif;?><?php if(!empty($sys['currency_quantity'])):?> · <?=number_format((int)$sys['currency_quantity'])?> <?=h($sys['currency_name']??'')?><?php endif;?></p>
        <?php if(!empty($sys['required_key_item'])):?><p class="muted mini">Key item: <a class="good" href="<?=h(bgSearch((string)$sys['required_key_item']))?>" target="_blank" rel="noreferrer"><?=h($sys['required_key_item'])?></a></p><?php endif;?>
        <?php if(!empty($sys['next_item'])):?><form method="post" class="inlineform"><input type="hidden" name="action" value="goal_item"><input type="hidden" name="item_name" value="<?=h($sys['next_item'])?>"><input type="hidden" name="priority" value="normal"><button>Add +4 target to Goals</button></form><?php endif;?>
      <?php else:?>
        <p class="<?=($sys['action_required']??true)?'warn':'good'?>"><strong><?=($sys['action_required']??true)?'Progression/live verification may still be required':'No separate post-acquisition progression action required'?></strong></p>
      <?php endif;?>
      <?php if(!empty($sys['notes'])):?><ul><?php foreach($sys['notes'] as $note):?><li><?=h($note)?></li><?php endforeach;?></ul><?php endif;?>
      <?php if(!empty($sys['source_url'])):?><p class="muted mini">System source: <a class="good" href="<?=h($sys['source_url'])?>" target="_blank" rel="noreferrer"><?=h($sys['source_title']??'BG-Wiki')?></a></p><?php endif;?>
    </div>
  <?php endforeach;?>
  </div>
</section>
<?php endif;?>

<?php if($itemOwned):?>
<section class="group"><h2>Vorhandene Exemplare</h2><div class="tablebox"><table><thead><tr><th>Location</th><th>ID</th><th>Live-Augment</th><th>GearFinder-Ableitung</th></tr></thead><tbody>
<?php foreach($itemOwned as $x): $isSelected=$selectedOwned!==null && (int)$x['bag_id']===(int)$selectedOwned['bag_id'] && (int)$x['bag_slot']===(int)$selectedOwned['bag_slot'];?><tr><td><?=h($x['bag_name'])?> / <?=h($x['bag_slot'])?><?php if($isSelected):?> <span class="badge good">dieses Exemplar</span><?php endif;?></td><td><?=h($x['item_id'])?></td><td><?php if(($x['bridge_item_id']??null)!==null):?><span class="good"><?=h(bridgeAugText($x))?></span><?php else:?><span class="warn">Live-Auslesung steht noch aus</span><?php endif;?></td><td class="muted"><?=h(augText($x['augment_stats_json']))?></td></tr><?php endforeach;?>
</tbody></table></div></section>
<?php endif;?>

<?php if($acquisitionRoutes):?>
<?php $ownedAcquisition = ($item['status'] ?? '') === 'OWNED'; ?>
<?php if($ownedAcquisition):?>
<section class="group"><details class="routesref"><summary>Alternative Bezugswege <span class="badge good">bereits vorhanden</span></summary>
  <p class="muted">Diese Bezugsquellen bleiben als Referenz erhalten. Da das Item bereits vorhanden ist, werden sie nicht als aktuelle Farmaufgabe gewertet.</p>
<?php else:?>
<section class="group">
  <h2>Bezugswege</h2>
  <p class="muted">Strukturierte Bezugswege aus dem lokalen BG-Wiki-Datenbestand. Upgrades und Augments werden darunter separat dargestellt.</p>
<?php endif;?>
  <div class="routegrid">
  <?php foreach($acquisitionRoutes as $route):
    $routeTask=null;foreach($itemTasks as $it){if(($it['route']['route_key']??'')===$route['key'] && !in_array($it['status'],['done','ignored'],true)){$routeTask=$it;break;}}
  ?>
    <div class="route routechoice">
      <div class="routehead"><span class="badge"><?=h(strtoupper($route['kind']))?></span><span class="badge"><?=h($route['group'])?></span><h4><?=h($route['title'])?></h4></div>
      <?php if($route['summary']):?><p><?=nl2br(h($route['summary']))?></p><?php endif;?>
      <ol><?php foreach($route['steps'] as $step):?><li><?=h($step)?></li><?php endforeach;?></ol>
      <div class="sourcebadges">
        <?php if($route['content_name']):?><span class="badge"><?=h($route['content_name'])?></span><?php endif;?>
        <span class="badge">BG-Wiki: <?=h($route['section'])?></span>
      </div>
      <div style="margin-top:10px">
      <?php if($ownedAcquisition):?>
        <span class="muted">Nur Referenz — current snapshot already contains this item.</span>
      <?php elseif($routeTask):?>
        <span class="good">✓ Bezugsweg bereits als To-Do gespeichert</span> · <a class="good" href="<?=h(advancedUrl('tasks'))?>">Aufgabe öffnen</a>
      <?php else:?>
        <form method="post" class="inlineform">
          <input type="hidden" name="action" value="route_task_add"><input type="hidden" name="item_name" value="<?=h($item['name'])?>"><input type="hidden" name="route_key" value="<?=h($route['key'])?>">
          <select name="priority"><option>normal</option><option>high</option><option>low</option></select><button>Bezugsweg als To-Do anlegen</button>
        </form>
      <?php endif;?>
      </div>
    </div>
  <?php endforeach;?>
  </div>
<?php if($ownedAcquisition):?></details><?php endif;?>
</section>
<?php endif;?>

<?php if(!empty($progressionChain['has_data'])):?>
<section class="group">
  <h2>Upgrade- / Augment-Verlauf</h2>
  <p class="muted">Es werden nur ausdrücklich in BG-Wiki hinterlegte Beziehungen verbunden. Gear Center erfindet keine Stufen anhand ähnlicher Itemnamen.</p>

  <?php if(!empty($progressionChain['upstream_paths'])):?>
    <h3><?=($item['status']==='OWNED'?'Upgrade-Verlauf / Referenz':'Paths to '.h($item['name']))?></h3>
    <?php foreach($progressionChain['upstream_paths'] as $path):?>
      <div class="route">
        <div class="chainpath">
        <?php $first=true; foreach($path as $edge):
          if($first): $node=$catalog->find($edge['from']); ?>
            <a class="chainnode" href="<?=h(itemUrl($edge['from']))?>"><strong><?=h($edge['from'])?></strong><?php if($node):?> <span class="badge <?=statusClass($node['status'])?>"><?=h($node['status'])?></span><?php endif;?></a>
          <?php $first=false; endif; ?>
          <span class="chainarrow">→</span>
          <?php $node=$catalog->find($edge['to']); ?>
          <a class="chainnode" href="<?=h(itemUrl($edge['to']))?>"><strong><?=h($edge['to'])?></strong><?php if($node):?> <span class="badge <?=statusClass($node['status'])?>"><?=h($node['status'])?></span><?php endif;?></a>
        <?php endforeach;?>
        </div>
        <?php foreach($path as $edge):?><div class="chainmeta"><span class="badge"><?=h(strtoupper($edge['kind']))?></span> <?=h($edge['from'])?> → <?=h($edge['to'])?><?php if($edge['summary']):?> · <?=nl2br(h($edge['summary']))?><?php endif;?> · <span class="muted">BG-Wiki: <?=h($edge['section'])?></span></div><?php endforeach;?>
      </div>
    <?php endforeach;?>
  <?php endif;?>

  <?php
    $focusNorm=LacProfileParser::normalizeName($item['name']);
    $focusActions=array_values(array_filter($progressionChain['actions'],static fn($a)=>$a['from_norm']===$focusNorm));
    $desiredPaths=[];
    foreach($itemUsage as $u){$p=trim((string)($u['aug_path']??''));if($p!=='')$desiredPaths[strtoupper($p)]=true;}
    $augmentOptions=$progressionChain['augment_options']??[];
  ?>
  <?php if($focusActions):?>
    <h3 style="margin-top:16px">Ziel-Progression</h3>
    <div class="route">
    <?php foreach($focusActions as $a):?>
      <div class="chainaction"><span class="badge"><?=h(strtoupper($a['kind']))?></span> <strong><?=h($item['name'])?></strong><?php if($a['actor']):?> via <?=h($a['actor'])?><?php endif;?><?php if($a['zone']):?> · <?=nl2br(h($a['zone']))?><?php endif;?><?php if($a['requirements']):?><div class="chainmeta"><?=nl2br(h($a['requirements']))?></div><?php endif;?></div>
    <?php endforeach;?>
    </div>
  <?php endif;?>

  <?php if($desiredPaths && $augmentOptions):?>
    <h3 style="margin-top:16px">LAC-Zielaugment</h3>
    <div class="routegrid">
    <?php foreach(array_keys($desiredPaths) as $desiredPath):
      $matches=array_values(array_filter($augmentOptions,static fn($o)=>strtoupper((string)($o['path']??''))===$desiredPath)); ?>
      <div class="route">
        <div class="routehead"><span class="badge">LAC</span><span class="badge">PATH <?=h($desiredPath)?></span><h4><?=h($item['name'])?></h4></div>
        <?php if($matches): foreach($matches as $opt):?><div class="chainmeta"><?=nl2br(h($opt['details']))?></div><?php endforeach;?>
        <?php else:?><div class="warn">Path <?=h($desiredPath)?> is required by LAC, but no structured BG-Wiki path details are cached yet.</div><?php endif;?>
        <p class="muted mini">Target definition only. Actual equipped item path/rank will be verified by the consolidated live bridge test.</p>
      </div>
    <?php endforeach;?>
    </div>
  <?php endif;?>

  <?php if(!empty($progressionChain['downstream_paths'])):?>
    <h3 style="margin-top:16px">Wird in späteren Stufen verwendet</h3>
    <?php foreach($progressionChain['downstream_paths'] as $path):?><div class="chainpath">
      <?php $first=true; foreach($path as $edge): if($first):?><a class="chainnode" href="<?=h(itemUrl($edge['from']))?>"><?=h($edge['from'])?></a><?php $first=false; endif;?><span class="chainarrow">→</span><a class="chainnode" href="<?=h(itemUrl($edge['to']))?>"><?=h($edge['to'])?></a><?php endforeach;?>
    </div><?php endforeach;?>
  <?php endif;?>

  <?php if(($progressionChain['current_state']??'')==='owned'):?>
    <p class="notice"><strong>Current-state planning:</strong> the physical item is bereits vorhanden. Earlier upgrade stages are reference/history only; actionable plans start at the resolved current variant and contain only remaining structured work.</p>
  <?php endif;?>
  <?php if(!empty($progressionChain['plans'])):?>
    <h3 style="margin-top:16px">Konkreter Progressionsplan</h3>
    <div class="routegrid">
    <?php foreach($progressionChain['plans'] as $plan):
      $planTask=null;foreach($itemTasks as $it){if(($it['route']['route_key']??'')===$plan['key'] && !in_array($it['status'],['done','ignored'],true)){$planTask=$it;break;}}
    ?>
      <div class="route routechoice">
        <div class="routehead"><span class="badge">CHAIN</span><span class="badge">Progression</span><h4><?=h($plan['title'])?></h4></div>
        <ol><?php foreach($plan['steps'] as $step):?><li><?=h($step)?></li><?php endforeach;?></ol>
        <?php
          $onlyAugmentActions=!empty($plan['actions']);
          foreach(($plan['actions']??[]) as $pa){if(($pa['kind']??'')!=='augment'){$onlyAugmentActions=false;break;}}
          $planVerificationPending=!$bridgeSnapshot && !empty($plan['fields']['current_owned']) && $onlyAugmentActions;
        ?>
        <?php if($planTask):?><span class="good">✓ Plan bereits als To-Do gespeichert</span> · <a class="good" href="<?=h(advancedUrl('tasks'))?>">Aufgabe öffnen</a>
        <?php elseif($planVerificationPending):?><span class="warn">LIVE VERIFICATION PENDING</span><p class="muted mini">Dieses vorhandene Item kann das Augment-/Rank-Ziel bereits erfüllen. Gear Center erzeugt dafür erst Arbeit, sobald die aktuelle Live-Auslesung Path/Rank eindeutig bestätigt.</p>
        <?php else:?><form method="post" class="inlineform"><input type="hidden" name="action" value="progression_task_add"><input type="hidden" name="item_name" value="<?=h($item['name'])?>"><input type="hidden" name="plan_key" value="<?=h($plan['key'])?>"><?php if($selectedOwned!==null):?><input type="hidden" name="instance_container" value="<?=h($selectedOwned['bag_id'])?>"><input type="hidden" name="instance_slot" value="<?=h($selectedOwned['bag_slot'])?>"><?php $selectedExtra=normalizedExtraHex($selectedOwned['bridge_raw_extra']??''); if($selectedExtra!==''):?><input type="hidden" name="instance_extra" value="<?=h($selectedExtra)?>"><?php endif;?><?php endif;?><select name="priority"><option>normal</option><option>high</option><option>low</option></select><button>Progressionsplan als To-Do anlegen</button></form><?php endif;?>
      </div>
    <?php endforeach;?>
    </div>
  <?php endif;?>
</section>
<?php endif;?>

<?php if(!empty($progressionResources['has_data'])):?>
<section class="group">
  <h2>Materialien / Währungen</h2>
  <p class="muted">Es werden nur Materialien des aktuell relevanten strukturierten Progressionsplans zusammengefasst. Historische Stufen und alternative Bezugswege werden hier nicht doppelt gezählt.</p>
  <?php if(in_array((string)($liveSqliteSync['status']??''),['PRESERVED','PARTIAL'],true)):?>
    <p class="notice"><strong>Live-Sync <?=h((string)$liveSqliteSync['status'])?>:</strong> <?=h((string)($liveSqliteSync['message']??''))?> Der letzte gültige SQLite-Bestand bleibt erhalten.</p>
  <?php endif;?>
  <?php if(($resourceInventoryStatus['status']??'PENDING')!=='OK'):?>
    <p class="notice"><strong>Resource inventory PENDING:</strong> <?=h($resourceInventoryStatus['message']??'Quantity verification is not available yet.')?> Required quantities remain source-derived; owned/missing quantities will be filled by the consolidated live resource snapshot.</p>
  <?php else:?>
    <p class="notice"><strong>Resource inventory:</strong> <?=h($resourceInventoryStatus['message'])?></p>
  <?php endif;?>

  <?php foreach($progressionResources['plans'] as $resourcePlan):?>
    <div class="card" style="margin:12px 0">
      <h3><?=h($resourcePlan['plan_title'])?></h3>
      <?php if(!empty($resourcePlan['systems'])):?>
        <?php foreach($resourcePlan['systems'] as $system): $systemMode=(string)($system['mode']??'workflow');?>
          <div class="route" style="margin:10px 0">
            <div class="routehead"><span class="badge"><?=h(strtoupper(str_replace('_',' ',$systemMode)))?></span><h4><?=h($system['label'])?></h4></div>
            <?php if($systemMode==='reinforcement_points'):?>
              <div class="cards" style="margin:10px 0">
                <div class="card"><span class="muted"><?=h(($system['target_rank_basis']??'')==='system_max_reference'?'System max rank':'Target rank')?></span><b class="big"><?=h($system['target_rank'])?></b><small><?=h($system['npc'])?> · <?=h($system['location'])?></small></div>
                <div class="card"><span class="muted">Full-system target</span><b class="big"><?=number_format((int)$system['total_points'])?> <?=h($system['point_label'])?></b><small>source-backed system total, not automatically the amount you still need</small></div>
                <div class="card"><span class="muted">Current / remaining</span>
                  <?php $rs=(string)($system['remaining_state']??'PENDING_LIVE_AUGMENT_STATE'); ?>
                  <?php if($rs==='COMPLETE'):?>
                    <b class="big good">Rank <?=h($system['current_rank'])?> · 0 <?=h($system['point_label'])?></b><small>Live item is already at the system target rank.</small>
                  <?php elseif($rs==='PARTIAL_RANK_NEXT_RP_UNREAD'):?>
                    <b class="big warn">Rank <?=h($system['current_rank'])?></b>
                    <small>Remaining <?=h($system['point_label'])?>: <?=h(number_format((int)$system['remaining_min_points']))?>–<?=h(number_format((int)$system['remaining_max_points']))?>. Exact value needs the item's NextRP/progress field.</small>
                  <?php elseif($rs==='AMBIGUOUS_MULTIPLE_COPIES'):?>
                    <b class="big warn">MULTIPLE COPIES</b><small><?=h($system['live_copy_count'])?> live copies found; Gear Center cannot assign one progression state without instance allocation.</small>
                  <?php elseif($rs==='LIVE_RANK_UNAVAILABLE'):?>
                    <b class="big warn">RANK UNREAD</b><small>The live item was found, but its rank was not decoded.</small>
                  <?php elseif($rs==='REFERENCE_MAX_NOT_LAC_TARGET'):?>
                    <b class="big">Rank <?=h($system['current_rank'])?></b><small>The shown max rank is system context. LAC did not specify a mandatory rank target, so Gear Center does not create rank-up work from the max alone.</small>
                  <?php else:?>
                    <b class="big warn">PENDING LIVE</b><small>Path/Rank must be read from the physical item before remaining work is calculated.</small>
                  <?php endif;?>
                </div>
              </div>
              <?php if(!empty($system['accepted_resources'])):?><strong>Accepted reinforcement resources</strong><div class="chips">
                <?php foreach($system['accepted_resources'] as $choice):?>
                  <span class="chip"><strong><?=h($choice['name'])?></strong> = <?=h($choice['points_per_unit'])?> <?=h($system['point_label'])?> · owned <?=($choice['owned_quantity']===null?'<span class="warn">?</span>':h($choice['owned_quantity']))?></span>
                <?php endforeach;?>
              </div><?php else:?><p class="muted mini">RP is earned/stored by the progression system rather than consumed from a bag material.</p><?php endif;?>
            <?php elseif($systemMode==='random_augment_rolls'):?>
              <div class="cards" style="margin:10px 0">
                <div class="card"><span class="muted">NPC</span><b class="big"><?=h($system['npc'])?></b><small><?=h($system['location'])?></small></div>
                <div class="card"><span class="muted">Progression type</span><b class="big">Random rolls</b><small>No finite guaranteed roll count is invented.</small></div>
              </div>
              <strong>Accepted roll resources</strong><div class="chips">
                <?php foreach(($system['accepted_resources']??[]) as $choice):?><span class="chip"><strong><?=h($choice['name'])?></strong> · owned <?=($choice['owned_quantity']===null?'<span class="warn">?</span>':h($choice['owned_quantity']))?></span><?php endforeach;?>
              </div>
            <?php elseif($systemMode==='augment_material_slots'):?>
              <div class="cards" style="margin:10px 0">
                <div class="card"><span class="muted">NPC</span><b class="big"><?=h($system['npc'])?></b><small><?=h($system['location'])?></small></div>
                <div class="card"><span class="muted">Content</span><b class="big"><?=h($system['content'])?></b><small>Exakte Restmengen werden erst berechnet, wenn aktuelle Live-Augments und das LAC-Ziel eindeutig vergleichbar sind.</small></div>
              </div>
              <strong>Augment material families</strong><div class="chips">
                <?php foreach(($system['material_families']??[]) as $m):?><span class="chip"><strong><?=h($m['name'])?></strong> · max <?=h($m['max_units'])?> · <?=h($m['role'])?> · owned <?=($m['owned_quantity']===null?'<span class="warn">?</span>':h($m['owned_quantity']))?></span><?php endforeach;?>
              </div>
            <?php else:?>
              <div class="cards" style="margin:10px 0">
                <div class="card"><span class="muted">System</span><b class="big"><?=h($system['content']??$system['label'])?></b><small>Reusable progression adapter</small></div>
                <div class="card"><span class="muted">NPC / location</span><b class="big"><?=h($system['npc']??'')?></b><small><?=h($system['location']??'')?></small></div>
              </div>
              <p class="muted mini">Item-specific fixed materials remain in the resource table below; this adapter contributes unlock rules and system context rather than replacing those source rows.</p>
            <?php endif;?>
            <?php if(!empty($system['unlock_requirements'])):?><details open><summary>Unlock requirements</summary><ul><?php foreach($system['unlock_requirements'] as $rule):?><li><?=h($rule)?></li><?php endforeach;?></ul></details><?php endif;?>
            <?php if(!empty($system['conditional_costs'])):?><details><summary>Conditional setup costs / rules</summary><ul>
              <?php foreach($system['conditional_costs'] as $cost):?><li><strong><?=h($cost['name'])?></strong><?php if($cost['quantity']!==null):?> × <?=h($cost['quantity'])?><?php endif;?> · <?=h($cost['condition'])?> — <?=h($cost['note'])?></li><?php endforeach;?>
            </ul></details><?php endif;?>
            <?php if(!empty($system['notes'])):?><details><summary>System rules</summary><ul><?php foreach($system['notes'] as $note):?><li><?=h($note)?></li><?php endforeach;?></ul></details><?php endif;?>
            <p class="muted mini">Adapter source: <a class="good" href="<?=h($system['source_url'])?>" target="_blank" rel="noreferrer"><?=h($system['source_title'])?></a>.</p>
          </div>
        <?php endforeach;?>
      <?php endif;?>
      <?php if($resourcePlan['requirements']):?>
      <div class="tablebox"><table><thead><tr><th>Resource</th><th>Required</th><th>Owned</th><th>Missing</th><th>Source state</th></tr></thead><tbody>
      <?php foreach($resourcePlan['requirements'] as $req):?>
        <tr>
          <td><?php if(strtolower((string)$req['kind'])==='item'):?><a class="good" href="<?=h(itemUrl((string)$req['name']))?>"><strong><?=h($req['name'])?></strong></a><?php else:?><strong><?=h($req['name'])?></strong><?php endif;?><br><span class="muted mini"><?=h($req['kind'])?></span></td>
          <td><?=($req['required_quantity']===null?'<span class="warn">Menge nicht eindeutig hinterlegt</span>':h($req['required_quantity']))?></td>
          <td><?=($req['owned_quantity']===null?'<span class="warn">Live-Bestand steht noch aus</span>':h($req['owned_quantity']))?></td>
          <td><?php if($req['missing_quantity']===null):?><span class="muted">unknown</span><?php elseif($req['missing_quantity']===0):?><span class="good">0 ✓</span><?php else:?><span class="bad"><?=h($req['missing_quantity'])?></span><?php endif;?></td>
          <td><span class="badge"><?=h($req['state'])?></span><?php if($req['raw']):?><details><summary>Quelltext</summary><?=nl2br(h(implode("\n",$req['raw'])))?></details><?php endif;?></td>
        </tr>
      <?php endforeach;?>
      </tbody></table></div>
      <?php endif;?>

      <?php if($resourcePlan['alternative_groups']):?>
        <h4 style="margin-top:16px">Alternative Ressourcen</h4>
        <?php foreach($resourcePlan['alternative_groups'] as $group):?>
          <div class="route">
            <div class="routehead"><span class="badge">ANY OF</span><h4><?=h($group['label']??'eine Ressource auswählen')?></h4></div>
            <div class="chips">
            <?php foreach($group['choices'] as $choice):?>
              <span class="chip"><strong><?=h($choice['name'])?></strong><?php if($choice['quantity']!==null):?> × <?=h($choice['quantity'])?><?php endif;?> · owned <?=($choice['owned_quantity']===null?'<span class="warn">?</span>':h($choice['owned_quantity']))?></span>
            <?php endforeach;?>
            </div>
            <p class="muted mini">Nicht in feste Summen eingerechnet, weil BG-Wiki Alternativen statt gleichzeitig benötigter Ressourcen beschreibt.</p>
          </div>
        <?php endforeach;?>
      <?php endif;?>
    </div>
  <?php endforeach;?>
</section>
<?php endif;?>

<div class="card">
  <h3>BG-Wiki: Bezugsweg & Progression</h3>
  <?php if($bgPage):?>
    <p><a class="good" href="<?=h($bgPage['canonical_url'])?>" target="_blank" rel="noreferrer"><?=h($bgPage['page_title'])?></a></p>
    <p class="muted">Revision <?=h($bgPage['revision_id']??'—')?> · <?=h($bgPage['revision_timestamp']??'—')?> · cached <?=h($bgPage['fetched_at'])?></p>
    <?php
      $secs=json_decode($bgPage['sections_json'],true)?:[];
      $groups=BgWikiExtractor::progressionGroups($secs);
      $recordGroups=[];$referenceRecordGroups=[];$sectionsWithRecords=[];
      foreach($bgRecords as $rec){
        $sectionsWithRecords[$rec['section']]=true;
        $sectionNorm=strtolower(trim((string)$rec['section']));
        $isAugmentReference=str_contains($sectionNorm,'augment')
          && ProgressionChainService::isAugmentReferenceFields(is_array($rec['fields']??null)?$rec['fields']:[]);
        if($compact && $isAugmentReference){$referenceRecordGroups[$rec['section']][]=$rec;continue;}
        $recordGroups[$rec['section']][]=$rec;
      }
      $referenceRecordCount=array_sum(array_map('count',$referenceRecordGroups));
    ?>
    <?php if($recordGroups):?>
      <h4 style="margin-top:18px"><?=$compact?'Bezugswege & strukturierte Aktionen':'Strukturierte Bezugs- / Progressionsdaten'?></h4>
      <?php foreach($recordGroups as $sectionName=>$records):?>
        <div style="margin:14px 0"><strong><?=h(bgSectionLabel($sectionName))?></strong>
          <div class="recordgrid">
          <?php foreach($records as $rec):?>
            <div class="record"><dl>
            <?php foreach($rec['fields'] as $field=>$value):?>
              <dt><?=h($field)?></dt><dd><?=nl2br(h($value))?></dd>
            <?php endforeach;?>
            </dl></div>
          <?php endforeach;?>
          </div>
        </div>
      <?php endforeach;?>
    <?php endif;?>
    <?php if($compact && $referenceRecordCount>0):?>
      <div class="muted mini" style="margin:12px 0 4px">
        <?=h($referenceRecordCount)?> technische Augment-Statzeilen sind in der normalen Ansicht ausgeblendet.
        Sie beschreiben mögliche <code>Aug/Item</code>- und <code>Max Aug</code>-Werte, keine zusätzlichen Bezugs- oder Progressionsschritte.
        <a class="good" href="<?=h($bgPage['canonical_url'])?>" target="_blank" rel="noreferrer">BG-Wiki-Tabelle direkt öffnen ↗</a>
      </div>
    <?php endif;?>
    <?php foreach($groups as $groupName=>$groupSections):
      $remaining=array_filter($groupSections,static fn($body,$name)=>!isset($sectionsWithRecords[$name]),ARRAY_FILTER_USE_BOTH);
      if(!$remaining)continue;?>
      <h4 style="margin-top:18px"><?=h($groupName)?></h4>
      <?php foreach($remaining as $name=>$body):?>
        <div style="margin:10px 0"><strong><?=h($name)?></strong><div class="bgbox" style="margin-top:6px"><?=nl2br(h($body))?></div></div>
      <?php endforeach;?>
    <?php endforeach;?>
    <?php if(!$secs && !$bgRecords && !empty($bgPage['raw_excerpt'])):?><p class="muted">No recognized progression section on this page; showing cleaned article excerpt.</p><div class="bgbox"><?=h($bgPage['raw_excerpt'])?></div><?php endif;?>
  <?php else:?>
    <p>No cached metadata yet.</p>
    <p><a class="good" href="<?=h(bgSearch($item['name']))?>" target="_blank" rel="noreferrer">Search BG-Wiki</a></p>
    <code>php bin/import-bgwiki.php "<?=h($item['name'])?>"</code>
  <?php endif;?>
</div>

<?php elseif($view==='item'):?>
<div class="card"><h2>Noch nicht im lokalen Item-Katalog</h2><p>„<?=h($itemNameQuery)?>“ ist aktuell weder über GearFinder, LuAshitacast, Ziele noch den lokalen BG-Wiki-Cache aufgelöst. Das ist kein Endpunkt: du kannst weiter recherchieren oder den Namen als eigenes Ziel aufnehmen.</p><div class="rowactions"><a class="good pill" href="<?=h(bgSearch($itemNameQuery))?>" target="_blank" rel="noreferrer">Auf BG-Wiki suchen ↗</a><a class="pill" href="<?=h(advancedUrl('items',['q'=>$itemNameQuery]))?>">Im gesamten Item-Katalog suchen</a></div><?php if($itemNameQuery!==''):?><form method="post" style="margin-top:14px"><input type="hidden" name="action" value="goal_item"><input type="hidden" name="item_name" value="<?=h($itemNameQuery)?>"><div class="inlineform"><select name="priority"><option>normal</option><option>high</option><option>low</option></select><button>Als eigenes Item-Ziel speichern</button></div></form><?php endif;?></div>

<?php elseif($view==='coverage'):?>
<?php // GP08_COVERAGE_CACHE_UI_BEGIN ?>
<?php $coverageCacheState=(string)($coverageReport['cache_meta']['state']??''); ?>
<?php if($coverageCacheState==='building'):?>
<section class="card" style="margin-bottom:12px"><strong>Coverage-Cache wird im Hintergrund aufgebaut.</strong><br><span class="muted">Der Cache ist an Charakter, Snapshots, Datenbank und Codeversion gebunden. Nach Abschluss neu laden; Status: <code>php bin/coverage-cache-status.php</code>.</span></section>
<?php elseif($coverageCacheState==='stale'):?>
<section class="card" style="margin-bottom:12px"><strong>Veralteter, aber kontextpassender Coverage-Cache.</strong><br><span class="muted">Alter <?=h((string)($coverageReport['cache_meta']['age_seconds']??'—'))?> s; Aktualisierung läuft im Hintergrund.</span></section>
<?php elseif(isset($coverageReport['cache_meta']['age_seconds'])):?>
<section class="card" style="margin-bottom:12px"><span class="muted">Coverage aus kontextgebundenem Cache · Alter <?=h((string)$coverageReport['cache_meta']['age_seconds'])?> s.</span></section>
<?php endif;?>
<?php // GP08_COVERAGE_CACHE_UI_END ?>
<?php
$covRows=$coverageReport['items'];
if($coverageFilter!=='ALL')$covRows=array_values(array_filter($covRows,static fn($r)=>($r['coverage']??'')===$coverageFilter));
?>
<div class="cards">
  <div class="card"><span class="muted">Relevant items</span><b class="big"><?=h($coverageReport['total'])?></b><small>LAC requirements/options + active/planned goals</small></div>
  <div class="card"><span class="muted">Modeled / actionable</span><b class="big good"><?=h($coverageReport['modeled_percent'])?>%</b><small>FULL + ACQUISITION_ONLY</small></div>
  <div class="card"><span class="muted">Fully modeled</span><b class="big"><?=h($coverageReport['full_percent'])?>%</b><small>current desired state has complete planning knowledge</small></div>
  <div class="card"><span class="muted">Latest-tier awareness</span><b class="big good"><?=h($latestTierAudit['latest_awareness_percent'])?>%</b><small><?=h($latestTierAudit['tier_relevant'])?> tier/system-relevant items</small></div>
  <div class="card"><span class="muted">Post-2024 verified</span><b class="big"><?=h($latestTierAudit['post_2024_verified_percent'])?>%</b><small>knowledge <?=h($latestTierAudit['knowledge_version'])?> · through <?=h($latestTierAudit['verified_through'])?></small></div>
  <div class="card"><span class="muted">Newer tiers available</span><b class="big warn"><?=h($latestTierAudit['counts']['NEWER_TIER_AVAILABLE']??0)?></b><small>advisory; never silently changes LAC/goals</small></div>
  <div class="card"><span class="muted">Freshness review</span><b class="big bad"><?=h($latestTierAudit['counts']['REVIEW_REQUIRED']??0)?></b><small>modeled systems not yet mapped to current registry</small></div>
  <div class="card"><span class="muted">Unresolved</span><b class="big bad"><?=h($coverageReport['counts']['UNRESOLVED']??0)?></b><small>highest-priority parser/source gaps</small></div>
</div>

<form class="toolbar">
  <input type="hidden" name="view" value="coverage">
  <select name="covjob">
    <?php foreach(['ALL'=>'All jobs/goals','NIN'=>'NIN','THF'=>'THF','COMMON'=>'COMMON'] as $v=>$label):?><option value="<?=h($v)?>" <?=$coverageJob===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <select name="coverage">
    <?php foreach(['ALL'=>'All coverage states','FULL'=>'FULL','ACQUISITION_ONLY'=>'ACQUISITION ONLY','SYSTEM_KNOWN_DATA_MISSING'=>'SYSTEM / DATA GAP','PARTIAL'=>'PARTIAL','UNRESOLVED'=>'UNRESOLVED'] as $v=>$label):?><option value="<?=h($v)?>" <?=$coverageFilter===$v?'selected':''?>><?=h($label)?></option><?php endforeach;?>
  </select>
  <button>Filter</button>
</form>

<div class="cards">
<?php foreach(['FULL','ACQUISITION_ONLY','SYSTEM_KNOWN_DATA_MISSING','PARTIAL','UNRESOLVED'] as $cs):?>
  <a class="card" href="?view=coverage&covjob=<?=h($coverageJob)?>&coverage=<?=h($cs)?>"><span class="muted"><?=h(str_replace('_',' ',$cs))?></span><b class="big <?=coverageClass($cs)?>"><?=h($coverageReport['counts'][$cs]??0)?></b></a>
<?php endforeach;?>
</div>

<?php if($coverageReport['gap_systems']):?>
<section class="group"><h2>Highest-impact missing system adapters / data</h2><p class="muted">Grouped from structured progression actions that exist but are not yet fully modeled. This is the queue for the next adapters.</p>
<div class="chips"><?php foreach(array_slice($coverageReport['gap_systems'],0,12,true) as $label=>$n):?><span class="chip"><b><?=h($n)?></b> <?=h($label)?></span><?php endforeach;?></div></section>
<?php endif;?>

<?php $newerRows=array_values(array_filter($latestTierAudit['rows'],static fn($r)=>($r['latest_status']??'')==='NEWER_TIER_AVAILABLE')); $reviewRows=array_values(array_filter($latestTierAudit['rows'],static fn($r)=>($r['latest_status']??'')==='REVIEW_REQUIRED')); ?>
<section class="group"><h2>Latest-tier / post-2024 audit</h2>
<p class="muted">Independent of current-target coverage. A target can be FULL while a newer tier exists. Knowledge registry: <?=h($latestTierAudit['knowledge_version'])?>, verified through <?=h($latestTierAudit['verified_through'])?>.</p>
<?php if($newerRows):?><h3>Newer tiers available</h3><div class="tablebox"><table><thead><tr><th>Current target</th><th>Latest known tier</th><th>Why</th></tr></thead><tbody><?php foreach($newerRows as $r):?><tr><td><a class="good" href="<?=h(itemUrl($r['name']))?>"><?=h($r['name'])?></a></td><td class="warn"><strong><?=h($r['latest_tier'])?></strong></td><td><?=h($r['latest_reason'])?></td></tr><?php endforeach;?></tbody></table></div><?php else:?><p class="good">No newer tier is currently flagged in this scope.</p><?php endif;?>
<?php if($reviewRows):?><h3>Freshness review required</h3><div class="chips"><?php foreach($reviewRows as $r):?><a class="chip bad" href="<?=h(itemUrl($r['name']))?>"><?=h($r['name'])?></a><?php endforeach;?></div><?php endif;?>
<h3>Tracked post-2024 update timeline</h3><div class="tablebox"><table><thead><tr><th>Date</th><th>System</th><th>Update</th></tr></thead><tbody><?php foreach($latestTierAudit['timeline'] as $u):?><tr><td><?=h($u['date'])?></td><td><?=h($u['system'])?></td><td><a class="good" href="<?=h($u['source'])?>" target="_blank" rel="noreferrer"><?=h($u['label'])?></a></td></tr><?php endforeach;?></tbody></table></div>
</section>

<section class="group"><h2>Coverage details</h2>
<div class="tablebox"><table><thead><tr><th>Item</th><th>Coverage</th><th>Current need</th><th>Owned</th><th>Jobs</th><th>External knowledge</th><th>Reason / target</th></tr></thead><tbody>
<?php foreach($covRows as $r):?>
<tr>
 <td><a class="good" href="<?=h(itemUrl($r['name']))?>"><strong><?=h($r['name'])?></strong></a><?php if($r['item_id']):?><div class="mini muted">ID <?=h($r['item_id'])?></div><?php endif;?></td>
 <td class="<?=coverageClass($r['coverage'])?>"><strong><?=h($r['coverage'])?></strong></td>
 <td><span class="badge"><?=h($r['need'])?></span></td>
 <td><?=h($r['owned_count'])?></td>
 <td><?=h($r['jobs']?implode(' · ',$r['jobs']):'Goal')?></td>
 <td><div class="sourcebadges"><?php if($r['bgwiki_cached']):?><span class="badge">BG-Wiki</span><?php endif;?><?php if($r['acquisition_route_count']):?><span class="badge"><?=h($r['acquisition_route_count'])?> routes</span><?php endif;?><?php if($r['progression_action_count']):?><span class="badge"><?=h($r['progression_action_count'])?> actions</span><?php endif;?><?php foreach($r['known_systems'] as $sys):?><span class="badge good"><?=h($sys)?></span><?php endforeach;?></div></td>
 <td><?=h($r['coverage_reason'])?><?php if($r['target_augments']):?><div class="mini warn">Target: <?=h(implode(' / ',$r['target_augments']))?></div><?php endif;?></td>
</tr>
<?php endforeach;?>
<?php if(!$covRows):?><tr><td colspan="7" class="empty">Keine Items passen zu diesem Coverage-Filter.<div class="rowactions" style="justify-content:center;margin-top:10px"><a class="pill good" href="<?=h(advancedUrl('coverage'))?>">Filter zurücksetzen</a><a class="pill" href="/?tab=plan">Zur Gearplanung</a></div></td></tr><?php endif;?>
</tbody></table></div></section>

<?php elseif($view==='jobs'):?>
<div class="nav"><?php foreach($profiles as $p):?><a class="<?=$job===$p['job']?'active':''?>" href="<?=h(advancedUrl('jobs',['job'=>$p['job']]))?>"><?=h($p['job'])?></a><?php endforeach;?></div>
<?php if($readiness):?>
<div class="cards">
  <div class="card"><span class="muted">Base ownership</span><b class="big"><?=$readiness['total']['present_percent']?>%</b><small><?=$readiness['total']['present']?>/<?=$readiness['total']['requirements']?> requirements present</small></div>
  <div class="card"><span class="muted">Verified completion</span><b class="big"><?=$bridgeSnapshot?$readiness['total']['verified_percent'].'%':'PENDING'?></b><small><?=$bridgeSnapshot?'live augment layer included':'wird automatisch neu berechnet, sobald aktuelle FFXI-/Augmentdaten vorliegen'?></small></div>
  <div class="card"><span class="muted">Selectable options</span><b class="big"><?=count($options)?></b><small>do not reduce readiness when missing</small></div>
</div>
<?php if($selectedSet&&$setProgress):?>
<p><a class="muted" href="<?=h(advancedUrl('jobs',['job'=>$job]))?>">← All <?=h($job)?> sets</a></p>
<div class="card"><div class="inlineform"><h2 style="margin-right:auto"><?=h($selectedSet['raw_name'])?></h2><form method="post"><input type="hidden" name="action" value="goal_set"><input type="hidden" name="entity_key" value="<?=h($selectedSet['set_key'])?>"><input type="hidden" name="label" value="<?=h($job.' '.$selectedSet['raw_name'])?>"><button>Add set to Goals</button></form></div><p><span class="badge"><?=h($selectedSet['activation_type'])?></span> · <?=$setProgress['present']?>/<?=$setProgress['total']?> present · <?=$setProgress['done']?> verified · <?=$setProgress['unverified']?> live unread</p></div>
<div class="tablebox"><table><thead><tr><th>Slot</th><th>Requested item</th><th>Target</th><th>Match</th><th>Owned location</th><th>Metadata</th></tr></thead><tbody>
<?php foreach($setProgress['results'] as $x):$r=$x['requirement'];$m=$x['match'];?><tr><td><?=h($r['slot_name'])?></td><td><a class="good" href="<?=h(itemUrl($r['item_name']))?>"><strong><?=h($r['item_name'])?></strong></a></td><td><?=h(targetText($r))?></td><td class="<?=statusClass($m['status'])?>"><strong><?=h($m['label'])?></strong><br><small><?=h($m['detail'])?></small></td><td><?=h($m['candidate']?($m['candidate']['bag_name'].' / '.$m['candidate']['bag_slot']):'—')?></td><td><a class="good" href="<?=h(itemUrl($r['item_name']))?>">Item</a> · <a class="muted" href="<?=h(bgSearch($r['item_name']))?>" target="_blank" rel="noreferrer">BG</a></td></tr><?php endforeach;?>
</tbody></table></div>
<?php else:?>
<?php $byCat=[];foreach($sets as $s)$byCat[$s['category']][]=$s;foreach($byCat as $cat=>$catSets):?><section class="group"><h2><?=h($cat)?></h2><div class="setgrid"><?php foreach($catSets as $s):$p=$s['progress'];?><a class="setcard" href="<?=h(advancedUrl('jobs',['job'=>$job,'set'=>$s['id']]))?>"><div class="muted"><?=h($s['activation_type'])?></div><h3><?=h($s['raw_name'])?></h3><div><strong><?=$p['present']?>/<?=$p['total']?></strong> present<?php if($bridgeSnapshot):?> · <span class="good"><?=$p['done']?> verified</span><?php elseif($p['unverified']):?> · <span class="warn"><?=$p['unverified']?> live checks pending</span><?php endif;?></div><div class="progress"><span style="width:<?=$p['present_percent']?>%"></span></div></a><?php endforeach;?></div></section><?php endforeach;?>
<?php if($options):?><section class="group"><h2>Selectable / conditional options</h2><div class="tablebox"><table><thead><tr><th>Cycle</th><th>Option</th><th>Slot</th><th>Item</th><th>Default</th><th>Target</th></tr></thead><tbody><?php foreach($options as $o):?><tr><td><?=h($o['cycle_name'])?></td><td><?=h($o['option_value'])?></td><td><?=h($o['slot_name'])?></td><td><a class="good" href="<?=h(itemUrl($o['item_name']))?>"><?=h($o['item_name'])?></a></td><td><?=$o['is_default']?'✓':'—'?></td><td><?=h(targetText($o))?></td></tr><?php endforeach;?></tbody></table></div></section><?php endif;?>
<?php endif;?>
<?php endif;?>

<?php elseif($view==='goals'):?>
<div class="setgrid">
<?php foreach($goalCards as $c):$g=$c['goal'];$p=$c['progress']??null;?>
<div class="setcard">
  <div><span class="badge"><?=h(strtoupper($g['status']))?></span> <span class="badge"><?=h(strtoupper($g['priority']))?></span> <span class="badge"><?=h($g['origin'])?></span></div>
  <h3><?=h($g['label'])?></h3>
  <?php if(($g['entity_type']??'')==='item' && !empty($c['item'])):?><p><a class="good" href="<?=h(itemUrl($c['item']['name']))?>">Item öffnen</a> · <span class="<?=statusClass($c['item']['status'])?>"><?=h($c['item']['status'])?></span></p><?php endif;?>
  <?php if($p):?>
    <p><strong><?=$p['present']?>/<?=$p['total']?></strong> present · <?php if($bridgeSnapshot):?><strong><?=$p['done']?>/<?=$p['total']?></strong> verified<?php else:?><span class="warn">Augment-Prüfung steht noch aus</span><?php endif;?></p>
    <div class="progress"><span style="width:<?=$p['present_percent']?>%"></span></div>
    <?php foreach($p['results'] as $x):$rr=$x['requirement'];$mm=$x['match'];?>
      <div class="<?=statusClass($mm['status'])?>" style="margin:5px 0"><?=h($rr['slot_name'])?> · <a class="good" href="<?=h(itemUrl($rr['item_name']))?>"><?=h($rr['item_name'])?></a> — <?=h($mm['label'])?><?php if($mm['status']==='MISSING'):?><form method="post" style="display:inline;margin-left:6px"><input type="hidden" name="action" value="task_add"><input type="hidden" name="title" value="Obtain <?=h($rr['item_name'])?>"><input type="hidden" name="task_type" value="obtain"><input type="hidden" name="priority" value="<?=h($g['priority'])?>"><input type="hidden" name="entity_type" value="item"><input type="hidden" name="entity_key" value="<?=h($rr['item_name_norm'])?>"><button style="padding:2px 6px">+ To-Do</button></form><?php endif;?></div>
    <?php endforeach;?>
  <?php endif;?>
  <form method="post" style="margin-top:12px"><input type="hidden" name="action" value="goal_save"><input type="hidden" name="id" value="<?=h($g['id'])?>"><div class="inlineform"><select name="status"><?php foreach(['active','planned','complete','ignored','archived'] as $v):?><option <?=$g['status']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select><select name="priority"><?php foreach(['high','normal','low'] as $v):?><option <?=$g['priority']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select><button>Speichern</button></div><textarea name="note"><?=h($g['note'])?></textarea></form>
  <form method="post" style="margin-top:8px" onsubmit="return confirm('Dieses Ziel wirklich löschen?')"><input type="hidden" name="action" value="goal_delete"><input type="hidden" name="id" value="<?=h($g['id'])?>"><button class="bad">Ziel löschen</button></form>
</div>
<?php endforeach;?>
</div>

<?php elseif($view==='tasks'):?>
<div class="card"><h2>Aufgabe hinzufügen</h2><form method="post"><input type="hidden" name="action" value="task_add"><div class="inlineform"><input type="text" name="title" placeholder="z. B. Purity Ring beschaffen" required><select name="task_type"><option>obtain</option><option>augment</option><option>rank</option><option>upgrade</option><option>farm</option></select><select name="priority"><option>normal</option><option>high</option><option>low</option></select><input type="text" name="content_name" placeholder="Inhalt / Content (optional)"><button>Hinzufügen</button></div><textarea name="note" placeholder="Notizen"></textarea></form></div>
<div class="setgrid" style="margin-top:14px"><?php foreach($tasks as $t):?><div class="setcard">
  <h3><?=h($t['title'])?></h3>
  <p><span class="badge"><?=h($t['task_type'])?></span> <?php if($t['content_name']):?><span class="badge"><?=h($t['content_name'])?></span><?php endif;?> <?php if(($t['entity_type']??'')==='item'&&($t['entity_key']??'')!==''):?><a class="good" href="<?=h(itemUrl((string)$t['entity_key']))?>">Item</a><?php endif;?></p>
  <?php if($t['route']):?><p class="muted">Bezugsweg: <?=h($t['route']['source_section'])?><?php if($t['route']['source_url']):?> · <a class="good" href="<?=h($t['route']['source_url'])?>" target="_blank" rel="noreferrer">Quelle</a><?php endif;?></p><?php endif;?>
  <?php if($t['steps']):?><p><strong><?=h($t['steps_done'])?>/<?=count($t['steps'])?></strong> Schritte erledigt</p><ul class="steps"><?php foreach($t['steps'] as $step):?><li><form method="post"><input type="hidden" name="action" value="task_step"><input type="hidden" name="step_id" value="<?=h($step['id'])?>"><input type="hidden" name="step_status" value="<?=$step['status']==='done'?'open':'done'?>"><button class="tinybtn" title="Toggle step"><?=$step['status']==='done'?'✓':'○'?></button></form><span class="<?=$step['status']==='done'?'stepdone':''?>"><?=h($step['label'])?></span></li><?php endforeach;?></ul><?php endif;?>
  <form method="post"><input type="hidden" name="action" value="task_save"><input type="hidden" name="id" value="<?=h($t['id'])?>"><div class="inlineform"><select name="status"><?php foreach(['open','doing','done','ignored'] as $v):?><option <?=$t['status']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select><select name="priority"><?php foreach(['high','normal','low'] as $v):?><option <?=$t['priority']===$v?'selected':''?>><?=$v?></option><?php endforeach;?></select><button>Speichern</button></div><textarea name="note"><?=h($t['note'])?></textarea></form>
</div><?php endforeach;?></div>

<?php elseif($view==='changes'):?>
<?php if(!$diff||!$diff['available']):?><div class="card"><h2>Noch kein Vergleich möglich</h2><p>Für den Vergleich werden zwei unterschiedliche GearFinder-Snapshots benötigt. Ohne Änderung wird kein künstlicher neuer Snapshot erzeugt.</p></div><?php else:?><div class="cards"><div class="card"><span class="muted">Added</span><b class="big"><?=count($diff['added'])?></b></div><div class="card"><span class="muted">Removed</span><b class="big"><?=count($diff['removed'])?></b></div><div class="card"><span class="muted">Moved</span><b class="big"><?=count($diff['moved'])?></b></div><div class="card"><span class="muted">Augment geändert</span><b class="big"><?=count($diff['augment_changed'])?></b></div></div><?php endif;?>

<?php elseif($view==='sources'):?>
<div class="card" style="margin-bottom:14px"><h3>Technische Datenprüfung</h3><p class="muted">Diese Prüfungen sind für Wartung und Fehlersuche gedacht und gehören deshalb nicht in die Hauptnavigation.</p><a class="good" href="?view=coverage">Vollständigkeit der Upgrade- und Quelleninformationen prüfen</a></div>
<div class="tablebox"><table><thead><tr><th>Source</th><th>Status</th><th>Age</th><th>Detail</th></tr></thead><tbody><?php foreach($health as $r):?><tr><td><strong><?=h($r['source'])?></strong></td><td class="<?=$r['status']==='OK'?'good':($r['status']==='PENDING'?'warn':'bad')?>"><?=h($r['status'])?></td><td><?=h($r['age_hours']!==null?$r['age_hours'].' h':'—')?></td><td><?=h($r['detail'])?></td></tr><?php endforeach;?></tbody></table></div>
<details class="card technical" style="margin-top:14px"><summary>Wartungsbefehle anzeigen</summary><p><code>php bin/source-health.php</code> prüft lokale Quellen.</p><p><code>php bin/import-bgwiki.php --missing --limit=20</code> ergänzt fehlende Metadaten aus dem vorhandenen Cache-/Importpfad.</p></details>
<?php endif;?>

<?php endif;?>
</div>
<script>if("serviceWorker" in navigator){navigator.serviceWorker.register("/sw.js").catch(()=>{});}</script>


</body>
</html>
