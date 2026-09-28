#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$args=array_slice($argv,1);$depth=5;$limit=12;$focus='';
foreach($args as $a){
    if(str_starts_with($a,'--depth='))$depth=max(1,min(10,(int)substr($a,8)));
    elseif(str_starts_with($a,'--limit='))$limit=max(1,min(50,(int)substr($a,8)));
    elseif(!str_starts_with($a,'--') && $focus==='')$focus=trim($a);
}
if($focus===''){fwrite(STDERR,"Usage: php bin/import-bgwiki-chain.php \"Kikoku\" [--depth=5] [--limit=12]\n");exit(2);} 
$client=new BgWikiClient();$resolver=new BgWikiItemResolver($pdo,$client);$repo=new BgWikiRepository($pdo);$svc=new ProgressionChainService($repo);
$queue=[$focus];$seen=[];$fetched=0;
while($queue && $fetched<$limit){
    $name=array_shift($queue);$norm=LacProfileParser::normalizeName($name);if($norm===''||isset($seen[$norm]))continue;$seen[$norm]=true;
    $isFocus=$norm===LacProfileParser::normalizeName($focus);
    if(!$repo->byItem($name) || $isFocus){
        try{$p=$resolver->fetch($name);$repo->save($name,$p);$fetched++;printf("[%s] %s · rev %s%s\n",$p['exists']?'OK':'MISS',$p['title'],$p['revision_id']??'—',!empty($p['resolved_from_ambiguous'])?' · resource-resolved':'');}
        catch(Throwable $e){fprintf(STDERR,"[ERROR] %s: %s\n",$name,$e->getMessage());continue;}
    }else printf("[CACHE] %s\n",$name);
    $graph=$svc->build($focus,$depth);
    foreach($graph['nodes'] as $node){
        $n=(string)$node['name'];$nn=LacProfileParser::normalizeName($n);
        if($nn===''||isset($seen[$nn]))continue;
        if(!$repo->byItem($n))$queue[]=$n;
    }
}
$graph=$svc->build($focus,$depth);
printf("Chain cache complete: focus=%s · fetched=%d · nodes=%d · upstream=%d · downstream=%d · actions=%d\n",
    $focus,$fetched,count($graph['nodes']),count($graph['upstream_paths']),count($graph['downstream_paths']),count($graph['actions']));
if($queue)echo "Stopped at safety limit; re-run to continue explicit related pages.\n";
