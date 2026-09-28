<?php
declare(strict_types=1);

/**
 * Resolves a guide label without ever changing the stored source label.
 *
 * Only exact runtime names, explicit guide links and cached BG-Wiki mappings are
 * accepted as evidence.  Similar-looking names and suffix patterns are never
 * used to join two items.
 */
final class CanonicalItemIdentityService
{
    /** @var array<string,array<int,array<string,mixed>>>|null */
    private ?array $definitionsByNorm=null;
    private BgWikiRepository $wiki;

    public function __construct(private PDO $pdo)
    {
        $this->wiki=new BgWikiRepository($pdo);
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    public function resolve(array $target): array
    {
        $sourceName=trim((string)($target['source_item_name']??''));
        $sourceNorm=LacProfileParser::normalizeName($sourceName);
        $linkTitle=trim((string)($target['source_link_title']??''));
        $linkUrl=trim((string)($target['source_link_url']??''));
        $linkNorm=LacProfileParser::normalizeName($linkTitle);

        $sourceDefs=$this->definitionsForNorm($sourceNorm);
        $linkDefs=$linkNorm!==''?$this->definitionsForNorm($linkNorm):[];
        $sourceMap=$this->wiki->mappingForAlias($sourceName);
        $linkMap=$linkTitle!==''?$this->wiki->mappingForAlias($linkTitle):null;
        [$page,$pageBasis]=$this->resolvePage($sourceName,$linkTitle);
        $pageTitle=trim((string)($page['page_title']??''));
        $pageNorm=LacProfileParser::normalizeName($pageTitle);
        $pageDefs=$pageNorm!==''?$this->definitionsForNorm($pageNorm):[];

        $conflict=false;$ambiguous=false;$reasons=[];
        $sourceDef=count($sourceDefs)===1?$sourceDefs[0]:null;
        $linkDef=count($linkDefs)===1?$linkDefs[0]:null;
        $pageDef=count($pageDefs)===1?$pageDefs[0]:null;
        if(count($sourceDefs)>1 || count($linkDefs)>1 || count($pageDefs)>1){
            $ambiguous=true;$reasons[]='More than one runtime/resource item has the same exact normalized identity.';
        }
        if($sourceDef && $linkDef && (int)$sourceDef['item_id']!==(int)$linkDef['item_id']){
            $conflict=true;$reasons[]='The preserved source label and its explicit link target resolve to different runtime item IDs.';
        }
        if($sourceMap && $linkTitle!=='' && !$this->samePage((string)$sourceMap['page_title'],$linkTitle)
            && $linkMap && !$this->samePage((string)$sourceMap['page_title'],(string)$linkMap['page_title'])){
            $conflict=true;$reasons[]='The cached source-label mapping conflicts with the explicit guide link target.';
        }

        $definition=null;$matchKind='UNRESOLVED';
        if(!$conflict && !$ambiguous){
            if($sourceDef){$definition=$sourceDef;$matchKind='EXACT_RUNTIME_LABEL';}
            elseif($linkDef){$definition=$linkDef;$matchKind='SOURCE_LINK_RUNTIME';}
            elseif($pageDef){$definition=$pageDef;$matchKind='CANONICAL_PAGE_RUNTIME';}
        }

        $mappingConfidence=(string)($sourceMap['confidence']??'');
        $trustedFingerprint=$mappingConfidence==='resource_stat_fingerprint';
        $explicitLinkToPage=$linkNorm!=='' && $pageNorm!=='' && (
            $linkNorm===$pageNorm || ($linkMap && $this->samePage((string)$linkMap['page_title'],$pageTitle))
        );
        $trustedMapping=$mappingConfidence==='resource_stat_fingerprint'
            || ($mappingConfidence==='exact_title' && $sourceNorm===$pageNorm);
        $trustedPageRelation=$page!==null && (
            ($definition && LacProfileParser::normalizeName((string)$definition['name'])===$pageNorm)
            || ($sourceMap && $this->samePage((string)$sourceMap['page_title'],$pageTitle)
                && $trustedMapping)
        );

        $identityStatus='REVIEW_REQUIRED';
        if($conflict){$matchKind='SOURCE_LINK_CONFLICT';}
        elseif($ambiguous){$matchKind='AMBIGUOUS_RUNTIME_IDENTITY';}
        elseif($definition && $page && $trustedPageRelation){
            $identityStatus='VERIFIED';
            if($trustedFingerprint && $pageNorm!==LacProfileParser::normalizeName((string)$definition['name']))$matchKind='RESOURCE_FINGERPRINT_ALIAS';
            elseif($sourceNorm===LacProfileParser::normalizeName((string)$definition['name']))$matchKind='EXACT_RUNTIME_AND_PAGE';
        }elseif($definition){
            $identityStatus='PARTIAL';
            $reasons[]=$page?'A runtime item exists, but the cached page relation is not backed by exact/link/fingerprint evidence.':'Runtime/resource identity exists, but its BG-Wiki item page is not cached.';
        }elseif($page){
            $identityStatus='PARTIAL';$matchKind='CANONICAL_PAGE_ONLY';
            $reasons[]='A canonical BG-Wiki page is cached, but no exact runtime/resource item identity is available.';
        }else{
            $reasons[]='Neither an exact runtime/resource identity nor a cached canonical item page resolves this target.';
        }

        $canonicalName=$definition?(string)$definition['name']:($pageTitle!==''?$pageTitle:$sourceName);
        $canonicalNorm=LacProfileParser::normalizeName($canonicalName);
        if($conflict)$anomaly='SOURCE_LINK_CONFLICT';
        elseif($ambiguous)$anomaly='AMBIGUOUS_IDENTITY';
        elseif($definition && $sourceNorm!==$canonicalNorm){
            $anomaly=$identityStatus==='VERIFIED'?'SOURCE_LABEL_MISMATCH':'UNVERIFIED_ALIAS';
            $reasons[]='The guide label differs from the canonical runtime item; both values are preserved for review.';
        }elseif(!$definition && $pageTitle!=='' && $sourceNorm!==$pageNorm)$anomaly='UNVERIFIED_ALIAS';
        elseif($identityStatus==='REVIEW_REQUIRED')$anomaly='UNRESOLVED';
        else $anomaly='NONE';

        $evidence=[
            'source_label'=>$sourceName,'source_norm'=>$sourceNorm,
            'source_link_title'=>$linkTitle!==''?$linkTitle:null,'source_link_url'=>$linkUrl!==''?$linkUrl:null,
            'source_runtime_ids'=>array_map(static fn(array $r):int=>(int)$r['item_id'],$sourceDefs),
            'link_runtime_ids'=>array_map(static fn(array $r):int=>(int)$r['item_id'],$linkDefs),
            'page_runtime_ids'=>array_map(static fn(array $r):int=>(int)$r['item_id'],$pageDefs),
            'source_mapping'=>$sourceMap?['page_title'=>$sourceMap['page_title'],'confidence'=>$sourceMap['confidence']]:null,
            'link_mapping'=>$linkMap?['page_title'=>$linkMap['page_title'],'confidence'=>$linkMap['confidence']]:null,
            'resolved_page_title'=>$pageTitle!==''?$pageTitle:null,'page_basis'=>$pageBasis,
            'explicit_link_to_page'=>$explicitLinkToPage,'trusted_page_relation'=>$trustedPageRelation,
        ];
        return [
            'source_item_name'=>$sourceName,'source_link_title'=>$linkTitle!==''?$linkTitle:null,'source_link_url'=>$linkUrl!==''?$linkUrl:null,
            'canonical_item_name'=>$canonicalName,'canonical_item_norm'=>$canonicalNorm,'canonical_item_id'=>$definition['item_id']??null,
            'canonical_page_title'=>$pageTitle!==''?$pageTitle:null,'identity_status'=>$identityStatus,
            'identity_match_kind'=>$matchKind,'source_anomaly_status'=>$anomaly,'identity_evidence'=>$evidence,
            'definition'=>$definition,'review_reasons'=>array_values(array_unique($reasons)),
        ];
    }

    /** @return array{0:?array,1:string} */
    private function resolvePage(string $sourceName,string $linkTitle): array
    {
        if($linkTitle!==''){
            $page=$this->wiki->byPageTitle($linkTitle);if($page)return [$page,'source_link_page'];
            $page=$this->wiki->byItem($linkTitle);if($page)return [$page,'source_link_mapping'];
        }
        $page=$this->wiki->byItem($sourceName);if($page)return [$page,'source_label_mapping'];
        $page=$this->wiki->byPageTitle($sourceName);if($page)return [$page,'source_label_page'];
        return [null,'none'];
    }

    /** @return array<int,array<string,mixed>> */
    private function definitionsForNorm(string $norm): array
    {
        if($norm==='')return [];
        if($this->definitionsByNorm===null){
            $this->definitionsByNorm=[];
            foreach($this->pdo->query('SELECT item_id,name,superior_level,item_level,equip_slots_mask FROM item_definitions ORDER BY item_id')->fetchAll() as $row){
                $key=LacProfileParser::normalizeName((string)$row['name']);$this->definitionsByNorm[$key][]=$row;
            }
        }
        return $this->definitionsByNorm[$norm]??[];
    }

    private function samePage(string $a,string $b): bool
    {
        return LacProfileParser::normalizeName(str_replace('_',' ',trim($a)))===LacProfileParser::normalizeName(str_replace('_',' ',trim($b)));
    }
}
