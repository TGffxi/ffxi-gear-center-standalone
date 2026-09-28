<?php
declare(strict_types=1);

/**
 * Resolves ambiguous BG-Wiki base-name pages against the concrete Ashita item
 * definition.  Example: the game item named "Kikoku" (resource id 21906) maps to
 * "Kikoku (Level 119 III)" because its real DMG/Delay/Attack/skill fingerprint
 * matches that page.  No stage is selected from the page suffix alone.
 */
final class BgWikiItemResolver
{
    public function __construct(private PDO $pdo, private BgWikiClient $client) {}

    /** @return array<string,mixed> */
    public function fetch(string $itemName): array
    {
        $page=$this->client->fetchPage($itemName);
        if(empty($page['exists']) || (string)($page['content_format']??'html')!=='html')return $page;

        $candidates=BgWikiExtractor::versionCandidateTitles((string)$page['content'],$itemName,'html');
        if(count($candidates)<2)return $page;
        $resource=$this->resourceForName($itemName);if($resource===null)return $page;

        $resourceFacts=$this->resourceFacts($resource);if(count($resourceFacts)<2)return $page;
        $ranked=[];
        foreach($this->prioritizeCandidates($candidates,(int)$resource['level'],(int)$resource['item_level']) as $candidate){
            try{$cp=$this->client->fetchPage($candidate);}catch(Throwable $e){continue;}
            if(empty($cp['exists']))continue;
            $facts=BgWikiExtractor::itemFacts((string)$cp['content'],'html');
            [$score,$matches,$mismatches]=$this->score($resourceFacts,$facts);
            $ranked[]=['page'=>$cp,'score'=>$score,'matches'=>$matches,'mismatches'=>$mismatches,'facts'=>$facts];
            // Four independent exact base-stat matches are strong enough to stop;
            // this keeps large REMA version ladders from causing unnecessary calls.
            if($matches>=4 && $mismatches===0)break;
        }
        if(!$ranked)return $page;
        usort($ranked,static fn($a,$b)=>$b['score']<=>$a['score'] ?: $b['matches']<=>$a['matches']);
        $best=$ranked[0];$second=$ranked[1]??null;
        if($best['matches']<2 || $best['score']<6)return $page;
        if($second!==null && $best['score']-$second['score']<3 && $best['matches']<4)return $page;
        $resolved=$best['page'];
        $resolved['requested_title']=$itemName;
        $resolved['resolved_from_ambiguous']=true;
        $resolved['resolution']='resource_stat_fingerprint';
        $resolved['resolution_score']=$best['score'];
        $resolved['resolution_matches']=$best['matches'];
        $resolved['resource_item_id']=(int)$resource['item_id'];
        return $resolved;
    }

    private function resourceForName(string $name): ?array
    {
        $s=$this->pdo->prepare("SELECT d.*,
            EXISTS(SELECT 1 FROM equipment_snapshot_items e JOIN v_latest_snapshots ls ON ls.id=e.snapshot_id WHERE e.item_id=d.item_id) AS currently_owned
            FROM item_definitions d WHERE lower(trim(d.name))=:norm
            ORDER BY currently_owned DESC,last_seen_at DESC,item_id DESC LIMIT 1");
        $s->execute([':norm'=>LacProfileParser::normalizeName($name)]);$r=$s->fetch();return $r?:null;
    }

    /** @return array<string,int> */
    private function resourceFacts(array $resource): array
    {
        $stats=json_decode((string)($resource['stats_json']??'{}'),true);$base=is_array($stats['base']??null)?$stats['base']:[];$out=[];
        if((int)($resource['level']??0)>0)$out['level']=(int)$resource['level'];
        foreach(['dmg','delay','atk','mdmg','skkatana','skparry','skh2h','skdagger','sksword','skgsword','skaxe','skgaxe','skscythe','skpolearm','skgkatana','skclub','skstaff'] as $key){
            $v=$base[$key]['base']??null;if(is_numeric($v) && (int)$v!==0)$out[$key]=(int)$v;
        }
        return $out;
    }

    /** @return array{0:int,1:int,2:int} */
    private function score(array $resource,array $page): array
    {
        $score=0;$matches=0;$mismatches=0;
        foreach($resource as $key=>$value){if(!array_key_exists($key,$page))continue;$weight=$key==='level'?1:4;if((int)$page[$key]===(int)$value){$score+=$weight;$matches++;}else{$score-=$weight;$mismatches++;}}
        return [$score,$matches,$mismatches];
    }

    /** @return array<int,string> */
    private function prioritizeCandidates(array $candidates,int $level,int $itemLevelRaw): array
    {
        // Candidate names are not trusted as the final decision; this merely tries
        // likely same-level/iLevel pages first. The resource-stat comparison still decides.
        usort($candidates,static function(string $a,string $b)use($level,$itemLevelRaw):int{
            $ia=$itemLevelRaw>0 && preg_match('/119/i',$a)?0:1;
            $ib=$itemLevelRaw>0 && preg_match('/119/i',$b)?0:1;
            if($ia!==$ib)return $ia<=>$ib;
            $sa=(preg_match('/(?:Level|Lv\.)\s*'.preg_quote((string)$level,'/').'/i',$a)?0:1);
            $sb=(preg_match('/(?:Level|Lv\.)\s*'.preg_quote((string)$level,'/').'/i',$b)?0:1);
            if($sa!==$sb)return $sa<=>$sb;
            return strcasecmp($b,$a);
        });
        return array_values(array_unique($candidates));
    }
}
