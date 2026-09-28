#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$jobs=array_keys(AllJobsTargetParser::jobs());$prefetch=true;$verify=true;$maxDepth=4;$maxFetch=500;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--jobs=')){$v=strtoupper(trim(substr($arg,7)));$jobs=$v==='ALL'?array_keys(AllJobsTargetParser::jobs()):array_values(array_filter(array_map('trim',explode(',',$v))));}
    elseif($arg==='--no-prefetch')$prefetch=false;elseif($arg==='--no-verify')$verify=false;
    elseif(str_starts_with($arg,'--max-depth='))$maxDepth=max(1,(int)substr($arg,12));elseif(str_starts_with($arg,'--max-fetch='))$maxFetch=max(1,(int)substr($arg,12));
}
foreach($jobs as $job)if(!isset(AllJobsTargetParser::jobs()[$job]))throw new InvalidArgumentException('Unknown job: '.$job);
echo "===== GLOBAL TARGET SYNC =====\nJobs: ".implode(', ',$jobs)."\n\n";
$import=(new AllJobsTargetImporter($pdo))->importJobs($jobs);
// GEARCENTER_BG_JOB_GUIDES_SOURCE_LABEL_V2
foreach($import['ok'] as $job=>$r){$displayPage=((int)$r['sets']>0)?('bg_job_guides/'.strtolower($job).'.md'):$r['page'];echo sprintf("[OK] %-3s %3d sets · %4d targets · %s\n",$job,$r['sets'],$r['targets'],$displayPage);}
foreach($import['errors'] as $job=>$err)echo "[ERR] $job $err\n";
echo "Imported: {$import['sets']} sets · {$import['targets']} target rows\n";

// Empty parsing must never count as a successful acceptance result.
// 22 fetched job pages with zero recognized sets means the parser/source
// contract is broken, not that synchronization succeeded.
if(count($jobs)>0 && (int)$import['sets']===0){
    fwrite(STDERR,
        "[ERR] Target parser produced zero sets for ".
        count($jobs)." requested job page(s).\n"
    );
    exit(2);
}

$repo=new TargetRepository($pdo);$items=[];foreach($jobs as $job)foreach($repo->latestItems($job) as $r){$name=trim((string)($r['source_link_title']?:$r['source_item_name']));$items[LacProfileParser::normalizeName($name)]=$name;}
if($prefetch){echo "\n===== TARGET ITEM PREFETCH =====\n";$p=(new TargetPrefetchService($pdo))->prefetch(array_values($items),$maxDepth,$maxFetch);echo 'Unique requested: '.count($items)."\nFetched: ".count($p['fetched'])." · already cached: ".count($p['already_cached'])." · missing: ".count($p['missing'])." · errors: ".count($p['errors'])."\n";if($p['limit_reached'])echo "[WARN] Prefetch limit reached; rerun to resume from cache.\n";foreach(array_slice($p['missing'],0,20,true) as $name=>$err)echo "[MISS] $name · $err\n";foreach(array_slice($p['errors'],0,20,true) as $name=>$err)echo "[ERR] $name · $err\n";}
if($verify){echo "\n===== STRICT TARGET VERIFICATION =====\n";$v=new TargetVerificationService($pdo);$n=0;$strict=0;foreach($jobs as $job){foreach($repo->latestItems($job) as $r){$res=$v->verify($r);$repo->saveVerification((int)$r['id'],$res);$n++;if(($res['strict_status']??'')==='VERIFIED')$strict++;}}echo "Verified rows: $n · strict: $strict · review: ".($n-$strict)."\n";}
echo "\nRun: php bin/global-target-report.php\n";
