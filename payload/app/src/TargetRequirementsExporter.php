<?php
declare(strict_types=1);

/**
 * Exports the already imported global target catalog into Gearplaner 0.5
 * planning tasks.  It is read-only toward the Gear Center database and never
 * creates personal goals.
 */
final class TargetRequirementsExporter
{
    /** @param array<int,string> $jobs */
    public static function build(PDO $pdo, array $jobs=[]): array
    {
        $all=array_keys(AllJobsTargetParser::jobs());
        if(!$jobs)$jobs=$all;
        $jobs=array_values(array_intersect($all,array_map(static fn($v)=>strtoupper(trim((string)$v)),$jobs)));
        $repo=new TargetRepository($pdo);
        $groups=[];$rowCount=0;
        foreach($jobs as $job){
            foreach($repo->latestItems($job) as $row){
                $rowCount++;
                $set=self::pick($row,['set_name','target_set_name','source_set_name','set_title'],'Target Set');
                $variant=self::pick($row,['variant_name','target_variant','source_variant','variant'],'Standard');
                $slot=self::pick($row,['slot_name','slot','equipment_slot','source_slot'],'');
                $name=trim((string)($row['source_link_title']??''));
                if($name==='')$name=self::pick($row,['source_item_name','item_name','target_item_name','canonical_name'],'');
                if($name==='')continue;
                $itemId=self::positiveInt($row,['canonical_item_id','resolved_item_id','catalog_item_id','item_id']);
                $details=self::details($row);
                $augmentRequired=self::augmentRequired($details,$row);
                $gkey=$job."\0".$set."\0".$variant;
                if(!isset($groups[$gkey]))$groups[$gkey]=[
                    'job'=>$job,'set'=>$set,'variant'=>$variant,'requirements'=>[],'row_ids'=>[],
                ];
                $rkey=($itemId?('id:'.$itemId):('name:'.self::norm($name))).'|'.hash('sha1',json_encode($details,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                if(!isset($groups[$gkey]['requirements'][$rkey])){
                    $req=['kind'=>'item','quantity'=>0,'label'=>$name,'item_name'=>$name];
                    if($itemId)$req['item_id']=$itemId;
                    if($slot!=='')$req['slots']=[$slot];
                    if($details)$req['details']=$details;
                    if($augmentRequired)$req['augment_required']=true;
                    $groups[$gkey]['requirements'][$rkey]=$req;
                } elseif($slot!=='') {
                    $slots=$groups[$gkey]['requirements'][$rkey]['slots']??[];
                    if(!in_array($slot,$slots,true))$slots[]=$slot;
                    $groups[$gkey]['requirements'][$rkey]['slots']=$slots;
                }
                $groups[$gkey]['requirements'][$rkey]['quantity']++;
                if(isset($row['id']))$groups[$gkey]['row_ids'][]=(int)$row['id'];
            }
        }
        $tasks=[];
        foreach($groups as $g){
            $id='target:'.$g['job'].':'.substr(hash('sha256',self::norm($g['set'])."\0".self::norm($g['variant'])),0,20);
            $tasks[]=[
                'id'=>$id,
                'title'=>$g['set'].' · '.$g['variant'],
                'description'=>'Globales Gear-Set aus dem verifizierten Gear-Center Target-Katalog. Erst durch „Als Ziel setzen“ wird es persönlich.',
                'category'=>'gear_set',
                'job'=>$g['job'],
                'source_type'=>'global_guide',
                'source_ref'=>'https://github.com/IzaKastra/bg_job_guides/blob/main/'.strtolower($g['job']).'.md',
                'source_meta'=>[
                    'provider'=>'IzaKastra/bg_job_guides',
                    'generated_by'=>'gearcenter_target_catalog_v2',
                    'set_name'=>$g['set'],'variant_name'=>$g['variant'],
                    'target_row_count'=>count($g['row_ids']),
                ],
                'priority_hint'=>0,
                'requirements'=>array_values($g['requirements']),
            ];
        }
        usort($tasks,static fn($a,$b)=>[$a['job'],strtolower($a['title'])]<=>[$b['job'],strtolower($b['title'])]);
        return ['schema'=>'gearplaner.requirements/0.6','tasks'=>$tasks,'meta'=>[
            'generated_by'=>'gearcenter_target_catalog_v2','jobs'=>$jobs,'target_rows'=>$rowCount,'task_count'=>count($tasks),
        ]];
    }

    public static function mergePreservingManual(array $existing,array $generated): array
    {
        $keep=[];
        foreach(($existing['tasks']??[]) as $task){
            $gen=(string)($task['source_meta']['generated_by']??'');
            if(!in_array($gen,['gearcenter_target_catalog_v1','gearcenter_target_catalog_v2'],true))$keep[]=$task;
        }
        $out=$generated;$out['tasks']=array_merge($keep,$generated['tasks']??[]);
        $out['meta']['preserved_non_generated_tasks']=count($keep);
        return $out;
    }

    private static function pick(array $row,array $keys,string $default): string
    { foreach($keys as $k){$v=trim((string)($row[$k]??''));if($v!=='')return $v;}return $default; }
    private static function positiveInt(array $row,array $keys): ?int
    { foreach($keys as $k){$v=(int)($row[$k]??0);if($v>0)return $v;}return null; }
    private static function norm(string $s): string
    { $s=str_replace(["’","‘"],"'",trim($s));$s=preg_replace('/\s+/u',' ',$s)??$s;return mb_strtolower($s,'UTF-8'); }
    private static function details(array $row): array
    {
        foreach(['details','details_json','source_details','augment_details','required_augments','augment_text'] as $k){
            if(!array_key_exists($k,$row)||$row[$k]===null||$row[$k]==='')continue;
            $v=$row[$k];
            if(is_array($v))return array_values(array_filter(array_map('strval',$v),static fn($x)=>trim($x)!==''));
            if(is_string($v)){
                $d=json_decode($v,true);
                if(is_array($d))return array_values(array_filter(array_map(static fn($x)=>is_scalar($x)?(string)$x:json_encode($x,JSON_UNESCAPED_UNICODE),$d),static fn($x)=>trim((string)$x)!==''));
                return [trim($v)];
            }
        }
        return [];
    }
    private static function augmentRequired(array $details,array $row): bool
    {
        foreach(['augment_required','has_augment','requires_augment'] as $k)if(!empty($row[$k]))return true;
        if(!$details)return false;
        $text=strtolower(implode(' ',array_map('strval',$details)));
        return str_contains($text,'augment')||str_contains($text,'path ')||str_contains($text,'weapon skill damage')||str_contains($text,'double attack')||str_contains($text,'crit');
    }
}
