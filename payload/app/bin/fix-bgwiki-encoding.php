#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$root=dirname(__DIR__);
$config=Config::load($root);
$dbPath=(string)($argv[1]??$config['db_path']);
if(!is_file($dbPath)){
    fwrite(STDERR,"BG-Wiki encoding migration: DB not found: {$dbPath}\n");
    exit(2);
}
$pdo=Database::connect($dbPath);
Database::migrate($pdo,$root.'/sql/schema.sql');
$r=(new BgWikiEncodingMigrator($pdo))->run();
echo "BG-Wiki encoding migration: {$r['rows']} rows / {$r['strings']} strings repaired; remaining={$r['remaining']}\n";
