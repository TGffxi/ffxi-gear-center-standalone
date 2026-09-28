#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
$config = Config::load($root);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo, $root . '/sql/schema.sql');

$args = array_slice($argv, 1);
$limit = 20;
$missing = in_array('--missing', $args, true);
$refreshCached = in_array('--refresh-cached', $args, true);
foreach ($args as $a) if (str_starts_with($a, '--limit=')) $limit = max(1, (int)substr($a, 8));
$names = [];

if ($refreshCached) {
    $s = $pdo->query('SELECT item_name FROM bgwiki_item_map ORDER BY updated_at ASC,item_name LIMIT ' . max(1, min($limit, 500)));
    $names = array_column($s->fetchAll(), 'item_name');
} elseif ($missing) {
    $s = $pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');
    $s->execute([':name'=>$config['character']]);
    $snap = $s->fetch();
    if ($snap) {
        $b = $pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');
        $b->execute([':cid'=>$snap['character_id']]);
        $bridgeId = $b->fetchColumn();
        $catalog = new ItemCatalog($pdo, (int)$snap['character_id'], (int)$snap['id'], $bridgeId === false ? null : (int)$bridgeId);
        // Fetch the full missing catalog then choose only items whose BG-Wiki page is
        // not already cached. Older builds repeatedly selected the same first N rows.
        $missingRows = $catalog->search('', 'missing', 'all', 2000);
        foreach ($missingRows as $row) {
            if (!empty($row['bgwiki_cached'])) continue;
            $names[] = (string)$row['name'];
            if (count($names) >= $limit) break;
        }
    }
} else {
    $names = array_values(array_filter($args, static fn($x) => !str_starts_with($x, '--')));
}

$names = array_values(array_unique(array_filter(array_map('trim', $names))));
if (!$names) {
    if ($missing) {
        echo "No uncached missing catalog items found.\n";
        exit(0);
    }
    if ($refreshCached) {
        echo "No cached BG-Wiki item mappings to refresh.\n";
        exit(0);
    }
    fwrite(STDERR, "Usage: php bin/import-bgwiki.php --missing [--limit=20]\n"
        . "       php bin/import-bgwiki.php --refresh-cached [--limit=50]\n"
        . "       php bin/import-bgwiki.php \"Purity Ring\" \"Nyame Mail\"\n");
    exit(2);
}

$client = new BgWikiClient();
$resolver = new BgWikiItemResolver($pdo, $client);
$repo = new BgWikiRepository($pdo);
$ok = 0;
foreach ($names as $name) {
    try {
        $p = $resolver->fetch($name);
        $repo->save($name, $p);
        $format=(string)($p['content_format'] ?? 'html');
        $sections = BgWikiExtractor::sections((string)$p['content'], $format);
        $records = BgWikiExtractor::progressionRecords((string)$p['content'], $format);
        if ($format === 'html') $records = array_merge($records, BgWikiExtractor::versionChainRecords((string)$p['content'], (string)$p['title'], $format));
        printf("[%s] %s · rev %s%s · %d section%s · %d structured row%s\n",
            $p['exists'] ? 'OK' : 'MISS',
            $p['title'],
            $p['revision_id'] ?? '—',
            !empty($p['resolved_from_ambiguous']) ? ' · resource-resolved item #'.(int)($p['resource_item_id']??0) : '',
            count($sections),
            count($sections) === 1 ? '' : 's',
            count($records),
            count($records) === 1 ? '' : 's'
        );
        $ok++;
    } catch (Throwable $e) {
        fprintf(STDERR, "[ERROR] %s: %s\n", $name, $e->getMessage());
    }
}
echo "BG-Wiki cached: $ok/" . count($names) . "\n";
