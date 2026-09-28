<?php
declare(strict_types=1);

/**
 * Extracts material/currency/resource requirements from structured progression
 * edges and aggregates them per actionable plan.
 *
 * Conservative by design:
 * - quantities are only numeric when the source text explicitly encodes them;
 * - alternatives are kept as ANY-OF groups and never summed as if all were needed;
 * - unknown quantities remain unknown instead of being guessed;
 * - acquisition routes and historical stages are not mixed into a current plan.
 */
final class ProgressionResourceService
{
    private const REQUIREMENT_KEYS = [
        'Required Items','Required Item','Items Required','Item Required',
        'Requirements','Requirement','Materials','Material','Cost','Currency',
        'Trade Items','Trade Item','Trade','Needed','Needed Items',
        'Job Ingredient','Slot Ingredient','Required Gallimaufry','Required Shard','Void Ingredient','Required Temenos Units','Required Apollyon Units','Upgrade Item','Objective','Ingredients',
    ];

    /** @param array<string,mixed> $graph */
    public function summarize(array $graph, array $holdings=[], array $liveAugment=[], array $itemContext=[]): array
    {
        $plans=[];
        foreach(($graph['plans']??[]) as $plan){
            $summary=$this->forPlan($plan,$holdings,$liveAugment,$itemContext);
            if($summary['requirements']!==[] || $summary['alternative_groups']!==[] || $summary['systems']!==[])$plans[]=$summary;
        }
        return [
            'plans'=>$plans,
            'has_data'=>$plans!==[],
            'holding_count'=>count($holdings),
        ];
    }

    /** @param array<string,mixed> $plan @return array<string,mixed> */
    public function forPlan(array $plan, array $holdings=[], array $liveAugment=[], array $itemContext=[]): array
    {
        $atomic=[];$alternatives=[];$sources=[];$systems=[];
        $systemService=new ProgressionSystemService();
        $edges=array_merge($plan['path']??[],$plan['actions']??[]);
        foreach($edges as $stepIndex=>$edge){
            if(!is_array($edge))continue;
            $fields=is_array($edge['fields']??null)?$edge['fields']:[];

            // Known rank/RP systems supersede the terse item-page requirement
            // cell.  Example: "S. Astral Detritus & M. Astral Detritus" does
            // not mean that both are fixed simultaneous quantities; they are
            // exchange resources worth different RP values in Oboro's system.
            $edgeForSystem=$edge;
            $edgeForSystem['item_id']=$itemContext['item_id']??($edgeForSystem['item_id']??null);
            $edgeForSystem['item_name']=$itemContext['name']??($edgeForSystem['item_name']??($edgeForSystem['to']??$edgeForSystem['from']??''));
            $edgeForSystem['item_superior_level']=$itemContext['superior_level']??($edgeForSystem['item_superior_level']??null);
            $system=$systemService->resolveEdge($edgeForSystem,$holdings,$liveAugment);
            $skipGenericRequirements=false;
            if($system!==null){
                $system['step_index']=$stepIndex;
                $system['edge_key']=(string)($edge['key']??'');
                $system['item_name']=(string)($edge['to']??$edge['from']??'');
                $systemKey=substr(hash('sha256',CanonicalJson::encode([
                    'system'=>$system['key']??'',
                    'edge'=>$system['edge_key'],
                    'item'=>LacProfileParser::normalizeName($system['item_name']),
                ])),0,20);
                $systems[$systemKey]=$system;
                $skipGenericRequirements=(bool)($system['supersedes_requirements']??false);
            }
            if(!$skipGenericRequirements){
            foreach($this->requirementFields($fields) as $fieldName=>$raw){
                foreach($this->parseRequirementText($raw,$fieldName) as $parsed){
                    $parsed['step_index']=$stepIndex;
                    $parsed['field']=$fieldName;
                    $parsed['source_title']=(string)($edge['source_title']??'');
                    $parsed['source_url']=(string)($edge['source_url']??'');
                    $parsed['section']=(string)($edge['section']??'');
                    $parsed['edge_key']=(string)($edge['key']??'');
                    if(($parsed['mode']??'all')==='any_of'){
                        $alternatives[]=$parsed;
                    }else{
                        $atomic[]=$parsed;
                    }
                }
            }
            }
            $sourceKey=(string)($edge['source_url']??'').'|'.(string)($edge['section']??'');
            if($sourceKey!=='|')$sources[$sourceKey]=[
                'title'=>(string)($edge['source_title']??'BG-Wiki'),
                'url'=>(string)($edge['source_url']??''),
                'section'=>(string)($edge['section']??''),
            ];
        }

        $agg=[];
        foreach($atomic as $req){
            $norm=(string)$req['name_norm'];
            if($norm==='')continue;
            if(!isset($agg[$norm]))$agg[$norm]=[
                'name'=>$req['name'],'name_norm'=>$norm,'kind'=>$req['kind'],
                'required_quantity'=>0,'quantity_known'=>true,'occurrences'=>0,
                'raw'=>[],'sources'=>[],
            ];
            $agg[$norm]['occurrences']++;
            $agg[$norm]['raw'][]=$req['raw'];
            $agg[$norm]['sources'][]=[
                'section'=>$req['section'],'field'=>$req['field'],'source_title'=>$req['source_title'],'source_url'=>$req['source_url'],
            ];
            if($req['quantity']===null){
                $agg[$norm]['quantity_known']=false;
            }else{
                $agg[$norm]['required_quantity']+=(int)$req['quantity'];
            }
        }

        foreach($agg as $norm=>&$row){
            $holding=$holdings[$norm]??null;
            $row['owned_quantity']=$holding!==null?(int)$holding['quantity']:null;
            $row['holding_source']=$holding['source_type']??null;
            if(!$row['quantity_known']){
                $row['required_quantity']=null;
                $row['missing_quantity']=null;
                $row['state']=$holding===null?'QUANTITY_UNKNOWN':'REQUIRED_UNKNOWN';
            }elseif($holding===null){
                $row['missing_quantity']=null;
                $row['state']='OWNED_UNKNOWN';
            }else{
                $row['missing_quantity']=max(0,(int)$row['required_quantity']-(int)$holding['quantity']);
                $row['state']=$row['missing_quantity']===0?'ENOUGH':'MISSING';
            }
            $row['raw']=array_values(array_unique($row['raw']));
        }
        unset($row);

        // Deduplicate alternative groups without collapsing their member choices.
        $altOut=[];
        foreach($alternatives as $group){
            $key=substr(hash('sha256',CanonicalJson::encode([
                'choices'=>array_map(static fn($x)=>$x['name_norm'],$group['choices']??[]),
                'raw'=>$group['raw']??'',
                'edge'=>$group['edge_key']??'',
            ])),0,20);
            if(isset($altOut[$key]))continue;
            $choices=[];
            foreach($group['choices']??[] as $choice){
                $norm=(string)$choice['name_norm'];
                $holding=$holdings[$norm]??null;
                $choice['owned_quantity']=$holding!==null?(int)$holding['quantity']:null;
                $choice['holding_source']=$holding['source_type']??null;
                $choices[]=$choice;
            }
            $group['key']=$key;$group['choices']=$choices;
            $altOut[$key]=$group;
        }

        usort($agg,static fn($a,$b)=>strcasecmp((string)$a['name'],(string)$b['name']));
        return [
            'plan_key'=>(string)($plan['key']??''),
            'plan_title'=>(string)($plan['title']??'Progression plan'),
            'requirements'=>array_values($agg),
            'alternative_groups'=>array_values($altOut),
            'systems'=>array_values($systems),
            'sources'=>array_values($sources),
        ];
    }

    /** @return array<string,string> */
    private function requirementFields(array $fields): array
    {
        $out=[];
        foreach($fields as $key=>$value){
            $k=trim((string)$key);$v=trim((string)$value);
            if($v==='')continue;
            foreach(self::REQUIREMENT_KEYS as $wanted){
                if(strcasecmp($k,$wanted)===0){$out[$k]=$v;break;}
            }
        }
        return $out;
    }

    /** @return array<int,array<string,mixed>> */
    public function parseRequirementText(string $raw,string $fieldName=''): array
    {
        $raw=trim($raw);
        if($raw==='' || preg_match('/^see\s+.+(?:prerequisite|requirements?)/i',$raw))return [];
        // System tables such as Relic/Artifact +4 encode the currency in the
        // column header (e.g. "Required Temenos Units") and put only the
        // numeric amount in the cell. Preserve that semantic pair.
        if(preg_match('/^Required\s+(.+?\s+Units)$/i',trim($fieldName),$fm) && preg_match('/^[0-9][0-9,]*$/',$raw)){
            $name=trim($fm[1]);
            return [$this->choice($name,$this->number($raw),$raw)+['mode'=>'all']];
        }
        $raw=preg_replace('/\s+\/\s+See\s+.+$/i','',$raw)??$raw;
        $segments=preg_split('/\R+|\s*;\s*/u',$raw)?:[];
        $out=[];
        foreach($segments as $segment){
            $segment=trim($segment," \t\n\r\0\x0B,.");
            if($segment==='' || $this->looksLikeInstruction($segment))continue;

            // BG-Wiki often writes shorthand alternatives such as
            // "Eschalixir, +1, or +2". Preserve these as one ANY-OF group.
            if(preg_match('/^(.+?),\s*\+([0-9]+)\s*,\s*(?:or\s*)?\+([0-9]+)$/iu',$segment,$m)){
                $base=trim($m[1]);
                $choices=[];
                foreach([$base,$base.' +'.$m[2],$base.' +'.$m[3]] as $name)$choices[]=$this->choice($name,null,$segment);
                $out[]=['mode'=>'any_of','label'=>'Choose one accepted resource tier','choices'=>$choices,'raw'=>$segment,'kind'=>'resource'];
                continue;
            }
            if(preg_match('/^(.+?)\s+or\s+(.+)$/iu',$segment,$m) && !str_contains(strtolower($segment),'trade ')){
                $parts=preg_split('/\s+or\s+/iu',$segment)?:[];
                if(count($parts)>=2){
                    $choices=[];foreach($parts as $part)$choices[]=$this->choice($part,null,$segment);
                    $out[]=['mode'=>'any_of','label'=>'Choose one accepted resource','choices'=>$choices,'raw'=>$segment,'kind'=>'resource'];
                    continue;
                }
            }

            // Reforged Artifact tables use a dedicated Ingredients column with
            // comma-separated independent items. Split only in that semantic field
            // so commas in ordinary prose/item labels are not globally destructive.
            if(strcasecmp(trim($fieldName),'Ingredients')===0 && str_contains($segment,',')){
                foreach(preg_split('/\s*,\s*/u',$segment)?:[] as $piece){
                    $req=$this->atomic(trim($piece),$segment);
                    if($req!==null)$out[]=$req;
                }
                continue;
            }

            // Ampersand is a reliable separator in requirement fields such as
            // "S. Astral Detritus & M. Astral Detritus".
            $parts=preg_split('/\s+&\s+/u',$segment)?:[$segment];
            foreach($parts as $part){
                $req=$this->atomic(trim($part),$segment);
                if($req!==null)$out[]=$req;
            }
        }
        return $out;
    }

    private function atomic(string $text,string $raw): ?array
    {
        $text=trim($text," \t\n\r\0\x0B,.");
        if($text==='')return null;
        $qty=null;$name=$text;

        $patterns=[
            '/^([0-9][0-9,]*)\s*[x×]\s*(.+)$/iu',
            '/^(.+?)\s*[x×]\s*([0-9][0-9,]*)$/iu',
            '/^([0-9][0-9,]*)\s+(.+)$/u',
            '/^(.+?)\s*\(([0-9][0-9,]*)\)\s*$/u',
            '/^(.+?)\s*:\s*([0-9][0-9,]*)\s*$/u',
        ];
        foreach($patterns as $idx=>$pattern){
            if(!preg_match($pattern,$text,$m))continue;
            if($idx===0 || $idx===2){$qty=$this->number($m[1]);$name=trim($m[2]);}
            else{$name=trim($m[1]);$qty=$this->number($m[2]);}
            break;
        }
        $name=$this->cleanName($name);
        if($name==='')return null;
        return $this->choice($name,$qty,$raw)+['mode'=>'all'];
    }

    private function choice(string $name,?int $qty,string $raw): array
    {
        $name=$this->cleanName($name);
        return [
            'name'=>$name,
            'name_norm'=>LacProfileParser::normalizeName($name),
            'quantity'=>$qty,
            'kind'=>$this->kind($name),
            'raw'=>$raw,
        ];
    }

    private function looksLikeInstruction(string $text): bool
    {
        $t=trim($text);
        if($t==='')return true;
        return (bool)preg_match(
            '/^(?:see|speak\s+to|talk\s+to|visit|consult|refer\s+to)\b|\b(?:for|about)\s+(?:the\s+)?prerequisites?\b/i',
            $t
        );
    }

    private function cleanName(string $name): string
    {
        $name=trim($name);
        $name=preg_replace('/^(?:trade|bring|requires?|required)\s+/iu','',$name)??$name;
        $name=preg_replace('/\s+to\s+[A-Z][^,;]+$/u','',$name)??$name;
        return trim($name," \t\n\r\0\x0B,.");
    }

    private function number(string $n): int
    {
        return (int)str_replace(',','',$n);
    }

    private function kind(string $name): string
    {
        $n=strtolower($name);
        foreach(['gil','bayld','plasm','gallimaufry','hallmarks','badges','segments','tokens','points','units','detritus'] as $needle){
            if(str_contains($n,$needle))return 'currency_or_progression_resource';
        }
        return 'item_or_resource';
    }
}
