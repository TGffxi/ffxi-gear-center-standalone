#!/usr/bin/env php
<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/src/bootstrap.php';
$config = Config::load($root);
$pdo = Database::connect($config['db_path']);
$s = $pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');
$s->execute([':name'=>$config['character']]);
$snap = $s->fetch();
if (!$snap) { fwrite(STDERR, "No GearFinder snapshot imported.\n"); exit(2); }
$b = $pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');
$b->execute([':cid'=>$snap['character_id']]);
$bridge = $b->fetch();
$cache = new CoverageCache($pdo, $root, (string)$config['db_path'], (int)$snap['character_id'], (int)$snap['id'], $bridge ? (int)$bridge['id'] : null);
echo "===== COVERAGE CACHE STATUS =====\n";
foreach (['ALL','NIN','THF','COMMON'] as $job) {
    $s = $cache->status($job);
    printf("%-6s %-18s age=%-6s items=%d%s\n", $job, strtoupper((string)$s['state']), $s['age_seconds'] ?? '—', $s['items'], !empty($s['refreshing']) ? ' · refreshing' : '');
}
