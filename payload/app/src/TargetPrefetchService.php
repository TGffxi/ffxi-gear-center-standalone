<?php
declare(strict_types=1);

/** Fetches target item pages and only follows explicit item-to-item progression edges. */
final class TargetPrefetchService
{
    public function __construct(private PDO $pdo){}

    /** @return array<string,mixed> */
    public function prefetch(array $itemNames,int $maxDepth=4,int $maxFetch=400): array
    {
        $repo=new BgWikiRepository($this->pdo);$client=new BgWikiClient();$resolver=new BgWikiItemResolver($this->pdo,$client);$chains=new ProgressionChainService($repo);
        $queue=[];foreach($itemNames as $n){$n=trim((string)$n);if($n!=='')$queue[]=[$n,0];}
        $seen=[];$fetched=[];$cached=[];$missing=[];$errors=[];$linked=[];
        while($queue && count($fetched)<$maxFetch){
            [$name,$depth]=array_shift($queue);$norm=LacProfileParser::normalizeName($name);
            if($norm===''||isset($seen[$norm]))continue;$seen[$norm]=true;
            try{
                $page=$repo->byItem($name);
                if(!$page){
                    $remote=$resolver->fetch($name);
                    if(empty($remote['exists'])){$missing[$name]='BG-Wiki page not found';continue;}
                    $repo->save($name,$remote);$page=$repo->byItem($name);$fetched[]=$name;
                }else{$cached[]=$name;}
                if(!$page||$depth>=$maxDepth)continue;
                $records=$repo->progressionRecordsByItem($name);
                foreach($chains->edges($records) as $edge){
                    if(!in_array((string)($edge['kind']??''),['upgrade','craft'],true))continue;
                    foreach(['from','to'] as $side){
                        $next=trim((string)($edge[$side]??''));$nextNorm=LacProfileParser::normalizeName($next);
                        if($next===''||$nextNorm===$norm||isset($seen[$nextNorm]))continue;
                        $linked[$name][]=$next;$queue[]=[$next,$depth+1];
                    }
                }
            }catch(Throwable $e){$errors[$name]=$e->getMessage();}
        }
        return [
            'requested'=>count($itemNames),'visited'=>count($seen),'fetched'=>array_values(array_unique($fetched)),
            'already_cached'=>array_values(array_unique($cached)),'missing'=>$missing,'errors'=>$errors,'linked'=>$linked,
            'limit_reached'=>!empty($queue),'max_depth'=>$maxDepth,'max_fetch'=>$maxFetch,
        ];
    }
}
