#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
$config = Config::load($root);
$options = getopt('', ['dir::', 'force']);
$source = GearFinderLocator::discover($config, $options['dir'] ?? null);
$pdo = Database::connect($config['db_path']);
Database::migrate($pdo, $root . '/sql/schema.sql');
$importer = new GearFinderImporter($pdo);
$result = $importer->import($source, array_key_exists('force', $options));

echo !empty($result['skipped']) ? "GearFinder unchanged; keeping snapshot #{$result['snapshot_id']}\n" : "Imported GearFinder snapshot #{$result['snapshot_id']}\n";
echo "Character: {$source['character']}\n";
echo "Captured: {$result['captured_at']}\n";
echo "Items: {$result['summary']['gear_entries']}\n";
echo "Unique IDs: {$result['summary']['unique_item_ids']}\n";
echo "Augmented: {$result['summary']['augmented_entries']}\n";
echo "Porter slip entries: {$result['summary']['porter_slip_entries']}\n";
if ($result['warnings'] !== []) {
    echo "Warnings: " . count($result['warnings']) . "\n";
    foreach ($result['warnings'] as $warning) echo "  - {$warning}\n";
}
