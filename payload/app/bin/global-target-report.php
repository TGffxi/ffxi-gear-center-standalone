#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$jsonPath=null;foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--json='))$jsonPath=substr($arg,7);
$r=(new TargetCoverageReport($pdo))->report();$s=$r['summary'];
echo "===== GLOBAL TARGET / VERIFIED PROGRESSION =====\n";
echo 'Version: '.trim((string)file_get_contents($root.'/VERSION'))."\n";
echo "Jobs cached: {$s['jobs']}/22 · Sets: {$s['sets']} · Target rows: {$s['targets']} · Unique target items: {$s['unique_items']}\n\n";
printf("%-4s %5s %7s %7s %9s %8s %8s %10s %8s %7s\n",'JOB','SETS','TARGETS','STRICT','IDENTITY','ROUTE','AUGMENT','FRESHNESS','CHAIN','REVIEW');
foreach($r['jobs'] as $j)printf("%-4s %5d %7d %6.1f%% %8.1f%% %7.1f%% %7.1f%% %9.1f%% %7.1f%% %7d\n",$j['job'],$j['sets'],$j['targets'],$j['strict_percent'],$j['identity_percent'],$j['route_percent'],$j['augment_percent'],$j['freshness_percent'],$j['chain_percent'],$j['review']);
echo "\nContent / system coverage\n";foreach(array_slice($r['systems'],0,40,true) as $key=>$x)printf("%-42s targets=%4d verified=%4d review=%4d\n",$key,$x['targets'],$x['verified'],$x['review']);
foreach(['identity','source_anomaly','route','augment','freshness','chain','parser'] as $queue){echo "\n".strtoupper($queue)." REVIEW QUEUE\n";$rows=$r['queues'][$queue]??[];if(!$rows){echo "None.\n";continue;}foreach(array_slice($rows,0,25) as $x){if($queue==='parser'){echo "{$x['job']} · {$x['page_title']} · {$x['warnings_json']}\n";continue;}echo "{$x['job']} · {$x['source_item_name']}".(!empty($x['source_link_title'])?' -> '.$x['source_link_title']:'')." · {$x['set_name']} / {$x['variant_name']} · ".($x['review_reason']?:($x['source_anomaly_status']??'review required'))."\n";}}
if($jsonPath!==null){file_put_contents($jsonPath,json_encode($r,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));echo "\nJSON: $jsonPath\n";}
