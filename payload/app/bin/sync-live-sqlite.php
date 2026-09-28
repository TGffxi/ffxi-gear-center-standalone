#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$root=dirname(__DIR__);
$config=Config::load($root);
$pdo=Database::connect($config['db_path']);
Database::migrate($pdo,$root.'/sql/schema.sql');

$q=$pdo->prepare('SELECT id,server_id FROM characters WHERE lower(name)=lower(:name) ORDER BY id DESC LIMIT 1');
$q->execute([':name'=>$config['character']]);
$character=$q->fetch()?:null;
if(!$character){
    fwrite(STDERR,"Live SQLite sync: character not present in DB yet; preserving existing state.\n");
    exit(0);
}
$result=(new LiveStateSynchronizer($pdo,$config))->sync(
    (int)$character['id'],
    $character['server_id']!==null?(int)$character['server_id']:null,
);
echo 'Live SQLite sync: '.($result['status']??'UNKNOWN').' · '.($result['message']??'')."\n";
if(!empty($result['captured_at']))echo 'Captured: '.$result['captured_at'].' · items='.(int)($result['items']??0)."\n";
if(!empty($result['resource_error']))echo 'Resource preservation: '.$result['resource_error']."\n";
