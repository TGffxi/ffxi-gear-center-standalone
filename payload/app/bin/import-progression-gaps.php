#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');
$s->execute([':name'=>$config['character']]);$snap=$s->fetch();if(!$snap)throw new RuntimeException('No GearFinder snapshot imported.');
$b=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$b->execute([':cid'=>$snap['character_id']]);$bridge=$b->fetch();
$job='all';$limit=25;$includePartial=true;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--job='))$job=substr($arg,6);
    elseif(str_starts_with($arg,'--limit='))$limit=max(1,min(200,(int)substr($arg,8)));
    elseif($arg==='--unresolved-only')$includePartial=false;
}
$an=new CoverageAnalyzer($pdo,(int)$snap['character_id'],(int)$snap['id'],$bridge?(int)$bridge['id']:null);
$report=$an->report($job,'all');$names=[];
foreach($report['items'] as $row){
    if(!empty($row['bgwiki_cached']))continue;
    if(($row['need']??'')==='NO_ACTION')continue;
    if(!$includePartial && ($row['coverage']??'')!=='UNRESOLVED')continue;
    if(in_array(($row['coverage']??''),['UNRESOLVED','PARTIAL','SYSTEM_KNOWN_DATA_MISSING'],true))$names[]=(string)$row['name'];
    if(count($names)>=$limit)break;
}
if(!$names){echo "No uncached actionable progression gaps found for scope ".strtoupper($job).".\n";exit(0);}
$client=new BgWikiClient();$resolver=new BgWikiItemResolver($pdo,$client);$repo=new BgWikiRepository($pdo);$ok=0;
echo "===== PROGRESSION GAP PREFETCH =====\n";
echo "Scope: ".strtoupper($job)." · selected: ".count($names)."\n\n";
foreach($names as $name){
    try{
        $page=$resolver->fetch($name);$repo->save($name,$page);
        $records=BgWikiExtractor::progressionRecords((string)$page['content'],(string)($page['content_format']??'html'));
        if(($page['content_format']??'html')==='html')$records=array_merge($records,BgWikiExtractor::versionChainRecords((string)$page['content'],(string)$page['title'],'html'));
        printf("[%s] %-32s -> %-36s · %d structured row%s\n",$page['exists']?'OK':'MISS',$name,(string)$page['title'],count($records),count($records)===1?'':'s');
        $ok++;
    }catch(Throwable $e){fprintf(STDERR,"[ERROR] %s: %s\n",$name,$e->getMessage());}
}
echo "\nCached: $ok/".count($names)."\n";
echo "Re-run php bin/coverage-report.php --job=".strtoupper($job)." to measure the new adapter/data coverage.\n";
