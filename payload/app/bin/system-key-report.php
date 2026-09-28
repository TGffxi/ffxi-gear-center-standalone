#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$job='all';$names=[];
foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--job='))$job=substr($arg,6);elseif($arg!=='')$names[]=$arg;}
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');$s->execute([':name'=>$config['character']]);$snap=$s->fetch();if(!$snap)throw new RuntimeException('No GearFinder snapshot imported.');
$b=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$b->execute([':cid'=>$snap['character_id']]);$bridge=$b->fetch();
$r=(new CoverageAnalyzer($pdo,(int)$snap['character_id'],(int)$snap['id'],$bridge?(int)$bridge['id']:null))->report($job,'all');
$filter=array_map([LacProfileParser::class,'normalizeName'],$names);
foreach($r['items'] as $item){
    if($filter && !in_array(LacProfileParser::normalizeName((string)$item['name']),$filter,true))continue;
    echo "===== {$item['name']} =====\n";
    echo 'item_id: '.var_export($item['item_id']??null,true)." · superior_level: ".var_export($item['superior_level']??null,true)."\n";
    echo 'known_system_keys: '.json_encode($item['known_system_keys']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    echo 'known_systems: '.json_encode($item['known_systems']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    echo 'target_augments: '.json_encode($item['target_augments']??[],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    $a=LatestTierAudit::classifyItem($item);
    echo 'latest_status: '.($a['latest_status']??'?')."\n";
    if(!empty($a['unmatched_systems']))echo 'unmatched_systems: '.json_encode($a['unmatched_systems'],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n";
    echo "\n";
}
