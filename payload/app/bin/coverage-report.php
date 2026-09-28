#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');$s->execute([':name'=>$config['character']]);$snap=$s->fetch();if(!$snap)throw new RuntimeException('No GearFinder snapshot imported.');
$b=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$b->execute([':cid'=>$snap['character_id']]);$bridge=$b->fetch();
$job='all';$filter='all';foreach(array_slice($argv,1) as $arg){if(str_starts_with($arg,'--job='))$job=substr($arg,6);elseif(str_starts_with($arg,'--coverage='))$filter=substr($arg,11);}
$r=(new CoverageAnalyzer($pdo,(int)$snap['character_id'],(int)$snap['id'],$bridge?(int)$bridge['id']:null))->report($job,$filter);
echo "===== PROGRESSION COVERAGE =====\n";echo "Scope: {$r['scope_job']}\nRelevant items: {$r['total']}\nModeled/actionable: {$r['modeled_percent']}%\nFully modeled: {$r['full_percent']}%\n\n";
foreach($r['counts'] as $k=>$n)printf("%-28s %4d\n",$k,$n);
echo "\nNeeds\n";foreach($r['need_counts'] as $k=>$n)printf("%-28s %4d\n",$k,$n);
if($r['gap_systems']){echo "\nTop missing system adapters/data\n";foreach(array_slice($r['gap_systems'],0,12,true) as $k=>$n)printf("%4d  %s\n",$n,$k);}
echo "\nPriority gaps\n";$shown=0;foreach($r['items'] as $x){if($x['coverage']==='FULL'||$x['coverage']==='ACQUISITION_ONLY')continue;printf("%-34s %-26s %-18s %s\n",(strlen((string)$x['name'])>33?substr((string)$x['name'],0,32).'…':(string)$x['name']),$x['coverage'],$x['need'],$x['coverage_reason']);if(++$shown>=25)break;}if(!$shown)echo "No unresolved/partial items in this scope.\n";
$a=(new LatestTierAudit())->report($r);
echo "\nLatest-tier / post-2024 audit\n";
echo "Knowledge: {$a['knowledge_version']} · verified through {$a['verified_through']}\n";
echo "Tier/system relevant: {$a['tier_relevant']}\nLatest-tier awareness: {$a['latest_awareness_percent']}%\nPost-2024 verified: {$a['post_2024_verified_percent']}%\n";
printf("%-28s %4d\n",'NEWER_TIER_AVAILABLE',$a['counts']['NEWER_TIER_AVAILABLE']??0);
printf("%-28s %4d\n",'CURRENT_VERIFIED',$a['counts']['CURRENT_VERIFIED']??0);
printf("%-28s %4d\n",'REVIEW_REQUIRED',$a['counts']['REVIEW_REQUIRED']??0);
$shown=0;foreach($a['rows'] as $x){if(($x['latest_status']??'')!=='NEWER_TIER_AVAILABLE')continue;if($shown++===0)echo "\nNewer-tier advisories\n";echo "  {$x['name']} -> {$x['latest_tier']}\n";}
if(!$shown)echo "No newer-tier advisories in this scope.\n";
