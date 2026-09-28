#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root = dirname(__DIR__);
$config = Config::load($root);
$options = getopt('', ['dir::']);
$source = GearFinderLocator::discover($config, $options['dir'] ?? null);
$gear = GearFinderNormalizer::readJsonArray($source['gear']);
$res = GearFinderNormalizer::readJsonArray($source['res']);
$map = GearFinderNormalizer::resourceMap($res);
$warnings = GearFinderNormalizer::validateGear($gear, $map);
$summary = GearFinderNormalizer::summary($gear, $map);

echo "GearFinder source: {$source['dir']}\n";
echo "Character: {$source['character']}\n";
echo "Server ID: " . ($source['server_id'] ?? 'unknown') . "\n\n";
foreach ($summary as $key => $value) {
    if ($key === 'bag_counts') continue;
    echo str_pad($key, 24) . ': ' . $value . "\n";
}
echo "\nBags:\n";
foreach ($summary['bag_counts'] as $id => $count) {
    echo sprintf("  %2d %-14s %3d\n", $id, BagMap::name((int)$id), $count);
}
if ($warnings !== []) {
    echo "\nWarnings:\n";
    foreach ($warnings as $warning) echo "  - {$warning}\n";
}
