<?php
declare(strict_types=1);

/**
 * Reconciles guide target details with structured item/system evidence.
 *
 * A guide is authoritative for the desired target, but a Path/Rank/stat target
 * becomes VERIFIED only when the concrete item was already assigned to a
 * source-backed progression system and that system/source can support it.
 */
final class TargetDetailReconciler
{
    /**
     * @param array<int,mixed> $details
     * @param array<string,mixed> $assumptions
     * @param array<int,array<string,mixed>> $augmentOptions
     * @param array<string,array<string,mixed>> $systems keyed by adapter key
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>
     */
    public function reconcile(
        array $details,
        array $assumptions,
        string $sourceName,
        string $canonicalName,
        array $augmentOptions,
        array $systems,
        array $records
    ): array {
        $paths=[];$ranks=[];$statGroups=[];$evidence=[];$reasons=[];$applied=[];
        foreach($details as $raw){
            $detail=trim((string)$raw);if($detail==='')continue;
            if(preg_match_all('/\bPath\s*[:=]?\s*([A-Z0-9]+)\b/i',$detail,$m)){
                foreach($m[1] as $value)$paths[strtoupper((string)$value)]=['value'=>strtoupper((string)$value),'source'=>'item_detail','raw'=>$detail];
                $detail=trim((string)preg_replace('/\bPath\s*[:=]?\s*[A-Z0-9]+\b/i',' ',$detail));
            }
            if(preg_match_all('/\b(?:Rank|R)\s*[:=]?\s*(\d+)\b/i',$detail,$m)){
                foreach($m[1] as $value)$ranks[(int)$value]=['value'=>(int)$value,'source'=>'item_detail','raw'=>(string)$raw];
                $detail=trim((string)preg_replace('/\b(?:Rank|R)\s*[:=]?\s*\d+\b/i',' ',$detail));
            }
            $detail=trim($detail," \t\n\r\0\x0B,;/|-+");
            if($detail!=='')$statGroups[]=['raw'=>$detail,'source'=>'item_detail'];
        }

        // The page-wide Odyssey/Nyame statement is applied only after the
        // concrete item has a verified Odyssey Gaol adapter.  The exact Nyame
        // family label is part of the guide statement; it never creates a chain.
        if(isset($systems['odyssey_gaol_augments'])){
            $isNyame=$this->isExactNyameTarget($sourceName,$canonicalName);
            if($isNyame && isset($assumptions['nyame_rank'])){
                $rank=(int)$assumptions['nyame_rank'];
                if(!$ranks)$ranks[$rank]=['value'=>$rank,'source'=>'guide_assumption','raw'=>'Nyame Rank '.$rank];
                $applied[]='nyame_rank';
            }elseif(!$ranks && isset($assumptions['odyssey_default_rank'])){
                $rank=(int)$assumptions['odyssey_default_rank'];
                $ranks[$rank]=['value'=>$rank,'source'=>'guide_assumption','raw'=>'Odyssey Rank '.$rank];
                $applied[]='odyssey_default_rank';
            }
            if($isNyame && !$paths && isset($assumptions['nyame_path'])){
                $path=strtoupper(trim((string)$assumptions['nyame_path']));
                if($path!=='')$paths[$path]=['value'=>$path,'source'=>'guide_assumption','raw'=>'Nyame Path '.$path];
                $applied[]='nyame_path';
            }
        }

        $hasTargets=(bool)($paths||$ranks||$statGroups);
        if(!$hasTargets){
            return [
                'has_targets'=>false,'status'=>'NOT_APPLICABLE','requirements'=>[],
                'evidence'=>[],'review_reasons'=>[],'applied_assumptions'=>[],
            ];
        }

        if(count($paths)>1)$reasons[]='Conflicting target paths are published for the same target row: '.implode(', ',array_keys($paths)).'.';
        if(count($ranks)>1)$reasons[]='Conflicting target ranks are published for the same target row: '.implode(', ',array_keys($ranks)).'.';

        $selectedPath=count($paths)===1?(string)array_key_first($paths):null;
        $selectedOptions=[];
        if($selectedPath!==null){
            foreach($augmentOptions as $option){
                if(strtoupper(trim((string)($option['path']??'')))===$selectedPath)$selectedOptions[]=$option;
            }
            if(!$selectedOptions){
                $reasons[]='Requested Path '.$selectedPath.' is not present in the structured augment options cached for this exact item.';
            }else{
                $evidence[]=['kind'=>'structured_path','path'=>$selectedPath,'matches'=>array_values($selectedOptions)];
            }
        }

        if(count($ranks)===1){
            $wanted=(int)array_key_first($ranks);$caps=[];
            foreach($systems as $key=>$system){
                $cap=(int)($system['target_rank']??0);
                if($cap>0)$caps[$key]=$cap;
                foreach(($system['rank_caps']??[]) as $quality=>$row){
                    $qualityCap=(int)($row['rank']??0);if($qualityCap>0)$caps[$key.':'.$quality]=$qualityCap;
                }
            }
            if(!$caps){
                $reasons[]='Requested Rank '.$wanted.' has no structured rank-cap evidence for the verified item system.';
            }elseif($wanted<0 || $wanted>max($caps)){
                $reasons[]='Requested Rank '.$wanted.' exceeds the structured maximum supported by the verified item system ('.max($caps).').';
            }else{
                $evidence[]=['kind'=>'structured_rank','rank'=>$wanted,'system_caps'=>$caps,'source'=>$ranks[$wanted]['source']];
            }
        }

        foreach($statGroups as $group){
            $requirements=$this->statRequirements((string)$group['raw']);
            if(!$requirements['requirements']){
                $reasons[]='Target augment detail could not be parsed into exact stat names: '.$group['raw'].'.';
                continue;
            }
            if($requirements['unknown']){
                $reasons[]='Target augment detail contains unsupported or ambiguous stat labels: '.implode(', ',$requirements['unknown']).'.';
                continue;
            }
            $match=$this->matchStatGroup(
                $requirements['requirements'],
                $selectedPath!==null?$selectedOptions:$augmentOptions,
                $systems,
                $records
            );
            if($match===null){
                $reasons[]='Target augment detail is not supported by one structured option/system record for this exact item: '.$group['raw'].'.';
                continue;
            }
            $evidence[]=['kind'=>'structured_augment_stats','raw'=>$group['raw'],'requirements'=>$requirements['requirements'],'match'=>$match];
        }

        return [
            'has_targets'=>true,
            'status'=>$reasons?'REVIEW_REQUIRED':'VERIFIED',
            'requirements'=>[
                'paths'=>array_values($paths),'ranks'=>array_values($ranks),'stat_groups'=>$statGroups,
            ],
            'evidence'=>$evidence,
            'review_reasons'=>array_values(array_unique($reasons)),
            'applied_assumptions'=>array_values(array_unique($applied)),
        ];
    }

    private function isExactNyameTarget(string $sourceName,string $canonicalName): bool
    {
        foreach([$sourceName,$canonicalName] as $name){
            if(preg_match('/^Nyame\s+[A-Za-z][A-Za-z .\'-]*$/u',trim($name)))return true;
        }
        return false;
    }

    /** @return array{requirements:array<int,array<string,mixed>>,unknown:array<int,string>} */
    private function statRequirements(string $value): array
    {
        $parts=preg_split('/\s*(?:,|\/|&|;)\s*/u',trim($value))?:[];$requirements=[];$unknown=[];
        foreach($parts as $part){
            $part=trim($part);if($part==='')continue;$amount=null;$percent=false;$label=$part;
            if(preg_match('/^(.*?)([+-]\s*\d+(?:\.\d+)?)\s*(%)?$/u',$part,$m)){
                $label=trim($m[1]);$amount=(float)str_replace(' ','',$m[2]);$percent=($m[3]??'')==='%';
            }
            $stat=$this->canonicalStat($label);
            if($stat===null){$unknown[]=$part;continue;}
            $requirements[]=['stat'=>$stat,'amount'=>$amount,'percent'=>$percent,'raw'=>$part];
        }
        return ['requirements'=>$requirements,'unknown'=>array_values(array_unique($unknown))];
    }

    private function canonicalStat(string $label): ?string
    {
        $n=strtolower(trim(preg_replace('/[^a-z0-9]+/i',' ',html_entity_decode($label,ENT_QUOTES|ENT_HTML5,'UTF-8'))??$label));
        $n=trim(preg_replace('/\s+/',' ',$n)??$n);
        $aliases=[
            'str'=>'str','strength'=>'str','dex'=>'dex','dexterity'=>'dex','vit'=>'vit','vitality'=>'vit','agi'=>'agi','agility'=>'agi',
            'int'=>'int','intelligence'=>'int','mnd'=>'mnd','mind'=>'mnd','chr'=>'chr','charisma'=>'chr',
            'acc'=>'accuracy','accuracy'=>'accuracy','atk'=>'attack','attack'=>'attack','macc'=>'magic_accuracy','magic acc'=>'magic_accuracy','magic accuracy'=>'magic_accuracy',
            'matk'=>'magic_attack','mab'=>'magic_attack','magic attack'=>'magic_attack','magic attack bonus'=>'magic_attack','mdmg'=>'magic_damage','magic damage'=>'magic_damage',
            'da'=>'double_attack','double attack'=>'double_attack','ta'=>'triple_attack','triple attack'=>'triple_attack','qa'=>'quadruple_attack','quadruple attack'=>'quadruple_attack',
            'wsd'=>'weapon_skill_damage','weapon skill damage'=>'weapon_skill_damage','stp'=>'store_tp','store tp'=>'store_tp','dw'=>'dual_wield','dual wield'=>'dual_wield',
            'fc'=>'fast_cast','fast cast'=>'fast_cast','crit rate'=>'critical_hit_rate','critical hit rate'=>'critical_hit_rate','crit damage'=>'critical_hit_damage','critical hit damage'=>'critical_hit_damage',
            'snapshot'=>'snapshot','sird'=>'spell_interruption_rate_down','spell interruption rate down'=>'spell_interruption_rate_down',
            'dt'=>'damage_taken','damage taken'=>'damage_taken','pdt'=>'physical_damage_taken','physical damage taken'=>'physical_damage_taken',
            'mdt'=>'magic_damage_taken','magic damage taken'=>'magic_damage_taken','pet'=>'pet','enmity'=>'enmity','haste'=>'haste',
        ];
        return $aliases[$n]??null;
    }

    /**
     * @param array<int,array<string,mixed>> $requirements
     * @param array<int,array<string,mixed>> $options
     * @param array<string,array<string,mixed>> $systems
     * @param array<int,array<string,mixed>> $records
     * @return array<string,mixed>|null
     */
    private function matchStatGroup(array $requirements,array $options,array $systems,array $records): ?array
    {
        foreach($options as $option){
            $text=(string)($option['details']??'');
            if($this->textSupportsAll($text,$requirements))return ['source'=>'augment_option','path'=>$option['path']??null,'details'=>$text,'source_title'=>$option['source_title']??''];
        }
        foreach($systems as $key=>$system){
            $supported=is_array($system['target_stat_families']??null)?$system['target_stat_families']:[];
            if(!$supported)continue;$ok=true;
            foreach($requirements as $requirement){
                $stat=(string)$requirement['stat'];$rule=$supported[$stat]??null;
                if(!is_array($rule)){$ok=false;break;}
                if($requirement['amount']!==null && isset($rule['max_value']) && abs((float)$requirement['amount'])>(float)$rule['max_value']){$ok=false;break;}
            }
            if($ok)return ['source'=>'system_stat_slots','system_key'=>$key,'rules'=>array_intersect_key($supported,array_flip(array_column($requirements,'stat')))];
        }
        foreach($records as $record){
            $section=strtolower(trim((string)($record['section']??'')));if(!str_contains($section,'augment'))continue;
            $fields=is_array($record['fields']??null)?$record['fields']:[];$text=implode("\n",array_map('strval',$fields));
            if($this->textSupportsAll($text,$requirements))return ['source'=>'structured_augment_record','section'=>$record['section']??'','source_title'=>$record['page_title']??''];
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $requirements */
    private function textSupportsAll(string $text,array $requirements): bool
    {
        $canonical=$this->canonicalAugmentText($text);
        foreach($requirements as $requirement){
            $stat=preg_quote((string)$requirement['stat'],'/');
            if(!preg_match('/\b'.$stat.'\b/',$canonical))return false;
            if($requirement['amount']!==null){
                $amount=preg_quote(rtrim(rtrim(number_format(abs((float)$requirement['amount']),2,'.',''),'0'),'.'),'/');
                if(!preg_match('/\b'.$stat.'\b\s*[+:=]?\s*[+-]?'.$amount.'\s*'.($requirement['percent']?'%':'%?').'/',$canonical))return false;
            }
        }
        return true;
    }

    private function canonicalAugmentText(string $text): string
    {
        $text=strtolower(html_entity_decode($text,ENT_QUOTES|ENT_HTML5,'UTF-8'));
        $replace=[
            '/weapon\s+skill\s+damage/' => 'weapon_skill_damage','/double\s+attack/' => 'double_attack','/triple\s+attack/' => 'triple_attack',
            '/quadruple\s+attack/' => 'quadruple_attack','/store\s+tp/' => 'store_tp','/dual\s+wield/' => 'dual_wield','/fast\s+cast/' => 'fast_cast',
            '/critical\s+hit\s+rate/' => 'critical_hit_rate','/critical\s+hit\s+damage/' => 'critical_hit_damage',
            '/magic\s+attack(?:\s+bonus)?/' => 'magic_attack','/mag\.?\s*atk\.?\s*bns\.?/' => 'magic_attack',
            '/magic\s+accuracy/' => 'magic_accuracy','/mag\.?\s*acc\.?/' => 'magic_accuracy','/magic\s+damage/' => 'magic_damage',
            '/spell\s+interruption\s+rate\s+down/' => 'spell_interruption_rate_down','/physical\s+damage\s+taken/' => 'physical_damage_taken',
            '/magic\s+damage\s+taken/' => 'magic_damage_taken','/damage\s+taken/' => 'damage_taken',
            '/\bwsd\b/' => 'weapon_skill_damage','/\bda\b/' => 'double_attack','/\bta\b/' => 'triple_attack','/\bqa\b/' => 'quadruple_attack',
            '/\bstp\b/' => 'store_tp','/\bdw\b/' => 'dual_wield','/\bfc\b/' => 'fast_cast','/\bmacc\b/' => 'magic_accuracy','/\bmab\b/' => 'magic_attack',
            '/\bacc(?:uracy)?\b/' => 'accuracy','/\batk(?:ack)?\b/' => 'attack',
        ];
        foreach($replace as $pattern=>$replacement)$text=(string)preg_replace($pattern,$replacement,$text);
        $text=(string)preg_replace('/[^a-z0-9_+%:=.-]+/',' ',$text);
        return trim((string)preg_replace('/\s+/',' ',$text));
    }
}
