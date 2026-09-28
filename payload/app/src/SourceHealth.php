<?php
declare(strict_types=1);

final class SourceHealth
{
    public function __construct(private PDO $pdo, private array $config, private string $root, private int $characterId) {}

    public function report(): array
    {
        $now=time();$rows=[];
        try{
            $gf=GearFinderLocator::discover($this->config);
            foreach(['gear','res'] as $key){$path=$gf[$key]??null;$mtime=$path&&is_file($path)?filemtime($path):false;$rows[]=['source'=>'GearFinder '.basename((string)$path),'status'=>$path&&is_file($path)?'OK':'ERROR','detail'=>$path?:'not found','age_hours'=>$mtime?round(($now-$mtime)/3600,1):null];}
        }catch(Throwable $e){$rows[]=['source'=>'GearFinder','status'=>'ERROR','detail'=>$e->getMessage(),'age_hours'=>null];$gf=['server_id'=>null];}
        try{
            $lac=LacLocator::discover($this->config,$gf['server_id']??null);
            foreach(($lac['profiles']??[]) as $job=>$path){if(!in_array($job,['NIN','THF'],true))continue;$rows[]=$this->fileHealth('LAC '.$job,$path,$now);}
            if(!empty($lac['gcinclude']))$rows[]=$this->fileHealth('LAC gcinclude',$lac['gcinclude'],$now);
        }catch(Throwable $e){$rows[]=['source'=>'LuAshitacast','status'=>'ERROR','detail'=>$e->getMessage(),'age_hours'=>null];}
        $s=$this->pdo->prepare('SELECT * FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$s->execute([':cid'=>$this->characterId]);$b=$s->fetch();$rows[]=['source'=>'Live augment bridge','status'=>$b?'OK':'PENDING','detail'=>$b?($b['item_count'].' items · '.$b['captured_at']):'No live snapshot yet; deferred until consolidated FFXI test','age_hours'=>null];
        $cnt=(int)$this->pdo->query('SELECT COUNT(*) FROM bgwiki_pages WHERE exists_flag=1')->fetchColumn();$last=$this->pdo->query('SELECT MAX(fetched_at) FROM bgwiki_pages')->fetchColumn();$rows[]=['source'=>'BG-Wiki cache','status'=>$cnt?'OK':'PENDING','detail'=>$cnt?$cnt.' cached pages · latest '.$last:'No pages cached yet','age_hours'=>null];
        $target=(new TargetRepository($this->pdo))->summary();
        $strict=(int)($target['statuses']['strict_status']['VERIFIED']??0);
        $anomalies=0;foreach(($target['statuses']['source_anomaly_status']??[]) as $state=>$count)if(!in_array((string)$state,['NONE','PENDING'],true))$anomalies+=(int)$count;
        $rows[]=['source'=>'Global target catalog','status'=>(int)$target['jobs']===22?'OK':'PENDING','detail'=>$target['jobs'].'/22 jobs · '.$target['sets'].' sets · '.$target['targets'].' target rows · '.$strict.' strict verified · '.$anomalies.' source anomalies','age_hours'=>null];
        $resourceHealth=(new ResourceInventoryRepository($this->pdo,$this->characterId))->status();$r=$resourceHealth['snapshot'];$rows[]=['source'=>'Unified resource snapshot','status'=>$resourceHealth['status'],'detail'=>$r?($r['entry_count'].' entries · '.$resourceHealth['message']):$resourceHealth['message'],'age_hours'=>null];
        $rows[]=['source'=>'Progression knowledge registry','status'=>'OK','detail'=>'Post-2024 audit knowledge '.SystemFreshnessRegistry::KNOWLEDGE_VERSION.' · verified through '.SystemFreshnessRegistry::VERIFIED_THROUGH,'age_hours'=>null];
        return $rows;
    }
    private function fileHealth(string $source,string $path,int $now):array{$m=is_file($path)?filemtime($path):false;return ['source'=>$source,'status'=>is_file($path)?'OK':'ERROR','detail'=>$path,'age_hours'=>$m?round(($now-$m)/3600,1):null];}
}
