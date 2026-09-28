#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$root=dirname(__DIR__);
$config=Config::load($root);
$pdo=Database::connect($config['db_path']);
Database::migrate($pdo,$root.'/sql/schema.sql');
$gf=GearFinderLocator::discover($config);
$c=$pdo->prepare('SELECT id FROM characters WHERE lower(name)=lower(:name) AND (server_id=:sid OR (:sid2 IS NULL AND server_id IS NULL)) LIMIT 1');
$c->execute([':name'=>$gf['character'],':sid'=>$gf['server_id'],':sid2'=>$gf['server_id']]);
$characterId=(int)($c->fetchColumn()?:0);
if(!$characterId)throw new RuntimeException('Character not found in DB. Import GearFinder first.');

$loc=AugmentBridgeLocator::discover($config,$gf['server_id']);
if(!is_file($loc['path'])){
    fwrite(STDERR,"No live snapshot yet: {$loc['path']}\nStart/reload FFXI after running ./bin/install-augment-bridge.sh, then wait a few seconds.\n");
    exit(2);
}

$augment=(new AugmentBridgeImporter($pdo))->import($loc['path'],$characterId);
echo (!empty($augment['unchanged'])?'Augment snapshot unchanged; using #':'Imported augment snapshot #').$augment['snapshot_id']."\n";
$raw=file_get_contents($loc['path']);
$payload=$raw===false?null:json_decode($raw,true);
if(!is_array($payload)||($payload['schema']??'')!=='ffxi-gear-center-live-snapshot-v2'){
    echo "Legacy v1 bridge payload: augment data imported; resource inventory requires the v2 bridge.\n";
    echo "Captured: {$augment['captured_at']}\nInventory instances: {$augment['items']}\n";
    exit(0);
}
$resources=(new ResourceSnapshotImporter($pdo))->import($loc['path'],$characterId);
echo (!empty($resources['unchanged'])?'Resource snapshot unchanged; using #':'Imported resource snapshot #').$resources['snapshot_id']."\n";
echo "Captured: {$resources['captured_at']}\nInventory instances: {$augment['items']}\nResource entries: {$resources['entries']}\n";
$status=is_array($resources['collection_status'])?$resources['collection_status']:[];
if($status!==[]){
    echo 'Collectors: inventory='.($status['inventory']??'UNKNOWN')
        .' · key_items='.($status['key_items']??'UNKNOWN')
        .' · currencies='.($status['currencies']??'UNKNOWN')."\n";
}
