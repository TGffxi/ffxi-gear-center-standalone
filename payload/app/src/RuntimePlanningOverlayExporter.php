<?php
declare(strict_types=1);

/**
 * Bridges the historical SQLite goal/To-Do domain into the 1.0 Python runtime.
 *
 * This is deliberately an integration layer around the live-tested core classes:
 * GoalRepository, TaskRepository, ItemCatalog, ProgressionChainService and
 * ProgressionResourceService remain unchanged.
 *
 * Rules:
 * - only explicit USER goals / To-Dos are exported;
 * - LAC set requirements retain exact augment fields;
 * - BG-Wiki progression resources come only from structured cached records;
 * - ANY-OF alternatives remain alternatives;
 * - unknown quantities remain null and are never guessed as zero.
 */
final class RuntimePlanningOverlayExporter
{
    public const SCHEMA = 'gearcenter.runtime-planning-overlay/1.0';

    public function __construct(
        private PDO $pdo,
        private int $characterId,
        private int $snapshotId,
        private ?int $augmentSnapshotId = null,
    ) {}

    /** @return array<string,mixed> */
    public function build(): array
    {
        $goalRepo = new GoalRepository($this->pdo, $this->characterId);
        $taskRepo = new TaskRepository($this->pdo, $this->characterId);
        $catalog = new ItemCatalog($this->pdo, $this->characterId, $this->snapshotId, $this->augmentSnapshotId);
        $bgRepo = new BgWikiRepository($this->pdo);
        $chain = new ProgressionChainService($bgRepo);
        $resources = new ProgressionResourceService();

        $requirementsTasks = [];
        $runtimeGoals = [];
        $goalEvidence = [];

        foreach ($goalRepo->all() as $goal) {
            if (!in_array((string)$goal['status'], ['active', 'planned'], true)) continue;
            $task = $this->goalTask($goal, $catalog);
            if ($task === null) {
                $goalEvidence[] = [
                    'sqlite_goal_id'=>(int)$goal['id'],
                    'entity_type'=>(string)$goal['entity_type'],
                    'entity_key'=>(string)$goal['entity_key'],
                    'label'=>(string)$goal['label'],
                    'status'=>'UNRESOLVED',
                    'reason'=>'SQLite goal could not be mapped to an explicit item/LAC-set requirement.',
                ];
                continue;
            }
            $requirementsTasks[] = $task;
            $runtimeGoals[] = [
                'task_id'=>(string)$task['id'],
                'enabled'=>true,
                'priority'=>$this->priorityNumber((string)$goal['priority']),
                'note'=>(string)($goal['note'] ?? ''),
                'origin'=>'sqlite',
                'external_goal_id'=>(int)$goal['id'],
            ];
            $goalEvidence[] = [
                'sqlite_goal_id'=>(int)$goal['id'],
                'entity_type'=>(string)$goal['entity_type'],
                'entity_key'=>(string)$goal['entity_key'],
                'label'=>(string)$goal['label'],
                'status'=>'EXPORTED',
                'runtime_task_id'=>(string)$task['id'],
                'requirement_count'=>count($task['requirements'] ?? []),
            ];
        }

        $progressionTodos = [];
        $taskEvidence = [];
        foreach ($taskRepo->all() as $task) {
            if (!in_array((string)$task['status'], ['open', 'doing'], true)) continue;
            $route = is_array($task['route'] ?? null) ? $task['route'] : null;
            if ($route === null) continue;
            $meta = json_decode((string)($route['meta_json'] ?? '{}'), true);
            if (!is_array($meta)) $meta = [];
            $routeFields = is_array($meta['fields'] ?? null) ? $meta['fields'] : [];
            $selectedInstance = is_array($routeFields['selected_instance'] ?? null) ? $routeFields['selected_instance'] : null;
            $itemName = trim((string)($meta['item'] ?? $task['content_name'] ?? ''));
            $row = [
                'id'=>'sqlite-todo:' . (int)$task['id'],
                'sqlite_task_id'=>(int)$task['id'],
                'title'=>(string)$task['title'],
                'status'=>(string)$task['status'],
                'priority'=>$this->priorityNumber((string)$task['priority']),
                'item_name'=>$itemName,
                'entity_type'=>$task['entity_type'] ?? null,
                'entity_key'=>$task['entity_key'] ?? null,
                'route_key'=>(string)$route['route_key'],
                'route_kind'=>(string)$route['route_kind'],
                'source_title'=>$route['source_title'] ?? null,
                'source_url'=>$route['source_url'] ?? null,
                'source_section'=>$route['source_section'] ?? null,
                'summary'=>$route['summary'] ?? null,
                'steps'=>array_values(array_map(static fn(array $s): array => [
                    'id'=>(int)$s['id'],
                    'index'=>(int)$s['step_index'],
                    'label'=>(string)$s['label'],
                    'status'=>(string)$s['status'],
                ], $task['steps'] ?? [])),
                'requirements'=>[],
                'alternative_groups'=>[],
                'systems'=>[],
                'selected_instance'=>$selectedInstance,
                'manage_url'=>'/advanced.php?view=tasks&compact=1&return=farming',
            ];

            if ((string)$route['route_kind'] === 'progression_plan' && $itemName !== '') {
                $plan = $chain->findPlan($itemName, (string)$route['route_key']);
                if ($plan !== null) {
                    $item = $catalog->find($itemName) ?? ['name'=>$itemName, 'item_id'=>null, 'superior_level'=>null];
                    // Fixed material holdings are evaluated later against the Python live
                    // inventory.  Augment/rank state, however, belongs to one physical gear copy.
                    // If the To-Do was created from a selected copy, reuse only that exact copy;
                    // otherwise remain conservative when duplicates exist.
                    $liveAugment = $this->liveAugmentForTask($catalog, $itemName, $selectedInstance);
                    $summary = $resources->forPlan($plan, [], $liveAugment, [
                        'name'=>(string)($item['name'] ?? $itemName),
                        'item_id'=>$item['item_id'] ?? null,
                        'superior_level'=>$item['superior_level'] ?? null,
                    ]);
                    $row['requirements'] = array_values($summary['requirements'] ?? []);
                    $row['alternative_groups'] = array_values($summary['alternative_groups'] ?? []);
                    $row['systems'] = array_values($summary['systems'] ?? []);
                    $row['plan_title'] = (string)($summary['plan_title'] ?? $plan['title'] ?? $task['title']);
                }
            }
            $progressionTodos[] = $row;
            $taskEvidence[] = [
                'sqlite_task_id'=>(int)$task['id'],
                'route_kind'=>(string)$route['route_kind'],
                'route_key'=>(string)$route['route_key'],
                'item_name'=>$itemName,
                'fixed_requirement_count'=>count($row['requirements']),
                'alternative_group_count'=>count($row['alternative_groups']),
                'system_count'=>count($row['systems']),
                'selected_instance'=>$selectedInstance,
            ];
        }

        $char = $this->pdo->prepare('SELECT name,server_id FROM characters WHERE id=:id LIMIT 1');
        $char->execute([':id'=>$this->characterId]);
        $character = $char->fetch() ?: [];

        return [
            'schema'=>self::SCHEMA,
            'generated_at'=>gmdate('c'),
            'character'=>[
                'id'=>$this->characterId,
                'name'=>(string)($character['name'] ?? ''),
                'server_id'=>isset($character['server_id']) ? (int)$character['server_id'] : null,
            ],
            'snapshot_id'=>$this->snapshotId,
            'augment_snapshot_id'=>$this->augmentSnapshotId,
            'requirements'=>['schema'=>'gearplaner.requirements/0.6','tasks'=>$requirementsTasks],
            'goals'=>['schema'=>'gearplaner.goals/0.6','goals'=>$runtimeGoals],
            'progression_todos'=>$progressionTodos,
            'evidence'=>['goals'=>$goalEvidence,'tasks'=>$taskEvidence],
            'rules'=>[
                'sqlite_goals_are_explicit_personal_goals'=>true,
                'structured_bgwiki_only'=>true,
                'unknown_quantity_is_null'=>true,
                'alternatives_are_not_summed'=>true,
            ],
        ];
    }

    public function write(string $path): array
    {
        $payload = $this->build();
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create runtime overlay directory: ' . $dir);
        }
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR) . "\n";
        $tmp = $path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json, LOCK_EX) === false) throw new RuntimeException('Could not write runtime overlay temp file.');
        if (!rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Could not atomically replace runtime overlay.'); }
        return $payload;
    }

    /** @return array<string,mixed>|null */
    private function goalTask(array $goal, ItemCatalog $catalog): ?array
    {
        $goalId = (int)$goal['id'];
        $type = (string)$goal['entity_type'];
        $key = (string)$goal['entity_key'];
        $label = trim((string)$goal['label']) !== '' ? (string)$goal['label'] : $key;
        $base = [
            'id'=>'sqlite-goal:' . $goalId,
            'title'=>$label,
            'description'=>'Personal SQLite goal from the live-tested Gear Center goal repository.',
            'source_type'=>'manual',
            'source_ref'=>'sqlite:goal:' . $goalId,
            'priority_hint'=>$this->priorityNumber((string)$goal['priority']),
            'source_meta'=>[
                'goal_origin'=>'sqlite',
                'external_goal_id'=>$goalId,
                'entity_type'=>$type,
                'entity_key'=>$key,
                'manage_url'=>'/advanced.php?view=goals&compact=1&return=plan',
            ],
            'requirements'=>[],
        ];

        if ($type === 'item') {
            $item = $catalog->find($label) ?? $catalog->find($key);
            $name = (string)($item['name'] ?? $label);
            $base['category'] = 'Personal item goal';
            $base['source_meta']['set_name'] = $label;
            $base['requirements'][] = [
                'kind'=>'item','item_id'=>(int)($item['item_id'] ?? 0),'item_name'=>$name,
                'quantity'=>1,'slots'=>[],'details'=>[],'augment_required'=>false,
            ];
            return $base;
        }

        if ($type !== 'set') return null;
        $s = $this->pdo->prepare(
            "SELECT s.id,s.raw_name,p.job
             FROM lac_sets s
             JOIN v_latest_lac_profiles p ON p.id=s.profile_snapshot_id
             WHERE p.character_id=:cid AND s.set_key=:key
             ORDER BY p.job,s.id"
        );
        $s->execute([':cid'=>$this->characterId, ':key'=>$key]);
        $sets = $s->fetchAll();
        if ($sets === []) return null;
        $jobs=[];$setNames=[];$reqs=[];
        foreach ($sets as $set) {
            $jobs[(string)$set['job']]=true;$setNames[(string)$set['raw_name']]=true;
            $r = $this->pdo->prepare('SELECT * FROM lac_requirements WHERE set_id=:id ORDER BY id');
            $r->execute([':id'=>(int)$set['id']]);
            foreach ($r->fetchAll() as $req) {
                $item = $catalog->find((string)$req['item_name']);
                $augStrings=json_decode((string)($req['augment_strings_json'] ?? '[]'),true);
                if(!is_array($augStrings))$augStrings=[];
                $augmentRequired = ($req['aug_path'] ?? null) !== null || ($req['aug_rank'] ?? null) !== null || ($req['aug_trial'] ?? null) !== null || $augStrings !== [];
                $reqs[] = [
                    'kind'=>'item',
                    'item_id'=>(int)($item['item_id'] ?? 0),
                    'item_name'=>(string)$req['item_name'],
                    'quantity'=>1,
                    'slots'=>[(string)$req['slot_name']],
                    'augment_required'=>$augmentRequired,
                    'details'=>array_values(array_map('strval',$augStrings)),
                    'aug_path'=>$req['aug_path'] ?? null,
                    'aug_rank'=>$req['aug_rank'] ?? null,
                    'aug_trial'=>$req['aug_trial'] ?? null,
                ];
            }
        }
        $jobList=array_keys($jobs);sort($jobList);$nameList=array_keys($setNames);sort($nameList);
        $base['category']='Personal LAC set goal';
        $base['job']=count($jobList)===1?$jobList[0]:implode('+',$jobList);
        $base['source_meta']['set_name']=$label;
        $base['source_meta']['lac_set_names']=$nameList;
        $base['requirements']=$reqs;
        return $base;
    }

    /**
     * Resolve live progression state conservatively for one persisted To-Do.
     * A stored physical instance wins; stale/conflicting identity yields no live
     * state rather than silently switching to another duplicate.
     *
     * @param array<string,mixed>|null $selectedInstance
     * @return array<string,mixed>
     */
    private function liveAugmentForTask(ItemCatalog $catalog, string $itemName, ?array $selectedInstance): array
    {
        $copies = $catalog->ownedCopies($itemName);
        $live = array_values(array_filter($copies, static fn(array $copy): bool => ($copy['bridge_item_id'] ?? null) !== null));

        $chosen = null;
        if ($selectedInstance !== null) {
            $container = isset($selectedInstance['container']) ? (int)$selectedInstance['container'] : null;
            $slot = isset($selectedInstance['slot']) ? (int)$selectedInstance['slot'] : null;
            $expectedItemId = isset($selectedInstance['item_id']) ? (int)$selectedInstance['item_id'] : null;
            $expectedExtra = $this->normalizeExtra($selectedInstance['extra_hex'] ?? '');
            foreach ($copies as $copy) {
                if ($container === null || $slot === null) break;
                if ((int)$copy['bag_id'] !== $container || (int)$copy['bag_slot'] !== $slot) continue;
                if ($expectedItemId !== null && (int)$copy['item_id'] !== $expectedItemId) return ['copy_count'=>0,'instance_state'=>'ITEM_ID_CONFLICT'];
                if (($copy['bridge_item_id'] ?? null) === null) return ['copy_count'=>0,'instance_state'=>'LIVE_READING_MISSING'];
                $actualExtra = $this->normalizeExtra($copy['bridge_raw_extra'] ?? '');
                if (strlen($expectedExtra) === 48 && strlen($actualExtra) === 48 && $expectedExtra !== $actualExtra) {
                    return ['copy_count'=>0,'instance_state'=>'EXTRA_CONFLICT'];
                }
                $chosen = $copy;
                break;
            }
            if ($chosen === null) return ['copy_count'=>0,'instance_state'=>'INSTANCE_MISSING'];
        } elseif (count($live) === 1) {
            $chosen = $live[0];
        } elseif (count($live) > 1) {
            return ['copy_count'=>count($live),'instance_state'=>'AMBIGUOUS_MULTIPLE_COPIES'];
        } else {
            return ['copy_count'=>0,'instance_state'=>'LIVE_READING_MISSING'];
        }

        return [
            'copy_count'=>1,
            'rank'=>($chosen['bridge_rank'] ?? null) !== null ? (int)$chosen['bridge_rank'] : null,
            'path'=>$chosen['bridge_path'] ?? null,
            'type'=>$chosen['bridge_type'] ?? null,
            'selected_physical_instance'=>$selectedInstance !== null,
            'instance_state'=>'MATCHED',
        ];
    }

    private function normalizeExtra(mixed $value): string
    {
        return strtolower((string)preg_replace('/[^0-9a-f]/i', '', (string)$value));
    }

    private function priorityNumber(string $priority): float
    {
        return match (strtolower(trim($priority))) {
            'high' => 3.0,
            'low' => 1.0,
            default => 2.0,
        };
    }
}
