<?php
declare(strict_types=1);

final class TargetRepository
{
    public const SOURCE_KEY='bgwiki_all_jobs_gear_sets';
    public function __construct(private PDO $pdo){}

    public function ensureAllJobsSource(): int
    {
        $now=gmdate('c');
        $s=$this->pdo->prepare("INSERT INTO target_sources(source_key,label,source_kind,root_title,root_url,scope,enabled,notes,created_at,updated_at)
            VALUES(:k,:l,:kind,:title,:url,'global',1,:notes,:now,:now)
            ON CONFLICT(source_key) DO UPDATE SET label=excluded.label,source_kind=excluded.source_kind,root_title=excluded.root_title,root_url=excluded.root_url,updated_at=excluded.updated_at");
        $s->execute([
            ':k'=>self::SOURCE_KEY,':l'=>'BG-Wiki All Jobs Gear Sets',':kind'=>'optimized_combat_targets',
            ':title'=>'All Jobs Gear Sets',':url'=>'https://www.bg-wiki.com/ffxi/All_Jobs_Gear_Sets',
            ':notes'=>'Automatically calculated combat targets. Not treated as a complete utility/job-guide truth.',':now'=>$now,
        ]);
        $q=$this->pdo->prepare('SELECT id FROM target_sources WHERE source_key=:k');$q->execute([':k'=>self::SOURCE_KEY]);
        return (int)$q->fetchColumn();
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $parsed */
    public function saveJob(int $sourceId,array $page,array $parsed): int
    {
        $this->pdo->beginTransaction();
        try{
            $now=gmdate('c');$setCount=count($parsed['sets']??[]);$targetCount=0;
            foreach(($parsed['sets']??[]) as $set)$targetCount+=count($set['items']??[]);
            $s=$this->pdo->prepare("INSERT INTO target_job_snapshots(source_id,job,page_title,canonical_url,revision_id,revision_timestamp,fetched_at,imported_at,status,set_count,target_count,assumptions_json,warnings_json,error_message)
                VALUES(:sid,:job,:title,:url,:rid,:rts,:fetched,:now,'OK',:sets,:targets,:assumptions,:warnings,NULL)");
            $s->execute([
                ':sid'=>$sourceId,':job'=>$parsed['job'],':title'=>$page['title']??$parsed['page_title'],':url'=>$page['canonical_url']??'',
                ':rid'=>$page['revision_id']??null,':rts'=>$page['revision_timestamp']??null,':fetched'=>$now,':now'=>$now,
                ':sets'=>$setCount,':targets'=>$targetCount,
                ':assumptions'=>json_encode($parsed['assumptions']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                ':warnings'=>json_encode($parsed['warnings']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
            ]);
            $snapshotId=(int)$this->pdo->lastInsertId();
            $si=$this->pdo->prepare('INSERT INTO target_sets(snapshot_id,set_key,set_name,variant_name,source_order,notes) VALUES(:snap,:key,:name,:variant,:ord,NULL)');
            $ii=$this->pdo->prepare('INSERT INTO target_items(set_id,position,slot_name,source_item_name,item_name_norm,details_json) VALUES(:set,:pos,:slot,:name,:norm,:details)');
            $ei=$this->pdo->prepare("INSERT INTO target_item_identity_evidence(target_item_id,source_link_title,source_link_url,identity_match_kind,source_anomaly_status,evidence_json,updated_at)
                VALUES(:id,:title,:url,'PENDING','PENDING','{}',:now)");
            foreach(($parsed['sets']??[]) as $set){
                $key=substr(hash('sha256',strtoupper((string)$parsed['job']).'|'.LacProfileParser::normalizeName((string)$set['set_name']).'|'.strtolower((string)$set['variant_name'])),0,24);
                $si->execute([':snap'=>$snapshotId,':key'=>$key,':name'=>$set['set_name'],':variant'=>$set['variant_name'],':ord'=>$set['source_order']??0]);
                $setId=(int)$this->pdo->lastInsertId();
                foreach(($set['items']??[]) as $item){
                    $ii->execute([
                        ':set'=>$setId,':pos'=>$item['position']??0,':slot'=>$item['slot_name']??null,':name'=>$item['source_item_name'],
                        ':norm'=>$item['item_name_norm'],':details'=>json_encode($item['details']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                    ]);
                    $targetItemId=(int)$this->pdo->lastInsertId();
                    $linkTitle=trim((string)($item['source_link_title']??''));$linkUrl=trim((string)($item['source_link_url']??''));
                    $ei->execute([':id'=>$targetItemId,':title'=>$linkTitle!==''?$linkTitle:null,':url'=>$linkUrl!==''?$linkUrl:null,':now'=>$now]);
                }
            }
            $this->pdo->commit();return $snapshotId;
        }catch(Throwable $e){$this->pdo->rollBack();throw $e;}
    }

    /** @return array<int,array<string,mixed>> */
    public function latestItems(?string $job=null): array
    {
        if($job!==null){$s=$this->pdo->prepare('SELECT * FROM v_latest_target_items WHERE job=:job ORDER BY set_name,variant_name,position');$s->execute([':job'=>strtoupper($job)]);return $s->fetchAll();}
        return $this->pdo->query('SELECT * FROM v_latest_target_items ORDER BY job,set_name,variant_name,position')->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function browse(string $job='ALL',string $strict='ALL',string $query='',int $limit=2000): array
    {
        $where=[];$params=[];$job=strtoupper(trim($job));$strict=strtoupper(trim($strict));$query=trim($query);
        if($job!==''&&$job!=='ALL'){$where[]='job=:job';$params[':job']=$job;}
        if($strict==='VERIFIED')$where[]="strict_status='VERIFIED'";
        elseif($strict==='REVIEW')$where[]="strict_status<>'VERIFIED'";
        elseif($strict!==''&&$strict!=='ALL'){$where[]='strict_status=:strict';$params[':strict']=$strict;}
        if($query!==''){
            $where[]='(source_item_name LIKE :query OR source_link_title LIKE :query OR canonical_item_name LIKE :query OR set_name LIKE :query OR variant_name LIKE :query OR job LIKE :query)';
            $params[':query']='%'.$query.'%';
        }
        $limit=max(1,min($limit,5000));
        $sql='SELECT * FROM v_latest_target_items'.($where?' WHERE '.implode(' AND ',$where):'')
            .' ORDER BY job,source_order,set_name,variant_name,position LIMIT '.$limit;
        $s=$this->pdo->prepare($sql);$s->execute($params);return $s->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function usages(string $itemName): array
    {
        $s=$this->pdo->prepare('SELECT * FROM v_latest_target_items WHERE item_name_norm=:norm OR canonical_item_norm=:norm ORDER BY job,set_name,variant_name,position');
        $s->execute([':norm'=>LacProfileParser::normalizeName($itemName)]);return $s->fetchAll();
    }

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $jobs=(int)$this->pdo->query('SELECT COUNT(*) FROM v_latest_target_job_snapshots')->fetchColumn();
        $sets=(int)$this->pdo->query('SELECT COUNT(*) FROM target_sets ts JOIN v_latest_target_job_snapshots js ON js.id=ts.snapshot_id')->fetchColumn();
        $targets=(int)$this->pdo->query('SELECT COUNT(*) FROM v_latest_target_items')->fetchColumn();
        $unique=(int)$this->pdo->query('SELECT COUNT(DISTINCT COALESCE(NULLIF(canonical_item_norm,\'\'),item_name_norm)) FROM v_latest_target_items')->fetchColumn();
        $statuses=[];
        foreach(['identity_status','route_status','augment_status','freshness_status','chain_status','strict_status','identity_match_kind','source_anomaly_status'] as $col){
            $statuses[$col]=[];
            foreach($this->pdo->query("SELECT $col AS k,COUNT(*) AS n FROM v_latest_target_items GROUP BY $col")->fetchAll() as $r)$statuses[$col][(string)$r['k']]=(int)$r['n'];
        }
        return ['jobs'=>$jobs,'sets'=>$sets,'targets'=>$targets,'unique_items'=>$unique,'statuses'=>$statuses];
    }

    /** @param array<string,mixed> $verification */
    public function saveVerification(int $targetItemId,array $verification): void
    {
        $s=$this->pdo->prepare("UPDATE target_items SET canonical_item_name=:cname,canonical_item_norm=:cnorm,canonical_item_id=:cid,canonical_page_title=:page,
            identity_status=:identity,route_status=:route,augment_status=:augment,freshness_status=:freshness,chain_status=:chain,strict_status=:strict,
            system_keys_json=:systems,review_reason=:reason,verification_json=:json,verified_at=:now WHERE id=:id");
        $s->execute([
            ':cname'=>$verification['canonical_item_name']??null,':cnorm'=>$verification['canonical_item_norm']??null,':cid'=>$verification['canonical_item_id']??null,
            ':page'=>$verification['canonical_page_title']??null,':identity'=>$verification['identity_status']??'REVIEW_REQUIRED',':route'=>$verification['route_status']??'REVIEW_REQUIRED',
            ':augment'=>$verification['augment_status']??'NOT_APPLICABLE',':freshness'=>$verification['freshness_status']??'REVIEW_REQUIRED',':chain'=>$verification['chain_status']??'REVIEW_REQUIRED',':strict'=>$verification['strict_status']??'REVIEW_REQUIRED',
            ':systems'=>json_encode($verification['system_keys']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':reason'=>$verification['review_reason']??null,
            ':json'=>json_encode($verification,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>gmdate('c'),':id'=>$targetItemId,
        ]);
        $e=$this->pdo->prepare("INSERT INTO target_item_identity_evidence(target_item_id,source_link_title,source_link_url,identity_match_kind,source_anomaly_status,evidence_json,updated_at)
            VALUES(:id,:title,:url,:match,:anomaly,:json,:now)
            ON CONFLICT(target_item_id) DO UPDATE SET
              source_link_title=COALESCE(excluded.source_link_title,target_item_identity_evidence.source_link_title),
              source_link_url=COALESCE(excluded.source_link_url,target_item_identity_evidence.source_link_url),
              identity_match_kind=excluded.identity_match_kind,source_anomaly_status=excluded.source_anomaly_status,
              evidence_json=excluded.evidence_json,updated_at=excluded.updated_at");
        $e->execute([
            ':id'=>$targetItemId,':title'=>$verification['source_link_title']??null,':url'=>$verification['source_link_url']??null,
            ':match'=>$verification['identity_match_kind']??'UNRESOLVED',':anomaly'=>$verification['source_anomaly_status']??'UNRESOLVED',
            ':json'=>json_encode($verification['identity_evidence']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>gmdate('c'),
        ]);
    }
}
