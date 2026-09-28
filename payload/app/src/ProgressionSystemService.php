<?php
declare(strict_types=1);

/**
 * Resolves structured BG-Wiki progression actions into reusable FFXI system adapters.
 *
 * Design rules:
 * - match recurring game systems, never one specific item name;
 * - only activate a rule when structured fields/source text identify the system;
 * - fixed-material workflows keep their ordinary requirement parsing;
 * - rank/RP/exchange systems may supersede terse item-page material cells;
 * - live Path/Rank/Trial state is never guessed.
 */
final class ProgressionSystemService
{
    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    public function resolveEdge(array $edge, array $holdings=[], array $liveAugment=[]): ?array
    {
        $fields=is_array($edge['fields']??null)?$edge['fields']:[];
        $actor=$this->norm((string)($edge['actor']??$this->field($fields,['Augmented Via','NPC Name','NPC','Name','Upgrade NPC'])));
        $required=trim((string)($edge['requirements']??$this->field($fields,['Required Items','Requirements','Requirement','Cost','Currency','Materials','Notes'])));
        $requiredNorm=$this->norm($required);
        $sourceNorm=$this->norm((string)($edge['source_title']??''));
        $sectionNorm=$this->norm((string)($edge['section']??''));
        $zoneNorm=$this->norm((string)($edge['zone']??$this->field($fields,['Zone','Area','Location'])));
        $itemSuperiorLevel=(int)($edge['item_superior_level']??0);

        // Trial of the Magians.  We require an explicit structured trial number
        // plus a Base/Reward row; this prevents an AugTrial value in LAC from
        // being mistaken for a complete route when the actual trial source is absent.
        $trialNumber=$this->field($fields,['Trial Number','Trial']);
        $trialBase=$this->field($fields,['Base','Base Item']);
        $trialReward=$this->field($fields,['Reward','Result']);
        if($trialNumber!=='' && $trialBase!=='' && $trialReward!==''){
            return $this->trialOfMagians($edge,$trialNumber,$trialBase,$trialReward);
        }

        // Reforged Artifact/Relic +4 via the 2025 Limbus furnaces.  The set
        // tables encode the currency in a dedicated "Required ... Units" column,
        // so the actor may be absent on transcluded rows; either the furnace or the
        // structured units field is sufficient to identify the system family.
        $temenosUnits=$this->field($fields,['Required Temenos Units']);
        $apollyonUnits=$this->field($fields,['Required Apollyon Units']);
        $sourceTitleNorm=$this->norm((string)($edge['source_title']??''));
        $furnaceActor=str_contains($actor,'temenos furnace') || str_contains($actor,'apollyon furnace');

        // 2025-09 / 2026 Limbus crafted/reforged equipment reuses the same furnaces
        // as +4 armor, so furnace name alone is no longer a safe +4 discriminator.
        // Jewel/Matter + units identifies the modern Rank-30 reinforcement family.
        $modernLimbusSignature=$furnaceActor && (
            str_contains($requiredNorm,'jewel of') || str_contains($requiredNorm,'alabaster matter') ||
            str_contains($requiredNorm,'murky matter') || str_contains($requiredNorm,'units from either zone')
        );
        if($modernLimbusSignature){
            return $this->applyLiveState($this->limbusModernReinforcement($edge,$holdings),$liveAugment);
        }

        // Reforged Artifact/Relic +4 uses dedicated structured unit columns on the
        // category tables. Individual +4 pages may instead say "See ... Units";
        // in that case the explicit +4 source title plus the matching furnace is
        // sufficient. This prevents 2025/26 craft gear from being misclassified.
        if($temenosUnits!=='' || (str_contains($actor,'temenos furnace') && str_contains($sourceTitleNorm,'+4') && str_contains($requiredNorm,'temenos unit'))){
            return $this->limbusPlus4($edge,'relic',$temenosUnits!==''?$temenosUnits:$required);
        }
        if($apollyonUnits!=='' || (str_contains($actor,'apollyon furnace') && str_contains($sourceTitleNorm,'+4') && str_contains($requiredNorm,'apollyon unit'))){
            return $this->limbusPlus4($edge,'artifact',$apollyonUnits!==''?$apollyonUnits:$required);
        }

        // Existing / recurring augment adapters.
        if($actor==='oboro' && str_contains($requiredNorm,'s. astral detritus') && str_contains($requiredNorm,'m. astral detritus')){
            return $this->applyLiveState($this->ultimateWeaponAugments($edge,$holdings),$liveAugment);
        }
        // Dynamis-Divergence crafted weapons and JSE necks both use Heroism RP,
        // but they are different progression families. Weapon pages explicitly
        // reference the Dynamis Divergence Weapon Augments system and may have
        // three irreversible paths. Detect that signature before the JSE-neck rule.
        if($actor==='oboro' && (str_contains($requiredNorm,'heroism crystal') || str_contains($requiredNorm,'heroism aggregate'))
            && (str_contains($requiredNorm,'dynamis divergence weapon') || $itemSuperiorLevel>=4)){
            return $this->applyLiveState($this->dynamisDivergenceWeaponAugments($edge,$holdings),$liveAugment);
        }
        // JSE necks (+0/+1/+2) use Heroism RP at Oboro / Dynamis-D.
        if($actor==='oboro' && (str_contains($requiredNorm,'heroism crystal') || str_contains($requiredNorm,'heroism aggregate'))){
            return $this->applyLiveState($this->jseNeckAugments($edge,$holdings),$liveAugment);
        }
        if($actor==='nolan' && str_contains($requiredNorm,'eschalixir')){
            return $this->applyLiveState($this->nolanMezzotint($edge,$holdings),$liveAugment);
        }
        // Reisenjima random Arcane Glyptics via Oseem.  There is no finite
        // deterministic material total: each roll may be kept or rejected.
        if($actor==='oseem' && (str_contains($requiredNorm,'pellucid') || str_contains($requiredNorm,'fern') || str_contains($requiredNorm,'taupe') || str_contains($requiredNorm,'dark matter'))){
            return $this->oseemArcaneGlyptics($edge,$holdings,$liveAugment);
        }

        // Ambuscade JSE cape augmentation. Gorpa-Masorpa is authoritative enough
        // together with Abdhaljs material families to avoid classifying normal gear.
        if(($actor==='gorpa-masorpa' || str_contains($sourceNorm,'gorpa-masorpa')) && str_contains($requiredNorm,'abdhaljs')){
            return $this->ambuscadeCape($edge,$holdings);
        }

        // Unity +1 gear augmented through Odyssey. The material identifies the
        // Sheol wing and all three types share the same 5-RP / Rank-15 table.
        if((str_contains($actor,'unity') || str_contains($sourceNorm,'unity'))
            && str_contains($requiredNorm,'lustreless') && str_contains($requiredNorm,'accolade')){
            return $this->applyLiveState($this->unityOdyssey($edge,$holdings),$liveAugment);
        }

        // Odyssey Gaol equipment: the structured item page explicitly says Pilgrim
        // Moogle + Odyssey on an augment action. This is distinct from acquisition.
        if($actor==='pilgrim moogle' && str_contains($sectionNorm,'augment')){
            return $this->applyLiveState($this->odysseyGaol($edge),$liveAugment);
        }

        // Reforged Empyrean +2/+3 via Sortie / Ruspix.
        if($actor==='ruspix' || str_contains($requiredNorm,'gallimaufry') || str_contains($requiredNorm,"ra'kaz")){
            return $this->workflow(
                'reforged_empyrean_sortie','Reforged Empyrean / Sortie','fixed_upgrade',
                'Ruspix','Leafallia (H-8)','Sortie',false,
                [
                    'Empyrean +2 unlock: enter a Sortie instance on the job.',
                    'Empyrean +3 unlock: reach a basement area via a Bitzer device on the job.',
                    'The Light Within and Scintillating Rhapsody are account/mission prerequisites for the reforging system.',
                ],
                'Category:Reforged Empyrean Armor +3 - FFXI Wiki',
                'https://www.bg-wiki.com/ffxi/Category:Reforged_Empyrean_Armor_%2B3',
                $edge
            );
        }

        // Reforged Artifact +2/+3 via Omen / Coelestrox. Job cards are the most
        // reliable row-level signature on item pages; Coelestrox is authoritative.
        if($actor==='coelestrox' || preg_match('/\bP\.\s*[A-Z]{2,4}\s+Card\b/i',$required)
            || (str_contains($requiredNorm,'card') && preg_match('/\b(?:kin|gin|kei|fu|kyou)\'?s scale\b/i',$required))){
            return $this->workflow(
                'reforged_artifact_omen','Reforged Artifact / Omen','fixed_upgrade',
                'Coelestrox','Reisenjima','Omen',false,
                [
                    'The +2 tier requires access to Omen; the source row remains authoritative for exact cards/materials.',
                    'The +3 tier has an additional Omen unlock requirement (boss/mid-boss progression or Distorted fragment).',
                    'Bead/time-skip costs are system metadata and should not replace the item-specific material row.',
                ],
                'Category:Reforged Artifact Armor +3 - FFXI Wiki',
                'https://www.bg-wiki.com/ffxi/Category:Reforged_Artifact_Armor_%2B3',
                $edge
            );
        }

        // Reforged Relic +2/+3 via Dynamis-Divergence / Aurix. The +3 tables use
        // slot shards + void items; actor Aurix is authoritative when present.
        if($actor==='aurix' || ((str_contains($requiredNorm,'shard') || str_contains($requiredNorm,'headshard') || str_contains($requiredNorm,'torsoshard'))
            && str_contains($requiredNorm,'void'))){
            return $this->workflow(
                'reforged_relic_divergence','Reforged Relic / Dynamis-Divergence','fixed_upgrade',
                'Aurix','Ru\'Lude Gardens (G-8)','Dynamis - Divergence',false,
                [
                    'Relic +3 slot access is unlocked per job/slot through Dynamis - Divergence progression.',
                    'A refined/dedicated/etc. job shard can represent full five-slot unlock status, but per-slot unlocks remain valid.',
                    'Exact shard/void/Vagary materials stay item-specific and are parsed from the structured source row.',
                ],
                'Category:Reforged Relic Armor +3 - FFXI Wiki',
                'https://www.bg-wiki.com/ffxi/Category:Reforged_Relic_Armor_%2B3',
                $edge
            );
        }

        // Initial 109/119 reforging for AF/Relic/Empyrean through Monisette.
        if($actor==='monisette' || str_contains($requiredNorm,"rem's tale") || str_contains($requiredNorm,'rem’s tale')){
            return $this->workflow(
                'reforged_armor_rem_tales','Reforged Armor / Rem\'s Tales','fixed_upgrade',
                'Monisette','Port Jeuno (I-8)','Reforged Armor',false,
                ['Exact chapters and slot/job materials remain item-specific and come from the structured BG-Wiki row.'],
                'Category:Armor - FFXI Wiki','https://www.bg-wiki.com/ffxi/Category:Armor',$edge
            );
        }

        return null;
    }

    /**
     * Recognize a system directly from LAC target metadata even when no item-page
     * progression edge has been cached yet. These are adapter hints, not proof that
     * the full route/material data is already available.
     *
     * @param array<int,array<string,mixed>> $usage
     * @return array<int,array<string,mixed>>
     */
    public function targetHints(string $itemName,array $usage,array $records=[],array $itemMeta=[]): array
    {
        $trials=[];$paths=[];$ranks=[];$augmentStrings=[];
        foreach($usage as $u){
            $trial=trim((string)($u['aug_trial']??''));if($trial!=='')$trials[$trial]=true;
            $path=strtoupper(trim((string)($u['aug_path']??'')));if($path!=='')$paths[$path]=true;
            $rank=trim((string)($u['aug_rank']??''));if($rank!=='')$ranks[$rank]=true;
            $aug=json_decode((string)($u['augment_strings_json']??'[]'),true);
            if(is_array($aug))foreach($aug as $a){$a=trim((string)$a);if($a!=='')$augmentStrings[$a]=true;}
        }
        $hints=[];
        $superiorLevel=(int)($itemMeta['superior_level']??0);

        // Target-aware direct-page system signatures.  These deliberately do not
        // depend on ProgressionChainService keeping a self-action in the current
        // UI plan.  The structured item page plus the LAC Path target is enough to
        // identify the recurring system family, while live Path/Rank remains deferred.
        if($paths && $this->recordsHaveAugmentActor($records,'Pilgrim Moogle')){
            $hints[]=[
                'key'=>'odyssey_gaol_augments','label'=>'Odyssey Sheol: Gaol Augments',
                'mode'=>'reinforcement_points','data_complete'=>true,'action_required'=>true,
                'supersedes_requirements'=>true,'item_name'=>$itemName,'target_paths'=>array_keys($paths),
                'notes'=>[
                    'The structured item page identifies Pilgrim Moogle as the augment source; this is the Odyssey Gaol rank system.',
                    'The live item read will verify the physical Path/Rank state; it is not inferred from ownership.',
                ],
                'source_title'=>'Category:Odyssey - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Odyssey',
                'confidence'=>'structured_item_source_plus_lac_target',
            ];
        }
        if($paths && $superiorLevel>=4 && $this->recordsHaveAugmentActor($records,'Oboro')){
            $hints[]=[
                'key'=>'dynamis_divergence_weapon_augments','label'=>'Dynamis-Divergence Weapon Augments',
                'mode'=>'reinforcement_points','data_complete'=>true,'action_required'=>true,
                'supersedes_requirements'=>true,'item_name'=>$itemName,'target_paths'=>array_keys($paths),
                'notes'=>[
                    'The item is SU4/SU5 and its structured augment source is Oboro, identifying the Dynamis-D crafted weapon family.',
                    'The quality tier determines the system rank cap; LAC Path alone does not invent a mandatory rank.',
                ],
                'source_title'=>'Dynamis Divergence Weapon Augments - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Dynamis_Divergence_Weapon_Augments',
                'confidence'=>'resource_metadata_plus_structured_item_source_plus_lac_target',
            ];
        }

        // Reforged Relic +3 job-effect augments are embedded in the item itself.
        // LuAshitacast exposes them as AugTrial, but obtaining the correct +3 item
        // already supplies that fixed job-effect augment; the AugTrial marker itself
        // is therefore NOT a Magian progression task.  Since 2025, every Reforged
        // Relic +3 piece also has a separate +4 upgrade through the Temenos Furnace.
        if($trials && $this->recordsLookLikeReforgedRelicPlus3($itemName,$records)){
            $hints[]=[
                'key'=>'reforged_relic_embedded_job_augment',
                'label'=>'Reforged Relic +3 embedded job augment',
                'mode'=>'fixed_embedded_augment','data_complete'=>true,'action_required'=>false,
                'supersedes_requirements'=>false,'item_name'=>$itemName,'target_trials'=>array_keys($trials),
                'notes'=>[
                    'The AugTrial value identifies the fixed job-effect augment carried by this Reforged Relic +3 item.',
                    'No separate Trial of the Magians action is required after the correct +3 item is obtained.',
                    'The consolidated live read may still confirm the physical item Extra/AugTrial field, but it does not create trial work.',
                ],
                'source_title'=>'Reforged Relic Armor +3 - FFXI Wiki',
                'source_url'=>'https://www.bg-wiki.com/ffxi/Category:Reforged_Relic_Armor_%2B3',
                'confidence'=>'structured_item_family_plus_lac_target',
            ];
            $advisory=$this->limbusPlus4TargetHint($itemName,$usage,$records,'relic');
            if($advisory!==null)$hints[]=$advisory;
            return $hints;
        }

        // Reforged Artifact +3 received the parallel +4 path through Apollyon.
        // Artifact pieces do not always carry AugTrial in LAC, but when an encoded
        // target is present we can still separate the current fixed +3 state from
        // the optional +4 upgrade instead of calling it a generic trial.
        if($trials && $this->recordsLookLikeReforgedArtifactPlus3($itemName,$records)){
            $hints[]=[
                'key'=>'reforged_artifact_plus3_encoded_state',
                'label'=>'Reforged Artifact +3 encoded job state',
                'mode'=>'fixed_embedded_augment','data_complete'=>true,'action_required'=>false,
                'supersedes_requirements'=>false,'item_name'=>$itemName,'target_trials'=>array_keys($trials),
                'notes'=>['The encoded LAC trial marker belongs to the already-forged +3 item state; it is not interpreted as a separate Magian route.'],
                'source_title'=>'Reforged Artifact Armor +3 - FFXI Wiki',
                'source_url'=>'https://www.bg-wiki.com/ffxi/Category:Reforged_Artifact_Armor_%2B3',
                'confidence'=>'structured_item_family_plus_lac_target',
            ];
            $advisory=$this->limbusPlus4TargetHint($itemName,$usage,$records,'artifact');
            if($advisory!==null)$hints[]=$advisory;
            return $hints;
        }

        // Sortie job earrings are generated with random augment values when the
        // corresponding ??? Earring item is used.  Existing copies must be live-read
        // and compared with the exact LAC augment strings; if they miss the target,
        // the progression is to obtain/open another eligible Sortie earring item.
        if($augmentStrings && $this->recordsLookLikeSortieJobEarring($records)){
            $hints[]=[
                'key'=>'sortie_jse_earring_random_augments','label'=>'Sortie JSE Earring random augments',
                'mode'=>'random_fixed_on_reveal','data_complete'=>true,'action_required'=>true,
                'supersedes_requirements'=>false,'item_name'=>$itemName,'target_augments'=>array_keys($augmentStrings),
                'notes'=>[
                    'Sortie JSE earrings are always augmented when revealed from the corresponding ??? Earring item.',
                    'Augment potency is random within the tier-specific ranges, so no finite number of cases can be promised.',
                    'Live augment strings determine whether the owned copy satisfies the LAC target.',
                ],
                'source_title'=>'Category:Sortie Rewards - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Sortie_Rewards',
                'confidence'=>'structured_item_family_plus_lac_target',
            ];
        }

        // Moonshade is a mission reward with selectable augment pools, not an RP or
        // random-stone system.  Detect the item family plus explicit LAC augment
        // strings; exact current choices wait for the live item read.
        if($augmentStrings && LacProfileParser::normalizeName($itemName)===LacProfileParser::normalizeName('Moonshade Earring')){
            $hints[]=[
                'key'=>'moonshade_mission_reward_augments','label'=>'Moonshade mission reward augment choice',
                'mode'=>'mission_reward_choice','data_complete'=>true,'action_required'=>true,
                'supersedes_requirements'=>false,'item_name'=>$itemName,'target_augments'=>array_keys($augmentStrings),
                'notes'=>[
                    'Moonshade Earring is received as an augmented Wings of the Goddess mission reward.',
                    'One augment is selected from each reward augment set; the requested LAC strings are treated as the desired combination.',
                    'The live item read verifies the current combination; Gear Center does not infer it from ownership alone.',
                ],
                'source_title'=>'Lest We Forget - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Lest_We_Forget',
                'confidence'=>'known_item_system_plus_lac_target',
            ];
        }

        // Any remaining AugTrial stays conservative: the value by itself proves an
        // encoded trial state, not the actual route or objective.
        if($trials){
            $hints[]=[
                'key'=>'trial_encoded_augment','label'=>'Trial / AugTrial progression','mode'=>'trial',
                'data_complete'=>false,'action_required'=>true,'supersedes_requirements'=>false,
                'item_name'=>$itemName,'target_trials'=>array_keys($trials),
                'notes'=>[
                    'LuAshitacast requires a concrete AugTrial value for this item.',
                    'The adapter recognizes the trial-encoded state, but the exact trial route must come from structured Trial/Reforged source data before it becomes fully actionable.',
                ],
                'source_title'=>'Trial of the Magians / item-specific progression',
                'source_url'=>'https://www.bg-wiki.com/ffxi/Category:Trial_of_the_Magians',
                'confidence'=>'lac_target_system_hint',
            ];
        }
        return $hints;
    }

    /** @param array<int,array<string,mixed>> $records */
    private function recordsHaveAugmentActor(array $records,string $actorName): bool
    {
        $needle=$this->norm($actorName);
        foreach($records as $record){
            $section=$this->norm((string)($record['section']??''));
            if(!str_contains($section,'augment'))continue;
            $fields=is_array($record['fields']??null)?$record['fields']:[];
            $actor=$this->norm($this->field($fields,['Augmented Via','NPC Name','NPC','Name','Upgrade NPC','Source']));
            if($actor!=='' && ($actor===$needle || str_contains($actor,$needle) || str_contains($needle,$actor)))return true;
        }
        return false;
    }

    /** @param array<string,mixed> $edge */
    private function trialOfMagians(array $edge,string $trialNumber,string $base,string $reward): array
    {
        $fields=is_array($edge['fields']??null)?$edge['fields']:[];
        $objective=$this->field($fields,['Upgrade Item','Objective','Requirement','Requirements']);
        return [
            'key'=>'trial_of_magians','label'=>'Trial of the Magians','mode'=>'trial',
            'data_complete'=>true,'supersedes_requirements'=>false,
            'npc'=>'Magian Moogle (Green)','location'=>"Ru'Lude Gardens (H-5)",'content'=>'Trial of the Magians',
            'target_rank'=>null,'total_points'=>null,'point_label'=>'',
            'trial_number'=>$trialNumber,'base_item'=>$base,'reward_item'=>$reward,'objective'=>$objective,
            'accepted_resources'=>[],'conditional_costs'=>[],
            'notes'=>array_values(array_filter([
                'Structured BG-Wiki trial row resolved; the trial number is treated as authoritative for this route.',
                $objective!=='' ? 'Complete/obtain the row-specific objective before turning in the trial.' : null,
                'Live AugTrial will later verify the physical item state; it is not guessed offline.',
            ])),
            'source_title'=>(string)($edge['source_title']??'Trial of the Magians - FFXI Wiki'),
            'source_url'=>(string)($edge['source_url']??'https://www.bg-wiki.com/ffxi/Category:Trial_of_the_Magians'),
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function ultimateWeaponAugments(array $edge,array $holdings): array
    {
        $accepted=[
            $this->resourceChoice('S. Astral Detritus',10,$holdings),
            $this->resourceChoice('M. Astral Detritus',50,$holdings),
        ];
        return [
            'key'=>'ultimate_weapon_augments','label'=>'Ultimate Weapon Augments','mode'=>'reinforcement_points',
            'data_complete'=>true,'supersedes_requirements'=>true,
            'npc'=>'Oboro','location'=>'Port Jeuno (E-6)','content'=>'Ultimate Weapon Augments',
            'target_rank'=>15,'total_points'=>5960,
            'rank_totals'=>[1=>10,2=>60,3=>140,4=>260,5=>430,6=>650,7=>930,8=>1270,9=>1680,10=>2160,11=>2720,12=>3370,13=>4120,14=>4980,15=>5960],
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>$accepted,
            'conditional_costs'=>[['name'=>'S. Astral Detritus','quantity'=>1,'condition'=>'initial_activation_only','note'=>'The first augment activation trade requires at least one S. Astral Detritus; it contributes to the RP total.']],
            'notes'=>['Rank 15 is the maximum.','S. Astral Detritus grants 10 RP each; M. Astral Detritus grants 50 RP each.','The full Rank-15 target is 5,960 RP. Remaining RP depends on the live item rank/progress and is not inferred offline.'],
            'source_title'=>'BGWiki:Ultimate Weapon Augments','source_url'=>'https://www.bg-wiki.com/ffxi/BGWiki:Ultimate_Weapon_Augments',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function nolanMezzotint(array $edge,array $holdings): array
    {
        return [
            'key'=>'nolan_escha_mezzotint','label'=>'Nolan Escha Mezzotint','mode'=>'reinforcement_points',
            'data_complete'=>true,'supersedes_requirements'=>true,
            'npc'=>'Nolan','location'=>'Norg (I-7)','content'=>'Escha / Geas Fete',
            'target_rank'=>15,'total_points'=>5980,
            'rank_totals'=>[1=>30,2=>80,3=>160,4=>280,5=>450,6=>670,7=>950,8=>1290,9=>1700,10=>2180,11=>2740,12=>3390,13=>4140,14=>5000,15=>5980],
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>[
                $this->resourceChoice('Eschalixir',10,$holdings),$this->resourceChoice('Eschalixir +1',50,$holdings),$this->resourceChoice('Eschalixir +2',2000,$holdings),
            ],
            'conditional_costs'=>[
                ['name'=>'Escha Silt','quantity'=>100,'condition'=>'initial_path_activation_only','note'=>'Initial path activation costs 100 Escha Silt.'],
                ['name'=>'Escha Silt','quantity'=>3000,'condition'=>'path_change_only','note'=>'Changing an already selected path costs 3,000 Escha Silt and does not reset rank progress.'],
                ['name'=>'Escha Silt','quantity'=>null,'condition'=>'per_eschalixir_trade','note'=>'Each Eschalixir application trade costs 1 Escha Silt; transaction count is user-dependent and is not guessed.'],
            ],
            'notes'=>['Rank 15 is the maximum for this Escha/Nolan system.','Eschalixir grants 10 RP, +1 grants 50 RP, and +2 grants 2,000 RP.','The full Rank-15 target is 5,980 RP. Remaining RP depends on the live item rank/progress and is not inferred offline.'],
            'source_title'=>'Nolan - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Escha_Rewards/Mezzotinting',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function unityOdyssey(array $edge,array $holdings): array
    {
        $required=$this->norm((string)($edge['requirements']??''));
        $material='Lustreless Scale';$wing='Sheol A';
        if(str_contains($required,'lustreless hide')){$material='Lustreless Hide';$wing='Sheol B';}
        elseif(str_contains($required,'lustreless wing')){$material='Lustreless Wing';$wing='Sheol C';}
        return [
            'key'=>'unity_odyssey_augments','label'=>'Unity Rewards → Odyssey Augments','mode'=>'reinforcement_points',
            'data_complete'=>true,'supersedes_requirements'=>true,
            'npc'=>'Unity NPCs','location'=>'Unity NPC locations','content'=>'Odyssey '.$wing,
            'target_rank'=>15,'total_points'=>5955,
            'rank_totals'=>[1=>5,2=>55,3=>135,4=>255,5=>425,6=>645,7=>925,8=>1265,9=>1675,10=>2155,11=>2715,12=>3365,13=>4115,14=>4975,15=>5955],
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>[$this->resourceChoice($material,5,$holdings)],
            'conditional_costs'=>[
                ['name'=>'Unity Accolades','quantity'=>30000,'condition'=>'initial_activation_only','note'=>'Starting the Odyssey augment requires 30,000 Unity Accolades.'],
                ['name'=>$material,'quantity'=>1,'condition'=>'initial_activation_only','note'=>'The initial Lustreless item is included in the 1,191 total items for Rank 15.'],
            ],
            'unlock_requirements'=>['Complete '.$wing.' by reaching its exit to unlock this Lustreless augment family.'],
            'notes'=>['Each '.$material.' is worth 5 RP.','1,191 corresponding Lustreless items total 5,955 RP through Rank 15.'],
            'source_title'=>'Category:Unity Rewards - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Unity_Rewards',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge */
    private function odysseyGaol(array $edge): array
    {
        return [
            'key'=>'odyssey_gaol_augments','label'=>'Odyssey Sheol: Gaol Augments','mode'=>'reinforcement_points',
            'data_complete'=>true,'supersedes_requirements'=>true,
            'npc'=>'Pilgrim Moogle','location'=>'Rabao (G-6)','content'=>'Odyssey Sheol: Gaol',
            'target_rank'=>30,'total_points'=>44020,
            'rank_totals'=>[1=>0,2=>50,3=>130,4=>250,5=>420,6=>640,7=>920,8=>1260,9=>1670,10=>2150,11=>2710,12=>3360,13=>4110,14=>4970,15=>5950,16=>7060,17=>8310,18=>9720,19=>11300,20=>13060,21=>15020,22=>17190,23=>19590,24=>22240,25=>25150,26=>28330,27=>31790,28=>35550,29=>39620,30=>44020],
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>[],
            'unlock_requirements'=>[
                'Defeat the corresponding NM above V+0 to unlock augmentation through Rank 15.',
                'V+15 unlocks Rank 20; V+20 unlocks Rank 25; V+25 unlocks Rank 30.',
            ],
            'notes'=>['RP is earned for the corresponding Gaol NM and stored per NM; it is not a bag material.','The full Rank-30 allocation is 44,020 RP. Remaining RP waits for the live rank/progress state.'],
            'source_title'=>'Category:Odyssey - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Odyssey',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function ambuscadeCape(array $edge,array $holdings): array
    {
        $materials=[
            ['name'=>'Abdhaljs Thread','max_units'=>20,'role'=>'primary stat / HP/MP family'],
            ['name'=>'Abdhaljs Dust','max_units'=>20,'role'=>'accuracy/attack/magic/evasion family'],
            ['name'=>'Abdhaljs Sap','max_units'=>10,'role'=>'WSD/DA/STP/DW/FC/etc. family'],
            ['name'=>'Abdhaljs Dye','max_units'=>10,'role'=>'secondary stat / accuracy family'],
            ['name'=>'Abdhaljs Resin','max_units'=>5,'role'=>'defensive/resistance family'],
        ];
        foreach($materials as &$m){$h=$holdings[LacProfileParser::normalizeName($m['name'])]??null;$m['owned_quantity']=$h!==null?(int)$h['quantity']:null;}unset($m);
        return [
            'key'=>'ambuscade_jse_cape','label'=>'Ambuscade JSE Cape Augmentation','mode'=>'augment_material_slots',
            'data_complete'=>true,'supersedes_requirements'=>true,
            'npc'=>'Gorpa-Masorpa','location'=>'Mhaura (G-9)','content'=>'Ambuscade',
            'target_rank'=>null,'total_points'=>null,'point_label'=>'',
            'accepted_resources'=>[],'material_families'=>$materials,
            // Canonical stat labels accepted by TargetDetailReconciler.  These
            // are source-backed augment-slot capabilities, not guessed values
            // for a physical cape.  Live comparison still decides completion.
            'target_stat_families'=>[
                'str'=>['family'=>'Thread + Dye','max_value'=>30],'dex'=>['family'=>'Thread + Dye','max_value'=>30],
                'vit'=>['family'=>'Thread + Dye','max_value'=>30],'agi'=>['family'=>'Thread + Dye','max_value'=>30],
                'int'=>['family'=>'Thread + Dye','max_value'=>30],'mnd'=>['family'=>'Thread + Dye','max_value'=>30],
                'chr'=>['family'=>'Thread + Dye','max_value'=>30],
                'accuracy'=>['family'=>'Dust / Dye','max_value'=>20],'attack'=>['family'=>'Dust','max_value'=>20],
                'magic_accuracy'=>['family'=>'Dust / Dye','max_value'=>20],'magic_attack'=>['family'=>'Dust','max_value'=>20],
                'magic_damage'=>['family'=>'Dust','max_value'=>20],
                'weapon_skill_damage'=>['family'=>'Sap','max_value'=>10],'double_attack'=>['family'=>'Sap','max_value'=>10],
                'triple_attack'=>['family'=>'Sap','max_value'=>10],'store_tp'=>['family'=>'Sap','max_value'=>10],
                'dual_wield'=>['family'=>'Sap','max_value'=>10],'fast_cast'=>['family'=>'Sap','max_value'=>10],
                'critical_hit_rate'=>['family'=>'Sap','max_value'=>10],'critical_hit_damage'=>['family'=>'Sap','max_value'=>10],
                'snapshot'=>['family'=>'Sap','max_value'=>10],'spell_interruption_rate_down'=>['family'=>'Sap','max_value'=>10],
                'damage_taken'=>['family'=>'Resin','max_value'=>5],'physical_damage_taken'=>['family'=>'Resin','max_value'=>5],
                'magic_damage_taken'=>['family'=>'Resin','max_value'=>5],'pet'=>['family'=>'Sap / Resin','max_value'=>10],
                'enmity'=>['family'=>'Sap / Resin','max_value'=>10],'haste'=>['family'=>'Sap','max_value'=>10],
            ],
            'conditional_costs'=>[['name'=>'Abdhaljs Needle','quantity'=>1,'condition'=>'optional_path_change','note'=>'Optional: changes an augment while retaining potency for supported paths.']],
            'notes'=>['Each material family controls a separate augment slot.','Maximum unit counts are system maxima; the exact amount still required must be derived from the live/current augment versus the LAC target.'],
            'source_title'=>'Gorpa-Masorpa - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Gorpa-Masorpa',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }


    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function dynamisDivergenceWeaponAugments(array $edge,array $holdings): array
    {
        return [
            'key'=>'dynamis_divergence_weapon_augments','label'=>'Dynamis-Divergence Weapon Augments','mode'=>'reinforcement_points',
            'data_complete'=>true,'action_required'=>true,'supersedes_requirements'=>true,
            'npc'=>'Oboro','location'=>'Port Jeuno (E-6)','content'=>'Dynamis - Divergence',
            // Quality determines the actual cap: NQ R15, HQ1 R20, HQ2+ R25.
            // Do not guess the user's mandatory rank when LAC only specifies Path.
            'target_rank'=>null,'target_rank_basis'=>'quality_dependent_system_reference','total_points'=>null,
            'rank_caps'=>[
                'NQ'=>['rank'=>15,'total_points'=>5950],
                'HQ1'=>['rank'=>20,'total_points'=>13060],
                'HQ2_PLUS'=>['rank'=>25,'total_points'=>25150],
            ],
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>[$this->resourceChoice('Heroism Crystal',10,$holdings),$this->resourceChoice('Heroism Aggregate',50,$holdings)],
            'conditional_costs'=>[],
            'unlock_requirements'=>['Enter any Dynamis - Divergence area at least once before beginning the Oboro augment process.'],
            'notes'=>[
                'Dynamis-D crafted weapons have three augment paths; once a weapon path is chosen it cannot be changed on that physical weapon.',
                'NQ weapons cap at Rank 15 / 5,950 RP, HQ1 at Rank 20 / 13,060 RP, and HQ2+ at Rank 25 / 25,150 RP.',
                'Heroism Crystal grants 10 RP and Heroism Aggregate grants 50 RP; RP can also be earned by defeating Dynamis-D foes while the item is equipped.',
                'If LAC specifies only a Path, the quality-specific maximum rank is reference context rather than an inferred mandatory target.',
            ],
            'source_title'=>'Dynamis Divergence Weapon Augments - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Dynamis_Divergence_Weapon_Augments',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function jseNeckAugments(array $edge,array $holdings): array
    {
        $title=(string)($edge['source_title']??'');
        $maxRank=15;$total=5950;
        if(preg_match('/\+2\b/',$title)){$maxRank=25;$total=25150;}
        elseif(preg_match('/\+1\b/',$title)){$maxRank=20;$total=13060;}
        $allTotals=[1=>0,2=>50,3=>130,4=>250,5=>420,6=>640,7=>920,8=>1260,9=>1670,10=>2150,11=>2710,12=>3360,13=>4110,14=>4970,15=>5950,16=>7060,17=>8310,18=>9720,19=>11300,20=>13060,21=>15020,22=>17190,23=>19590,24=>22240,25=>25150];
        $rankTotals=array_filter($allTotals,static fn($v,$k)=>$k<=$maxRank,ARRAY_FILTER_USE_BOTH);
        return [
            'key'=>'jse_neck_oboro_augments','label'=>'JSE Neck Oboro / Dynamis-D Augments','mode'=>'reinforcement_points',
            'data_complete'=>true,'action_required'=>true,'supersedes_requirements'=>true,
            'npc'=>'Oboro','location'=>'Port Jeuno (E-6)','content'=>'JSE Necks / Dynamis - Divergence',
            'target_rank'=>$maxRank,'target_rank_basis'=>'system_max_reference','total_points'=>$total,'rank_totals'=>$rankTotals,
            'point_label'=>'RP','current_rank'=>null,'current_points'=>null,'remaining_points'=>null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'accepted_resources'=>[$this->resourceChoice('Heroism Crystal',10,$holdings),$this->resourceChoice('Heroism Aggregate',50,$holdings)],
            'conditional_costs'=>[],
            'unlock_requirements'=>['RP may also be earned by defeating enemies in Dynamis - Divergence while the JSE neck is equipped.'],
            'notes'=>[
                'NQ necks cap at Rank 15 (5,950 RP), HQ1 at Rank 20 (13,060 RP), and HQ2 at Rank 25 (25,150 RP).',
                'Heroism Crystal grants 10 RP and Heroism Aggregate grants 50 RP.',
                'When LAC specifies only AugPath and no AugRank, the displayed max rank is system context, not an inferred mandatory rank target.',
            ],
            'source_title'=>'Category:JSE Necks - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/JSE_Neck',
            'source_edge_title'=>$title,'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings @param array<string,mixed> $live */
    private function oseemArcaneGlyptics(array $edge,array $holdings,array $live): array
    {
        $resources=[];
        foreach(['Pellucid Stone','Fern Stone','Taupe Stone','Dark Matter'] as $name){
            $norm=LacProfileParser::normalizeName($name);$h=$holdings[$norm]??null;
            $resources[]=['name'=>$name,'name_norm'=>$norm,'owned_quantity'=>$h!==null?(int)$h['quantity']:null,'holding_source'=>$h['source_type']??null];
        }
        return [
            'key'=>'oseem_arcane_glyptics','label'=>'Oseem Reisenjima Arcane Glyptics','mode'=>'random_augment_rolls',
            'data_complete'=>true,'action_required'=>true,'supersedes_requirements'=>true,
            'npc'=>'Oseem','location'=>'Norg (I-7)','content'=>'Reisenjima / Arcane Glyptics',
            'accepted_resources'=>$resources,'current_augments'=>$live['augments']??null,'remaining_state'=>'PENDING_LIVE_AUGMENT_STATE',
            'notes'=>[
                'Pellucid, Fern, and Taupe Stones influence different augment slots/caps; the result of each roll is random.',
                'Dark Matter can roll a broader augment pool and may also be available through the Dark Matter Arcane Glyptics campaign.',
                'There is no finite guaranteed material quantity for a requested LAC augment set; Gear Center tracks rolls/resources and verifies the resulting physical item live.',
            ],
            'source_title'=>'Category:Escha Rewards/Arcane Glyptics Inscription - FFXI Wiki',
            'source_url'=>'https://www.bg-wiki.com/ffxi/Category:Escha_Rewards/Arcane_Glyptics_Inscription',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge @param array<string,array<string,mixed>> $holdings */
    private function limbusModernReinforcement(array $edge,array $holdings): array
    {
        $fields=is_array($edge['fields']??null)?$edge['fields']:[];
        $required=trim((string)($edge['requirements']??$this->field($fields,['Required Items','Requirements','Requirement','Cost','Currency','Materials','Notes'])));
        $n=$this->norm($required);
        $rankMaterial='';
        if(preg_match('/\b(Jewel of [A-Za-z]+\.?)/i',$required,$m))$rankMaterial=trim($m[1]);
        elseif(str_contains($n,'alabaster matter'))$rankMaterial='Alabaster Matter';
        elseif(str_contains($n,'murky matter'))$rankMaterial='Murky Matter';
        $totals=[0=>0,1=>750,2=>2350,3=>4850,4=>8250,5=>12650,6=>18050,7=>24550,8=>32150,9=>40950,10=>50950,11=>62250,12=>74950,13=>89050,14=>104650,15=>121850,16=>140650,17=>161150,18=>183450,19=>207650,20=>233850,21=>262150,22=>292650,23=>325450,24=>360650,25=>398350,26=>438750,27=>481950,28=>528050,29=>577250,30=>629650];
        return [
            'key'=>'limbus_modern_rank30','label'=>'Limbus 2025/2026 Rank-30 reinforcement','mode'=>'reinforcement_points',
            'data_complete'=>true,'supersedes_requirements'=>true,'npc'=>'Temenos / Apollyon Furnace','location'=>'Limbus','content'=>'Limbus (2025/2026)',
            'target_rank'=>30,'target_rank_basis'=>'system_max_reference','total_points'=>629650,'point_label'=>'Units / RP','rank_totals'=>$totals,
            'accepted_resources'=>[
                $this->resourceChoice('Temenos Units',1,$holdings),$this->resourceChoice('Apollyon Units',1,$holdings),
            ],
            'conditional_costs'=>$rankMaterial!==''?[[
                'name'=>$rankMaterial,'quantity'=>null,'when'=>'Required alongside rank reinforcement according to the item/system row; exact remaining trades depend on current live rank and item family.',
            ]]:[],
            'rank_material'=>$rankMaterial!==''?$rankMaterial:null,
            'notes'=>[
                'This is the post-September-2025 Limbus equipment family, distinct from Artifact/Relic +4 reforging even though it uses the same furnaces.',
                'The current Rank-30 table totals 629,650 RP. Temenos/Apollyon Units act as reinforcement points.',
                'Matter/Jewel requirements are rank-linked; Gear Center does not multiply a guessed material total before the current live rank and exact item-family rule are known.',
                'June 2026 added higher-quality crafted equipment and augment transfer; source equipment is lost when transferring an augment to an eligible higher-quality target.',
            ],
            'source_title'=>'Category:Limbus - FFXI Wiki','source_url'=>'https://www.bg-wiki.com/ffxi/Category:Limbus',
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_post_2024_system_rule',
        ];
    }

    /** @param array<string,mixed> $edge */
    private function limbusPlus4(array $edge,string $family,string $unitsText=''): array
    {
        $isRelic=$family==='relic';
        $currency=$isRelic?'Temenos Units':'Apollyon Units';
        $npc=$isRelic?'Temenos Furnace':'Apollyon Furnace';
        $location=$isRelic?'Temenos':'Apollyon';
        $keyItem=$isRelic?'Temenos verification key':'Apollyon verification key';
        $sourceTitle=$isRelic?'Category:Reforged Relic Armor +4 - FFXI Wiki':'Category:Reforged Artifact Armor +4 - FFXI Wiki';
        $sourceUrl=$isRelic?'https://www.bg-wiki.com/ffxi/Category:Reforged_Relic_Armor_%2B4':'https://www.bg-wiki.com/ffxi/Category:Reforged_Artifact_Armor_%2B4';
        $qty=$this->parseInteger($unitsText);
        return [
            'key'=>$isRelic?'reforged_relic_limbus_plus4':'reforged_artifact_limbus_plus4',
            'label'=>$isRelic?'Reforged Relic +4 / Temenos':'Reforged Artifact +4 / Apollyon',
            'mode'=>'fixed_currency_upgrade','data_complete'=>true,'action_required'=>true,'supersedes_requirements'=>false,
            'npc'=>$npc,'location'=>$location,'content'=>'Limbus (119)',
            'currency_name'=>$currency,'currency_quantity'=>$qty,'required_key_item'=>$keyItem,
            'unlock_requirements'=>[
                'Complete Chains of Promathia mission Garden of Antiquity.',
                "Have the Scintillating Rhapsody key item from The Orb's Radiance.",
                'Obtain the '.$keyItem.' by opening a treasure chest in '.$location.' for the first time.',
            ],
            'notes'=>[
                'Trade the Reforged +3 armor piece to the '.$npc.' and commit the slot-specific '.$currency.' cost.',
                'The +4 reforging is instant and the finished armor is placed in inventory.',
                'Slot costs are fixed system-wide: Head 20,000; Body 30,000; Hands 15,000; Legs 25,000; Feet 10,000 '.$currency.'.',
            ],
            'source_title'=>$sourceTitle,'source_url'=>$sourceUrl,
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<int,array<string,mixed>> $usage @param array<int,array<string,mixed>> $records */
    private function limbusPlus4TargetHint(string $itemName,array $usage,array $records,string $family): ?array
    {
        $slot=$this->armorSlotFromUsage($usage);
        $costs=['HEAD'=>20000,'BODY'=>30000,'HANDS'=>15000,'LEGS'=>25000,'FEET'=>10000];
        $cost=$slot!==null?($costs[$slot]??null):null;
        $next=$this->nextPlus4TargetFromRecords($records);
        $isRelic=$family==='relic';
        $currency=$isRelic?'Temenos Units':'Apollyon Units';
        $npc=$isRelic?'Temenos Furnace':'Apollyon Furnace';
        $location=$isRelic?'Temenos':'Apollyon';
        $keyItem=$isRelic?'Temenos verification key':'Apollyon verification key';
        return [
            'key'=>$isRelic?'reforged_relic_limbus_plus4_available':'reforged_artifact_limbus_plus4_available',
            'label'=>$isRelic?'Next upgrade: Reforged Relic +4':'Next upgrade: Reforged Artifact +4',
            'mode'=>'next_upgrade','data_complete'=>$cost!==null,'action_required'=>true,'advisory_only'=>true,
            'supersedes_requirements'=>false,'item_name'=>$itemName,'next_item'=>$next,
            'npc'=>$npc,'location'=>$location,'currency_name'=>$currency,'currency_quantity'=>$cost,'required_key_item'=>$keyItem,
            'notes'=>array_values(array_filter([
                $next!==''?'Explicit BG-Wiki next stage: '.$next.'.':'The Reforged +3 family has a +4 Limbus upgrade; cache the linked +4 page to resolve the exact canonical target name.',
                $cost!==null?'Required for this '.$slot.' slot: '.number_format($cost).' '.$currency.'.':'Slot-specific '.$currency.' cost could not yet be resolved from current LAC usage.',
                'Requires '.$keyItem.'; Garden of Antiquity and Scintillating Rhapsody are system prerequisites.',
                'This is a next-tier upgrade opportunity, separate from whether the current +3 LAC AugTrial marker is already satisfied.',
            ])),
            'source_title'=>$isRelic?'Category:Reforged Relic Armor +4 - FFXI Wiki':'Category:Reforged Artifact Armor +4 - FFXI Wiki',
            'source_url'=>$isRelic?'https://www.bg-wiki.com/ffxi/Category:Reforged_Relic_Armor_%2B4':'https://www.bg-wiki.com/ffxi/Category:Reforged_Artifact_Armor_%2B4',
            'confidence'=>'system_family_plus_structured_current_item',
        ];
    }

    /** @param array<int,array<string,mixed>> $usage */
    private function armorSlotFromUsage(array $usage): ?string
    {
        $map=['HEAD'=>'HEAD','BODY'=>'BODY','HANDS'=>'HANDS','LEGS'=>'LEGS','FEET'=>'FEET'];
        foreach($usage as $u){
            $slot=strtoupper(trim((string)($u['slot_name']??'')));
            if(isset($map[$slot]))return $map[$slot];
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $records */
    private function nextPlus4TargetFromRecords(array $records): string
    {
        foreach($records as $record){
            $section=$this->norm((string)($record['section']??''));
            if(!str_contains($section,'used in item upgrades') && !str_contains($section,'upgrade'))continue;
            $fields=is_array($record['fields']??null)?$record['fields']:[];
            foreach(['Item Name','Upgraded Item','Upgrade To','Reforged Armor','Result'] as $key){
                $v=$this->field($fields,[$key]);
                if($v!=='' && preg_match('/\+4\b/u',$v))return trim(preg_split('/\R/u',$v)[0]??$v);
            }
        }
        return '';
    }

    /** @param array<int,array<string,mixed>> $records */
    private function recordsLookLikeReforgedArtifactPlus3(string $itemName,array $records): bool
    {
        $itemNorm=LacProfileParser::normalizeName($itemName);
        $hasOmenSignature=false;$hasCoelestrox=false;$hasPlus4=false;
        foreach($records as $record){
            $fields=is_array($record['fields']??null)?$record['fields']:[];
            $section=$this->norm((string)($record['section']??''));
            $result=$this->field($fields,['Reforged Armor','Result','Upgrade To']);
            if($result!=='' && LacProfileParser::normalizeName($result)!==$itemNorm && !preg_match('/\+4\b/u',$result))continue;
            $joined=$this->norm(implode(' ',array_map('strval',$fields)));
            if(str_contains($joined,'coelestrox'))$hasCoelestrox=true;
            if(str_contains($joined,'card') && (str_contains($joined,'scale') || str_contains($joined,'omen')))$hasOmenSignature=true;
            if(str_contains($section,'used in item upgrades') || str_contains($section,'upgrade')){
                foreach($fields as $v)if(preg_match('/\+4\b/u',(string)$v)){$hasPlus4=true;break;}
            }
        }
        return $hasOmenSignature || ($hasCoelestrox && $hasPlus4);
    }

    /** @param array<int,array<string,mixed>> $records */
    private function recordsLookLikeReforgedRelicPlus3(string $itemName,array $records): bool
    {
        $itemNorm=LacProfileParser::normalizeName($itemName);
        $hasDivergenceSignature=false;$hasAurix=false;$hasPlus4=false;
        foreach($records as $record){
            $fields=is_array($record['fields']??null)?$record['fields']:[];
            $section=$this->norm((string)($record['section']??''));
            $result=$this->field($fields,['Reforged Armor','Result','Upgrade To']);
            if($result!=='' && LacProfileParser::normalizeName($result)!==$itemNorm && !preg_match('/\+4\b/u',$result))continue;
            $joined=$this->norm(implode(' ',array_map('strval',$fields)));
            if(str_contains($joined,'aurix'))$hasAurix=true;
            if(str_contains($joined,'shard') && str_contains($joined,'void'))$hasDivergenceSignature=true;
            if(str_contains($section,'used in item upgrades') || str_contains($section,'upgrade')){
                foreach($fields as $v)if(preg_match('/\+4\b/u',(string)$v)){$hasPlus4=true;break;}
            }
        }
        return $hasDivergenceSignature || ($hasAurix && $hasPlus4);
    }

    /** @param array<int,array<string,mixed>> $records */
    private function recordsLookLikeSortieJobEarring(array $records): bool
    {
        foreach($records as $record){
            $fields=is_array($record['fields']??null)?$record['fields']:[];
            $joined=$this->norm(implode(' ',array_map('strval',$fields)));
            if(str_contains($joined,'??? earring') || str_contains($joined,'??? ear.'))return true;
        }
        return false;
    }

    /** @param array<string,mixed> $edge @param array<int,string> $notes */
    private function workflow(string $key,string $label,string $mode,string $npc,string $location,string $content,bool $supersedes,array $notes,string $sourceTitle,string $sourceUrl,array $edge): array
    {
        return [
            'key'=>$key,'label'=>$label,'mode'=>$mode,'data_complete'=>true,'supersedes_requirements'=>$supersedes,
            'npc'=>$npc,'location'=>$location,'content'=>$content,'target_rank'=>null,'total_points'=>null,'point_label'=>'',
            'accepted_resources'=>[],'conditional_costs'=>[],'notes'=>$notes,
            'source_title'=>$sourceTitle,'source_url'=>$sourceUrl,
            'source_edge_title'=>(string)($edge['source_title']??''),'source_edge_url'=>(string)($edge['source_url']??''),'confidence'=>'structured_system_rule',
        ];
    }

    /** @param array<string,mixed> $system @param array<string,mixed> $live */
    private function applyLiveState(array $system,array $live): array
    {
        if(($system['mode']??'')!=='reinforcement_points')return $system;
        $copyCount=(int)($live['copy_count']??0);
        $system['live_copy_count']=$copyCount;$system['current_path']=$live['path']??null;$system['live_type']=$live['type']??null;
        if($copyCount===0){$system['remaining_state']='PENDING_LIVE_AUGMENT_STATE';return $system;}
        if($copyCount>1){$system['remaining_state']='AMBIGUOUS_MULTIPLE_COPIES';return $system;}
        $rank=isset($live['rank'])?(int)$live['rank']:null;$system['current_rank']=$rank;
        if($rank===null){$system['remaining_state']='LIVE_RANK_UNAVAILABLE';return $system;}
        if(($system['target_rank_basis']??'')==='system_max_reference'){
            $system['remaining_state']='REFERENCE_MAX_NOT_LAC_TARGET';
            return $system;
        }
        $target=(int)($system['target_rank']??0);
        if($target>0 && $rank >= $target){$system['remaining_points']=0;$system['remaining_min_points']=0;$system['remaining_max_points']=0;$system['remaining_state']='COMPLETE';return $system;}
        $totals=$system['rank_totals']??[];$atRank=(int)($totals[$rank]??0);$next=(int)($totals[$rank+1]??0);
        if($target>0 && $next>=0 && isset($totals[$rank+1])){
            $system['remaining_min_points']=max(1,(int)$system['total_points']-max($atRank,$next-1));
            $system['remaining_max_points']=max(0,(int)$system['total_points']-$atRank);
        }
        $system['remaining_state']='PARTIAL_RANK_NEXT_RP_UNREAD';return $system;
    }

    /** @param array<string,array<string,mixed>> $holdings */
    private function resourceChoice(string $name,int $points,array $holdings): array
    {
        $norm=LacProfileParser::normalizeName($name);$holding=$holdings[$norm]??null;$qty=$holding!==null?(int)$holding['quantity']:null;
        return ['name'=>$name,'name_norm'=>$norm,'points_per_unit'=>$points,'owned_quantity'=>$qty,'available_points'=>$qty===null?null:$qty*$points,'holding_source'=>$holding['source_type']??null];
    }

    private function parseInteger(string $value): ?int
    {
        $value=trim($value);if($value==='')return null;
        if(!preg_match('/([0-9][0-9,]*)/',$value,$m))return null;
        return (int)str_replace(',','',$m[1]);
    }

    private function field(array $fields,array $keys): string
    {
        foreach($keys as $wanted)foreach($fields as $k=>$v)if(strcasecmp(trim((string)$k),$wanted)===0)return trim((string)$v);
        return '';
    }
    private function norm(string $s): string{return strtolower(trim(preg_replace('/\s+/u',' ',$s)??$s));}
}
