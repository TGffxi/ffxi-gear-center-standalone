<?php
declare(strict_types=1);

/**
 * Audits a CoverageAnalyzer report for two independent questions:
 *  - is the current LAC/goal target understood?
 *  - is the underlying progression family current against the post-2024 registry?
 */
final class LatestTierAudit
{
    /** @param array<string,mixed> $coverage */
    public function report(array $coverage): array
    {
        $rows=[];$counts=['NEWER_TIER_AVAILABLE'=>0,'CURRENT_VERIFIED'=>0,'NOT_APPLICABLE'=>0,'REVIEW_REQUIRED'=>0];
        $tierRelevant=0;$aware=0;$verified=0;
        foreach(($coverage['items']??[]) as $item){
            $r=self::classifyItem($item);
            $rows[]=$r;$counts[$r['latest_status']]++;
            if($r['tier_relevant']){
                $tierRelevant++;
                if($r['latest_status']!=='REVIEW_REQUIRED')$aware++;
                if($r['post_2024_verified'])$verified++;
            }
        }
        usort($rows,static function(array $a,array $b):int{
            $p=['NEWER_TIER_AVAILABLE'=>0,'REVIEW_REQUIRED'=>1,'CURRENT_VERIFIED'=>2,'NOT_APPLICABLE'=>3];
            return ($p[$a['latest_status']]<=>$p[$b['latest_status']]) ?: strcasecmp((string)$a['name'],(string)$b['name']);
        });
        return [
            'knowledge_version'=>SystemFreshnessRegistry::KNOWLEDGE_VERSION,
            'verified_through'=>SystemFreshnessRegistry::VERIFIED_THROUGH,
            'total'=>(int)($coverage['total']??count($rows)),
            'tier_relevant'=>$tierRelevant,
            'counts'=>$counts,
            'latest_awareness_percent'=>$tierRelevant?round(($aware/$tierRelevant)*100,1):100.0,
            'post_2024_verified_percent'=>$tierRelevant?round(($verified/$tierRelevant)*100,1):100.0,
            'rows'=>$rows,
            'timeline'=>SystemFreshnessRegistry::timeline(),
        ];
    }

    /** @param array<string,mixed> $item @return array<string,mixed> */
    public static function classifyItem(array $item): array
    {
        $systems=array_values(array_filter(array_map('strval',$item['known_systems']??[])));
        $systemKeys=array_values(array_filter(array_map('strval',$item['known_system_keys']??[])));
        $newer='';$meta=[];$unmatched=[];

        // Prefer stable ProgressionSystemService adapter keys. Labels remain a
        // backward-compatible fallback for old fixtures / external callers.
        foreach($systemKeys as $adapterKey){
            $m=SystemFreshnessRegistry::matchAdapterKey($adapterKey);
            if($m)$meta[$m['key']]=$m;
            else $unmatched[]='adapter:'.$adapterKey;
        }
        foreach($systems as $label){
            if(str_starts_with(strtolower($label),'next upgrade:'))$newer=$label;
            $m=SystemFreshnessRegistry::matchLabel($label);
            if($m)$meta[$m['key']]=$m;
            elseif(!$systemKeys)$unmatched[]=$label;
        }
        $hasProgression=(bool)($item['has_target_augment']??false) || !empty($systems);
        if(!$hasProgression){
            return array_merge($item,[
                'latest_status'=>'NOT_APPLICABLE','tier_relevant'=>false,'post_2024_verified'=>false,
                'latest_tier'=>'—','latest_reason'=>'No tiered/augment progression is required for the current item target.',
                'freshness_systems'=>[],
            ]);
        }
        if($newer!==''){
            $first=$meta?reset($meta):null;
            return array_merge($item,[
                'latest_status'=>'NEWER_TIER_AVAILABLE','tier_relevant'=>true,
                'post_2024_verified'=>$first?($first['freshness']==='POST_2024_VERIFIED'):false,
                'latest_tier'=>$first['latest_tier']??preg_replace('/^Next upgrade:\s*/i','',$newer),
                'latest_reason'=>$newer.' is newer than the current LAC/goal target; it is advisory until explicitly added as a goal.',
                'freshness_systems'=>array_values($meta),
            ]);
        }
        // Numeric reforged tiers can be audited even when the current item's page
        // did not expose an explicit "Next upgrade" row. This is still family-
        // driven: the registry supplies the latest tier, while the item name only
        // tells us which tier the current target actually is.
        if($meta && preg_match('/\+(\d+)\s*$/u',(string)($item['name']??''),$tm)){
            $currentTier=(int)$tm[1];
            foreach($meta as $m){
                $max=isset($m['max_numeric_tier'])?(int)$m['max_numeric_tier']:0;
                if($max>0 && $currentTier<$max){
                    return array_merge($item,[
                        'latest_status'=>'NEWER_TIER_AVAILABLE','tier_relevant'=>true,
                        'post_2024_verified'=>($m['freshness']==='POST_2024_VERIFIED'),
                        'latest_tier'=>$m['latest_tier'],
                        'latest_reason'=>'Current target is +'.$currentTier.'; the verified progression family currently extends to +'.$max.'. This remains advisory until explicitly added as a goal.',
                        'freshness_systems'=>array_values($meta),
                    ]);
                }
            }
        }
        if($meta){
            $allVerified=true;$tiers=[];
            foreach($meta as $m){$allVerified=$allVerified&&($m['freshness']==='POST_2024_VERIFIED');if(!empty($m['latest_tier']))$tiers[]=(string)$m['latest_tier'];}
            return array_merge($item,[
                'latest_status'=>'CURRENT_VERIFIED','tier_relevant'=>true,'post_2024_verified'=>$allVerified,
                'latest_tier'=>$tiers?implode(' / ',array_values(array_unique($tiers))):'Current modeled system',
                'latest_reason'=>'All matched progression families are present in the post-2024 freshness registry.',
                'freshness_systems'=>array_values($meta),
            ]);
        }
        return array_merge($item,[
            'latest_status'=>'REVIEW_REQUIRED','tier_relevant'=>true,'post_2024_verified'=>false,
            'latest_tier'=>'Unknown','latest_reason'=>'Progression is modeled, but its family is not yet mapped to the post-2024 freshness registry.',
            'freshness_systems'=>[],'unmatched_systems'=>$unmatched,
        ]);
    }
}
