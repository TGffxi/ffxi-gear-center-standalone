<?php
declare(strict_types=1);

/** Audits whether explicit downstream relations reach the registered latest tier. */
final class TargetChainCompletenessService
{
    /**
     * @param array<string,mixed> $chain
     * @param array<string,mixed> $freshness
     * @param callable(string):bool $pageExists
     * @return array<string,mixed>
     */
    public function audit(array $chain,array $freshness,callable $pageExists): array
    {
        $freshStatus=(string)($freshness['latest_status']??'REVIEW_REQUIRED');
        $paths=is_array($chain['downstream_paths']??null)?$chain['downstream_paths']:[];
        $latest=(string)($freshness['latest_tier']??'');
        $expectedTier=$this->expectedNumericTier($freshness);

        if($freshStatus==='NOT_APPLICABLE'){
            return ['status'=>'NOT_APPLICABLE','evidence'=>[],'review_reasons'=>[],'expected_latest_tier'=>$latest];
        }
        if($freshStatus==='REVIEW_REQUIRED'){
            return [
                'status'=>'REVIEW_REQUIRED','evidence'=>[],
                'review_reasons'=>['Downstream completeness cannot be accepted until the progression family is mapped to the current freshness registry.'],
                'expected_latest_tier'=>$latest,
            ];
        }

        if($freshStatus==='CURRENT_VERIFIED'){
            $currentTier=$this->numericTier((string)($freshness['name']??$chain['resolved_focus']??$chain['focus']??''));
            foreach($paths as $path){
                if(!is_array($path)||!$path)continue;$tail=(string)($path[count($path)-1]['to']??'');$tailTier=$this->numericTier($tail);
                if($currentTier!==null && $tailTier!==null && $tailTier>$currentTier){
                    return [
                        'status'=>'REVIEW_REQUIRED',
                        'evidence'=>[['kind'=>'unexpected_explicit_downstream','target'=>$tail,'path'=>$this->pathLabels($path)]],
                        'review_reasons'=>['An explicit downstream upgrade exceeds the tier currently marked as latest; refresh the registry/source review before accepting the chain.'],
                        'expected_latest_tier'=>$latest,
                    ];
                }
            }
            return [
                'status'=>'VERIFIED',
                'evidence'=>[['kind'=>'registry_current','latest_tier'=>$latest,'freshness_systems'=>$freshness['freshness_systems']??[]]],
                'review_reasons'=>[],'expected_latest_tier'=>$latest,
            ];
        }

        // NEWER_TIER_AVAILABLE is only complete when a structured downstream
        // relation reaches that tier and the concrete destination page is cached.
        $uncached=[];$wrongTier=[];
        foreach($paths as $path){
            if(!is_array($path)||!$path)continue;
            $tail=(string)($path[count($path)-1]['to']??'');if($tail==='')continue;
            $tailTier=$this->numericTier($tail);
            if($expectedTier===null || $tailTier===null || $tailTier<$expectedTier){
                $wrongTier[]=['target'=>$tail,'tier'=>$tailTier,'path'=>$this->pathLabels($path)];continue;
            }
            if(!(bool)$pageExists($tail)){
                $uncached[]=['target'=>$tail,'tier'=>$tailTier,'path'=>$this->pathLabels($path)];continue;
            }
            return [
                'status'=>'VERIFIED',
                'evidence'=>[array_merge(['kind'=>'explicit_cached_latest_tier','target'=>$tail,'tier'=>$tailTier],['path'=>$this->pathLabels($path)])],
                'review_reasons'=>[],'expected_latest_tier'=>$latest,'expected_numeric_tier'=>$expectedTier,
            ];
        }

        $reasons=[];
        if(!$paths)$reasons[]='The freshness registry reports a newer tier, but no explicit structured downstream item relation is cached.';
        if($wrongTier)$reasons[]='Structured downstream relations exist, but none reaches the registered latest numeric tier '.($expectedTier??'unknown').'.';
        if($uncached)$reasons[]='The explicit latest-tier destination is known, but its concrete item page is not cached: '.implode(', ',array_values(array_unique(array_column($uncached,'target')))).'.';
        if(!$reasons)$reasons[]='The newer-tier advisory could not be reconciled with a concrete cached downstream item.';
        return [
            'status'=>'REVIEW_REQUIRED','evidence'=>array_merge($wrongTier,$uncached),'review_reasons'=>$reasons,
            'expected_latest_tier'=>$latest,'expected_numeric_tier'=>$expectedTier,
        ];
    }

    /** @param array<string,mixed> $freshness */
    private function expectedNumericTier(array $freshness): ?int
    {
        $max=0;
        foreach(($freshness['freshness_systems']??[]) as $system)$max=max($max,(int)($system['max_numeric_tier']??0));
        if($max>0)return $max;
        return $this->numericTier((string)($freshness['latest_tier']??''));
    }

    private function numericTier(string $value): ?int
    {
        return preg_match('/\+(\d+)\b/u',$value,$m)?(int)$m[1]:null;
    }

    /** @param array<int,array<string,mixed>> $path @return array<int,string> */
    private function pathLabels(array $path): array
    {
        return array_map(static fn(array $edge):string=>(string)($edge['from']??'').' -> '.(string)($edge['to']??''),$path);
    }
}
