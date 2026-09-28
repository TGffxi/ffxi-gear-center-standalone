<?php
declare(strict_types=1);

/**
 * Real PHP + PDO-SQLite release gate.
 *
 * This test NEVER mutates the supplied database. It creates a consistent SQLite
 * clone with VACUUM INTO and performs the complete goal / progression-To-Do /
 * runtime-overlay mutation cycle against that clone.
 *
 * Usage:
 *   php tests/test_php_pdo_e2e.php /path/to/gear-center.sqlite
 */

$root = dirname(__DIR__);
$app = $root . '/payload/app';
require $app . '/src/bootstrap.php';
require_once $app . '/src/RuntimePlanningOverlayExporter.php';

function qaAssert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function qaFindById(array $rows, int $id): ?array
{
    foreach ($rows as $row) if ((int)($row['id'] ?? 0) === $id) return $row;
    return null;
}

function qaNorm(string $value): string
{
    return LacProfileParser::normalizeName($value);
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "SKIP: pdo_sqlite is not loaded; this gate requires real PDO-SQLite.\n");
    exit(77);
}

$sourceDb = $argv[1] ?? (getenv('GEARCENTER_QA_DB') ?: (getenv('HOME') . '/Games/FFXI/tools/ffxi-gear-center/data/gear-center.sqlite'));
$sourceDb = (string)$sourceDb;
qaAssert(is_file($sourceDb), 'Source Gear Center database not found: ' . $sourceDb);

$tmpDir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'gearcenter-pdo-e2e-' . getmypid() . '-' . bin2hex(random_bytes(4));
qaAssert(mkdir($tmpDir, 0700, true), 'Could not create QA temp directory: ' . $tmpDir);
$cloneDb = $tmpDir . '/gear-center.qa.sqlite';
$overlayFile = $tmpDir . '/runtime-planning-overlay.json';

try {
    // Online-consistent read-only source clone. This includes committed WAL state
    // without ever writing to the user's original DB.
    $source = new PDO('sqlite:' . $sourceDb, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $source->exec('PRAGMA busy_timeout = 5000');
    qaAssert((string)$source->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Source SQLite integrity_check failed.');
    $source->exec('VACUUM INTO ' . $source->quote($cloneDb));
    $source = null;
    qaAssert(is_file($cloneDb) && filesize($cloneDb) > 0, 'Consistent SQLite clone was not created.');

    $pdo = Database::connect($cloneDb);
    Database::migrate($pdo, $app . '/sql/schema.sql');
    $encodingMigration=(new BgWikiEncodingMigrator($pdo))->run();
    qaAssert((int)$encodingMigration['remaining'] === 0, 'Legacy BG-Wiki mojibake remained after clone migration.');
    qaAssert((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Cloned SQLite integrity_check failed after migration.');

    $character = $pdo->query('SELECT id,name,server_id FROM characters ORDER BY id LIMIT 1')->fetch();
    if(!is_array($character)){
        fwrite(STDERR, "SKIP: source DB is a sanitized/schema-only public seed with no character data.\n");
        exit(77);
    }
    $characterId = (int)$character['id'];

    $snap = $pdo->prepare('SELECT id FROM v_latest_snapshots WHERE character_id=:cid LIMIT 1');
    $snap->execute([':cid'=>$characterId]);
    $snapshotId = (int)($snap->fetchColumn() ?: 0);
    if($snapshotId<=0){
        fwrite(STDERR, "SKIP: source DB has no imported inventory snapshot yet.\n");
        exit(77);
    }

    $aug = $pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');
    $aug->execute([':cid'=>$characterId]);
    $augmentSnapshotId = (($x = $aug->fetchColumn()) !== false) ? (int)$x : null;

    $goalRepo = new GoalRepository($pdo, $characterId);
    $taskRepo = new TaskRepository($pdo, $characterId);
    $catalog = new ItemCatalog($pdo, $characterId, $snapshotId, $augmentSnapshotId);
    $bgRepo = new BgWikiRepository($pdo);
    $chainService = new ProgressionChainService($bgRepo);
    $resourceService = new ProgressionResourceService();
    $exporter = new RuntimePlanningOverlayExporter($pdo, $characterId, $snapshotId, $augmentSnapshotId);

    // The live RC2 browser exposed the Ambuscade cape stat matrix as 100+ fake
    // sequential augment actions. Against the actual DB, the owned cape must now
    // keep exactly the one Gorpa-Masorpa action; stat-option rows stay reference data.
    $capeRecordCount=(int)$pdo->query("SELECT COUNT(*) FROM bgwiki_progression_records WHERE lower(page_title)=lower('Andartia''s Mantle')")->fetchColumn();
    if($capeRecordCount>0){
        $capeGraph=$chainService->build("Andartia's Mantle");
        $capeOwned=$chainService->forOwnedFocus($capeGraph);
        $capeNorm=qaNorm("Andartia's Mantle");
        $capeActions=array_values(array_filter($capeGraph['actions']??[],static fn(array $e): bool=>($e['from_norm']??'')===$capeNorm));
        qaAssert(count($capeActions)===1, 'Andartia stat matrix leaked into actionable progression: '.count($capeActions).' actions.');
        qaAssert(count($capeOwned['plans']??[])===1 && count($capeOwned['plans'][0]['steps']??[])===1, 'Owned Andartia progression did not collapse to one current action.');
        qaAssert(stripos((string)$capeOwned['plans'][0]['steps'][0],'Gorpa-Masorpa')!==false, 'Andartia current action lost Gorpa-Masorpa source context.');
    }

    // ---- Goal mutation -> runtime overlay -> removal -----------------------
    $goalCandidate = null;
    foreach (["Heishi Shorinken", "Andartia's Mantle", "Mpaca's Hose", "Fotia Gorget", "Purity Ring"] as $name) {
        $row = $catalog->find($name);
        if ($row !== null) { $goalCandidate = (string)$row['name']; break; }
    }
    if ($goalCandidate === null) {
        $row = $pdo->query("SELECT name FROM item_definitions WHERE trim(name)<>'' ORDER BY item_id LIMIT 1")->fetch();
        qaAssert(is_array($row), 'No catalog item available for goal mutation E2E.');
        $goalCandidate = (string)$row['name'];
    }

    $qaGoalKey = '__qa_e2e__' . qaNorm($goalCandidate);
    $goalRepo->upsert('item', $qaGoalKey, $goalCandidate, 'active', 'high', 'QA PDO E2E goal', 'USER');
    $goalRows = $goalRepo->all();
    $qaGoal = null;
    foreach ($goalRows as $row) if ((string)$row['entity_key'] === $qaGoalKey) { $qaGoal = $row; break; }
    qaAssert(is_array($qaGoal), 'GoalRepository upsert did not persist the QA goal.');
    $goalId = (int)$qaGoal['id'];

    $goalOverlay = $exporter->build();
    $runtimeTaskId = 'sqlite-goal:' . $goalId;
    $overlayGoalTask = null;
    foreach (($goalOverlay['requirements']['tasks'] ?? []) as $row) if ((string)($row['id'] ?? '') === $runtimeTaskId) { $overlayGoalTask = $row; break; }
    qaAssert(is_array($overlayGoalTask), 'SQLite goal did not enter runtime requirements overlay.');
    qaAssert(count($overlayGoalTask['requirements'] ?? []) >= 1, 'Runtime goal contains no concrete item requirement.');
    $overlayGoal = null;
    foreach (($goalOverlay['goals']['goals'] ?? []) as $row) if ((string)($row['task_id'] ?? '') === $runtimeTaskId) { $overlayGoal = $row; break; }
    qaAssert(is_array($overlayGoal) && ($overlayGoal['origin'] ?? '') === 'sqlite', 'SQLite goal did not enter runtime goals overlay.');

    $goalRepo->update($goalId, 'planned', 'low', 'QA PDO E2E updated');
    $updatedGoal = qaFindById($goalRepo->all(), $goalId);
    qaAssert(is_array($updatedGoal) && $updatedGoal['status'] === 'planned' && $updatedGoal['priority'] === 'low', 'GoalRepository update did not persist.');

    $goalRepo->delete($goalId);
    qaAssert(qaFindById($goalRepo->all(), $goalId) === null, 'GoalRepository delete did not remove QA goal.');
    $afterGoalDelete = $exporter->build();
    foreach (($afterGoalDelete['goals']['goals'] ?? []) as $row) {
        qaAssert((string)($row['task_id'] ?? '') !== $runtimeTaskId, 'Deleted SQLite goal remained in runtime overlay.');
    }

    // ---- Structured BG-Wiki progression -> To-Do -> runtime overlay ------
    $chosenItem = null;
    $chosenPlan = null;
    $chosenSummary = null;
    $candidates = ["Heishi Shorinken", "Andartia's Mantle", "Mpaca's Hose", "Fotia Gorget"];
    $q = $pdo->query("SELECT DISTINCT page_title FROM bgwiki_progression_records WHERE lower(section_name) LIKE '%augment%' OR lower(section_name) LIKE '%upgrade%' ORDER BY page_title LIMIT 250");
    foreach ($q->fetchAll() as $row) $candidates[] = (string)$row['page_title'];
    $candidates = array_values(array_unique(array_filter(array_map('trim', $candidates))));

    foreach ($candidates as $name) {
        $graph = $chainService->build($name);
        foreach (($graph['plans'] ?? []) as $plan) {
            $item = $catalog->find($name) ?? ['name'=>$name, 'item_id'=>null, 'superior_level'=>null];
            $summary = $resourceService->forPlan($plan, [], ['copy_count'=>0,'instance_state'=>'QA_UNKNOWN'], [
                'name'=>(string)($item['name'] ?? $name),
                'item_id'=>$item['item_id'] ?? null,
                'superior_level'=>$item['superior_level'] ?? null,
            ]);
            if (count($summary['requirements'] ?? []) + count($summary['alternative_groups'] ?? []) + count($summary['systems'] ?? []) > 0) {
                $chosenItem = $name;
                $chosenPlan = $plan;
                $chosenSummary = $summary;
                break 2;
            }
        }
    }
    qaAssert($chosenItem !== null && is_array($chosenPlan) && is_array($chosenSummary), 'No structured BG-Wiki progression plan with resource/system data was found.');

    // Ensure createRoute must perform a real insert on the disposable clone.
    $doneExisting = $pdo->prepare("UPDATE tasks SET status='done',updated_at=:now WHERE character_id=:cid AND id IN (SELECT task_id FROM task_route_details WHERE route_key=:rk)");
    $doneExisting->execute([':now'=>gmdate('c'), ':cid'=>$characterId, ':rk'=>(string)$chosenPlan['key']]);

    $taskId = $taskRepo->createRoute($chosenPlan, 'high', $chosenItem, qaNorm($chosenItem));
    qaAssert($taskId > 0, 'TaskRepository createRoute did not return an id.');
    $createdTask = qaFindById($taskRepo->all(), $taskId);
    qaAssert(is_array($createdTask), 'Progression To-Do was not persisted.');
    qaAssert((string)($createdTask['route']['route_kind'] ?? '') === 'progression_plan', 'Persisted To-Do lost progression_plan route kind.');
    qaAssert((string)($createdTask['route']['route_key'] ?? '') === (string)$chosenPlan['key'], 'Persisted To-Do lost selected BG-Wiki plan key.');

    $todoOverlay = $exporter->build();
    $todoId = 'sqlite-todo:' . $taskId;
    $overlayTodo = null;
    foreach (($todoOverlay['progression_todos'] ?? []) as $row) if ((string)($row['id'] ?? '') === $todoId) { $overlayTodo = $row; break; }
    qaAssert(is_array($overlayTodo), 'Progression To-Do did not enter runtime overlay.');
    qaAssert((string)($overlayTodo['route_kind'] ?? '') === 'progression_plan', 'Runtime overlay lost progression route kind.');
    $resourceCount = count($overlayTodo['requirements'] ?? []);
    $alternativeCount = count($overlayTodo['alternative_groups'] ?? []);
    $systemCount = count($overlayTodo['systems'] ?? []);
    qaAssert(($resourceCount + $alternativeCount + $systemCount) > 0, 'Runtime progression To-Do lost BG-Wiki resource/system data.');
    foreach (($overlayTodo['requirements'] ?? []) as $row) {
        if (($row['quantity_known'] ?? null) === false) {
            qaAssert(($row['required_quantity'] ?? null) === null, 'Unknown BG-Wiki quantity was fabricated instead of remaining null.');
        }
    }
    foreach (($overlayTodo['alternative_groups'] ?? []) as $group) {
        qaAssert(count($group['choices'] ?? []) >= 2, 'ANY-OF group was collapsed instead of preserving choices.');
    }

    $exporter->write($overlayFile);
    $decoded = json_decode((string)file_get_contents($overlayFile), true, 512, JSON_THROW_ON_ERROR);
    qaAssert(($decoded['schema'] ?? '') === RuntimePlanningOverlayExporter::SCHEMA, 'Atomic runtime overlay write produced wrong schema.');

    $taskRepo->update($taskId, 'doing', 'low', 'QA PDO E2E updated');
    $updatedTask = qaFindById($taskRepo->all(), $taskId);
    qaAssert(is_array($updatedTask) && $updatedTask['status'] === 'doing' && $updatedTask['priority'] === 'low', 'TaskRepository update did not persist.');
    if (!empty($updatedTask['steps'])) {
        $stepId = (int)$updatedTask['steps'][0]['id'];
        $taskRepo->updateStep($stepId, 'done');
        $stepTask = qaFindById($taskRepo->all(), $taskId);
        qaAssert(is_array($stepTask) && (string)$stepTask['steps'][0]['status'] === 'done', 'Task step completion did not persist.');
    }

    $doingOverlay = $exporter->build();
    $doingFound = false;
    foreach (($doingOverlay['progression_todos'] ?? []) as $row) if ((string)($row['id'] ?? '') === $todoId && (string)($row['status'] ?? '') === 'doing') $doingFound = true;
    qaAssert($doingFound, 'Updated doing To-Do did not remain in runtime overlay.');

    $taskRepo->update($taskId, 'done', 'low', 'QA PDO E2E complete');
    $doneOverlay = $exporter->build();
    foreach (($doneOverlay['progression_todos'] ?? []) as $row) {
        qaAssert((string)($row['id'] ?? '') !== $todoId, 'Completed To-Do remained active in runtime overlay.');
    }

    qaAssert((string)$pdo->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'Cloned DB integrity_check failed after mutations.');

    printf(
        "PASS: real PHP+PDO-SQLite E2E · character=%s · goal=%s · progression=%s · fixed=%d · alternatives=%d · systems=%d\n",
        (string)$character['name'], $goalCandidate, $chosenItem, $resourceCount, $alternativeCount, $systemCount
    );
} finally {
    foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);
}
