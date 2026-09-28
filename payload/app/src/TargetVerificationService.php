<?php
declare(strict_types=1);

/** Strict per-target verification. System-family recognition alone is never enough. */
final class TargetVerificationService
{
    private BgWikiRepository $wiki;
    private AcquisitionRouteService $routes;
    private ProgressionChainService $chains;
    private ProgressionSystemService $systems;
    private CanonicalItemIdentityService $identities;
    private TargetDetailReconciler $details;
    private TargetChainCompletenessService $chainCompleteness;
    public function __construct(private PDO $pdo){
        $this->wiki=new BgWikiRepository($pdo);$this->routes=new AcquisitionRouteService();$this->chains=new ProgressionChainService($this->wiki);$this->systems=new ProgressionSystemService();$this->identities=new CanonicalItemIdentityService($pdo);
        $this->details=new TargetDetailReconciler();$this->chainCompleteness=new TargetChainCompletenessService();
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    public function verify(array $target): array
    {
        $sourceName=trim((string)$target['source_item_name']);$details=json_decode((string)($target['details_json']??'[]'),true);if(!is_array($details))$details=[];
        $assumptions=json_decode((string)($target['target_assumptions_json']??'{}'),true);if(!is_array($assumptions))$assumptions=[];
        $identityResolution=$this->identities->resolve($target);$def=$identityResolution['definition'];$identity=(string)$identityResolution['identity_status'];
        $canonicalName=(string)$identityResolution['canonical_item_name'];$canonicalNorm=(string)$identityResolution['canonical_item_norm'];$canonicalPage=$identityResolution['canonical_page_title'];
        $reason=(array)$identityResolution['review_reasons'];$page=$canonicalPage?$this->wiki->byPageTitle((string)$canonicalPage):null;
        $lookupName=$canonicalPage?(string)$canonicalPage:$canonicalName;

        $records=$page?$this->wiki->progressionRecordsByItem($lookupName):[];
        $directRoutes=$page?$this->routes->build($lookupName,$records,$page):[];
        $acq=array_values(array_filter($directRoutes,static fn(array $r):bool=>($r['group']??'')==='Acquisition'));
        $chain=$page?$this->chains->build($lookupName,6):['has_data'=>false,'upstream_paths'=>[],'actions'=>[],'augment_options'=>[]];
        $routeStatus=$acq?'VERIFIED':'REVIEW_REQUIRED';$routeEvidence=[];
        foreach($acq as $r)$routeEvidence[]=['kind'=>$r['kind'],'title'=>$r['title'],'source_title'=>$r['source_title'],'source_url'=>$r['source_url']];
        if(!$acq && !empty($chain['upstream_paths'])){
            foreach($chain['upstream_paths'] as $path){
                if(!$path)continue;$root=(string)$path[0]['from'];$rootPage=$this->wiki->byItem($root);
                if(!$rootPage){$reason[]='Explicit upstream chain exists, but base page is not cached: '.$root;continue;}
                $rootRecords=$this->wiki->progressionRecordsByItem($root);$rootRoutes=$this->routes->build($root,$rootRecords,$rootPage);
                $rootAcq=array_values(array_filter($rootRoutes,static fn(array $r):bool=>($r['group']??'')==='Acquisition'));
                if($rootAcq){$routeStatus='VERIFIED';$routeEvidence[]=['kind'=>'upstream_chain','root'=>$root,'steps'=>array_map(static fn(array $e)=>$e['from'].' -> '.$e['to'],$path),'root_acquisition'=>array_column($rootAcq,'title')];break;}
            }
        }
        if($routeStatus!=='VERIFIED')$reason[]='No direct acquisition route or explicit upstream chain to a cached acquirable base is verified yet.';

        $systemKeys=[];$systemLabels=[];$systemDetails=[];$superior=(int)($def['superior_level']??0);
        foreach($this->chains->edges($records) as $edge){
            $edge['item_superior_level']=$superior;$system=$this->systems->resolveEdge($edge,[],[]);
            if(!$system)continue;$key=(string)$system['key'];$systemKeys[$key]=true;$systemLabels[(string)$system['label']]=true;$systemDetails[$key]=$system;
        }
        $targetHints=$this->details->reconcile(
            $details,$assumptions,$sourceName,$canonicalName,(array)($chain['augment_options']??[]),$systemDetails,$records
        );
        $augmentStatus=(string)$targetHints['status'];
        foreach(($targetHints['review_reasons']??[]) as $detailReason)$reason[]=(string)$detailReason;

        $fresh=LatestTierAudit::classifyItem([
            'name'=>$canonicalName,'known_systems'=>array_keys($systemLabels),'known_system_keys'=>array_keys($systemKeys),
            'has_target_augment'=>$targetHints['has_targets'],'target_augments'=>$details,
        ]);
        $freshness=(string)($fresh['latest_status']??'REVIEW_REQUIRED');
        if($freshness==='NOT_APPLICABLE' && $routeStatus==='VERIFIED')$freshness='NOT_APPLICABLE';
        elseif($freshness==='REVIEW_REQUIRED' && !$systemKeys && !$targetHints['has_targets'])$freshness='NOT_APPLICABLE';
        elseif($freshness==='REVIEW_REQUIRED')$reason[]='Progression family is not verified against the current freshness registry.';

        $chainAudit=$this->chainCompleteness->audit(
            $chain,$fresh,fn(string $item):bool=>$this->wiki->byItem($item)!==null
        );
        $chainStatus=(string)$chainAudit['status'];
        foreach(($chainAudit['review_reasons']??[]) as $chainReason)$reason[]=(string)$chainReason;

        $strict=($identity==='VERIFIED' && $routeStatus==='VERIFIED' && in_array($augmentStatus,['VERIFIED','NOT_APPLICABLE'],true) && in_array($freshness,['CURRENT_VERIFIED','NEWER_TIER_AVAILABLE','NOT_APPLICABLE'],true) && in_array($chainStatus,['VERIFIED','NOT_APPLICABLE'],true))?'VERIFIED':'REVIEW_REQUIRED';
        return [
            'source_item_name'=>$sourceName,'source_link_title'=>$identityResolution['source_link_title'],'source_link_url'=>$identityResolution['source_link_url'],
            'canonical_item_name'=>$canonicalName,'canonical_item_norm'=>$canonicalNorm,'canonical_item_id'=>$identityResolution['canonical_item_id'],
            'canonical_page_title'=>$canonicalPage,'identity_status'=>$identity,'route_status'=>$routeStatus,'augment_status'=>$augmentStatus,'freshness_status'=>$freshness,'chain_status'=>$chainStatus,'strict_status'=>$strict,
            'identity_match_kind'=>$identityResolution['identity_match_kind'],'source_anomaly_status'=>$identityResolution['source_anomaly_status'],'identity_evidence'=>$identityResolution['identity_evidence'],
            'system_keys'=>array_keys($systemKeys),'system_labels'=>array_keys($systemLabels),'route_evidence'=>$routeEvidence,'target_details'=>$details,
            'target_reconciliation'=>$targetHints,'chain_completeness'=>$chainAudit,
            'latest_tier'=>$fresh['latest_tier']??null,'latest_reason'=>$fresh['latest_reason']??null,'review_reason'=>$reason?implode(' | ',array_values(array_unique($reason))):null,
        ];
    }
}
