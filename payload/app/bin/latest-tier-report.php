#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');$s->execute([':name'=>$config['character']]);$snap=$s->fetch();if(!$snap)throw new RuntimeException('No GearFinder snapshot imported.');
$b=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$b->execute([':cid'=>$snap['character_id']]);$bridge=$b->fetch();
$job='ALL';foreach(array_slice($argv,1) as $arg)if(str_starts_with($arg,'--job='))$job=strtoupper(substr($arg,6));
$cov=(new CoverageAnalyzer($pdo,(int)$snap['character_id'],(int)$snap['id'],$bridge?(int)$bridge['id']:null))->report($job,'all');
$r=(new LatestTierAudit())->report($cov);
echo "===== LATEST-TIER / POST-2024 AUDIT =====\n";
echo "Scope: {$cov['scope_job']}\nKnowledge version: {$r['knowledge_version']} · verified through {$r['verified_through']}\n";
echo "Relevant items: {$r['total']}\nTier/system relevant: {$r['tier_relevant']}\nLatest-tier awareness: {$r['latest_awareness_percent']}%\nPost-2024 verified: {$r['post_2024_verified_percent']}%\n\n";
foreach($r['counts'] as $k=>$n)printf("%-24s %4d\n",$k,$n);
$shown=0;echo "\nNewer tiers available\n";foreach($r['rows'] as $x){if($x['latest_status']!=='NEWER_TIER_AVAILABLE')continue;printf("%-34s -> %s\n",$x['name'],$x['latest_tier']);if(++$shown>=30)break;}if(!$shown)echo "None in this scope.\n";
$shown=0;echo "\nFreshness review required\n";foreach($r['rows'] as $x){if($x['latest_status']!=='REVIEW_REQUIRED')continue;printf("%-34s %s\n",$x['name'],$x['latest_reason']);if(++$shown>=30)break;}if(!$shown)echo "None.\n";
echo "\nPost-2024 update timeline\n";foreach($r['timeline'] as $u)echo "  {$u['date']} · {$u['system']} · {$u['label']}\n";
