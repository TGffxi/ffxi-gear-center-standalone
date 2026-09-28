#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root=dirname(__DIR__); $config=Config::load($root);
$pdo=Database::connect($config['db_path']); Database::migrate($pdo,$root.'/sql/schema.sql');
$gf=GearFinderLocator::discover($config);
$c=$pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:name) AND (server_id=:sid OR (:sid2 IS NULL AND server_id IS NULL)) LIMIT 1');
$c->execute([':name'=>$gf['character'],':sid'=>$gf['server_id'],':sid2'=>$gf['server_id']]);
$characterId=(int)($c->fetchColumn() ?: 0);
if(!$characterId) throw new RuntimeException('Character not found in DB. Run GearFinder import first.');
$lac=LacLocator::discover($config,$gf['server_id']);
$result=(new LacImporter($pdo))->import($lac,$characterId);
$s=$result['summary'];
echo !empty($result['skipped']) ? "LAC sources unchanged; keeping latest profile snapshots.\n" : "Imported LAC run #{$result['run_id']}\n";
echo "Profiles: {$s['profiles']}\nSets: {$s['sets']}\nRequirements: {$s['requirements']}\nSelectable/options: {$s['options']}\nSupplies: {$s['supplies']}\n";
