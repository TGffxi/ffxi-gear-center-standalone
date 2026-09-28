<?php
declare(strict_types=1);

final class TargetCoverageReport
{
    public function __construct(private PDO $pdo){}

    /** @return array<string,mixed> */
    public function report(): array
    {
        $repo=new TargetRepository($this->pdo);$summary=$repo->summary();$jobs=[];
        foreach(array_keys(AllJobsTargetParser::jobs()) as $job){
            $rows=$repo->latestItems($job);if(!$rows){$jobs[$job]=['job'=>$job,'sets'=>0,'targets'=>0,'unique_items'=>0,'strict_percent'=>0.0,'identity_percent'=>0.0,'route_percent'=>0.0,'augment_percent'=>0.0,'freshness_percent'=>0.0,'chain_percent'=>0.0,'source_anomalies'=>0,'review'=>0];continue;}
            $sets=[];$unique=[];$counts=['identity'=>0,'route'=>0,'augment'=>0,'freshness'=>0,'chain'=>0,'strict'=>0];$review=0;$sourceAnomalies=0;
            foreach($rows as $r){$sets[(string)$r['set_key']]=true;$unique[(string)($r['canonical_item_norm']?:$r['item_name_norm'])]=true;
                if(($r['identity_status']??'')==='VERIFIED')$counts['identity']++;
                if(($r['route_status']??'')==='VERIFIED')$counts['route']++;
                if(in_array((string)($r['augment_status']??''),['VERIFIED','NOT_APPLICABLE'],true))$counts['augment']++;
                if(in_array((string)($r['freshness_status']??''),['CURRENT_VERIFIED','NEWER_TIER_AVAILABLE','NOT_APPLICABLE'],true))$counts['freshness']++;
                if(in_array((string)($r['chain_status']??''),['VERIFIED','NOT_APPLICABLE'],true))$counts['chain']++;
                if(!in_array((string)($r['source_anomaly_status']??'PENDING'),['NONE','PENDING'],true))$sourceAnomalies++;
                if(($r['strict_status']??'')==='VERIFIED')$counts['strict']++;else$review++;
            }
            $n=count($rows);$pct=static fn(int $x)=>$n?round($x*100/$n,1):0.0;
            $jobs[$job]=['job'=>$job,'sets'=>count($sets),'targets'=>$n,'unique_items'=>count($unique),'strict_percent'=>$pct($counts['strict']),'identity_percent'=>$pct($counts['identity']),'route_percent'=>$pct($counts['route']),'augment_percent'=>$pct($counts['augment']),'freshness_percent'=>$pct($counts['freshness']),'chain_percent'=>$pct($counts['chain']),'source_anomalies'=>$sourceAnomalies,'review'=>$review];
        }
        $systems=[];foreach($repo->latestItems() as $r){$keys=json_decode((string)($r['system_keys_json']??'[]'),true);if(!is_array($keys)||!$keys)$keys=['unclassified_or_nonprogression'];foreach($keys as $k){$k=(string)$k;if(!isset($systems[$k]))$systems[$k]=['targets'=>0,'verified'=>0,'review'=>0];$systems[$k]['targets']++;if(($r['strict_status']??'')==='VERIFIED')$systems[$k]['verified']++;else$systems[$k]['review']++;}}
        uasort($systems,static fn($a,$b)=>($b['review']<=>$a['review'])?:($b['targets']<=>$a['targets']));
        return ['summary'=>$summary,'jobs'=>$jobs,'systems'=>$systems,'queues'=>$this->queues()];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function queues(): array
    {
        $defs=[
            'identity'=>"identity_status!='VERIFIED'",
            'route'=>"route_status!='VERIFIED'",
            'augment'=>"augment_status NOT IN ('VERIFIED','NOT_APPLICABLE')",
            'freshness'=>"freshness_status NOT IN ('CURRENT_VERIFIED','NEWER_TIER_AVAILABLE','NOT_APPLICABLE')",
            'chain'=>"chain_status NOT IN ('VERIFIED','NOT_APPLICABLE')",
            'source_anomaly'=>"source_anomaly_status NOT IN ('NONE','PENDING')",
            'strict'=>"strict_status!='VERIFIED'",
        ];$out=[];
        foreach($defs as $name=>$where){$q=$this->pdo->query("SELECT id,job,set_name,variant_name,source_item_name,source_link_title,canonical_item_name,identity_status,identity_match_kind,source_anomaly_status,route_status,augment_status,freshness_status,chain_status,strict_status,review_reason FROM v_latest_target_items WHERE $where ORDER BY job,source_item_name LIMIT 100");$out[$name]=$q->fetchAll();}
        $q=$this->pdo->query("SELECT job,page_title,warnings_json,error_message FROM v_latest_target_job_snapshots WHERE warnings_json!='[]' OR error_message IS NOT NULL ORDER BY job");$out['parser']=$q->fetchAll();
        return $out;
    }
}
