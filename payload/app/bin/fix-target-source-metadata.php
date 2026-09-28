#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/bootstrap.php';
require_once $root.'/src/TargetSourceMetadataFixer.php';
$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$jobs=[];
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--jobs=')){
        $v=strtoupper(trim(substr($arg,7)));
        $jobs=$v==='ALL'?array_keys(AllJobsTargetParser::jobs()):array_values(array_filter(array_map('trim',explode(',',$v))));
    }
}
$r=TargetSourceMetadataFixer::fix($pdo,$jobs);
echo "===== TARGET SOURCE METADATA =====\n";
echo 'Jobs: '.count($r['jobs'])."\n";
if(!$r['tables']){
    echo "[WARN] Keine passenden Target-Provenance-Spalten mussten aktualisiert werden.\n";
}else{
    foreach($r['tables'] as $table=>$n) echo sprintf("[OK] %-36s %d row(s)\n",$table,$n);
    echo "Updated rows: {$r['rows']}\n";
}
