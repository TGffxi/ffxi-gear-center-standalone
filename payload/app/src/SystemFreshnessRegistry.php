<?php
declare(strict_types=1);

/**
 * Curated freshness metadata for progression systems.
 *
 * This registry deliberately separates "the adapter can model the current target"
 * from "the system was checked against current post-2024 game updates".
 * It is static/offline by design; updating it is an explicit code/research action,
 * never an inferred date from a cached item page.
 */
final class SystemFreshnessRegistry
{
    public const KNOWLEDGE_VERSION = '2026-08';
    public const VERIFIED_THROUGH = '2026-08-31';

    /** @return array<string,array<string,mixed>> */
    public static function systems(): array
    {
        return [
            'limbus_reforged_relic_plus4'=>[
                'label'=>'Limbus Reforged Relic +4 / Temenos',
                'latest_tier'=>'Reforged Relic +4 / Temenos','max_numeric_tier'=>4,
                'introduced'=>'2025-06','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>[
                    'reforged relic +3 embedded job augment','next upgrade: reforged relic +4',
                    'reforged relic +4 / temenos','reforged relic / dynamis-d'
                ],
                'sources'=>[
                    ['date'=>'2025-06-10','title'=>'June 10, 2025 (JST) Version Update','url'=>'https://forum.square-enix.com/ffxi/threads/62862'],
                    ['date'=>'2026-08','title'=>'BG-Wiki Reforged Relic Armor +4','url'=>'https://www.bg-wiki.com/ffxi/Category:Reforged_Relic_Armor_%2B4'],
                ],
                'updates'=>[
                    '2025-06: Reforged Relic +4 was introduced through the Temenos Furnace using Temenos Units.',
                    '2026-07: Temenos floor/progression structure changed; the +4 armor tier remains current.',
                ],
            ],
            'limbus_reforged_artifact_plus4'=>[
                'label'=>'Limbus Reforged Artifact +4 / Apollyon',
                'latest_tier'=>'Reforged Artifact +4 / Apollyon','max_numeric_tier'=>4,
                'introduced'=>'2025-06','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>[
                    'reforged artifact +3 encoded job state','next upgrade: reforged artifact +4',
                    'reforged artifact +4 / apollyon','reforged artifact / omen'
                ],
                'sources'=>[
                    ['date'=>'2025-06-10','title'=>'June 10, 2025 (JST) Version Update','url'=>'https://forum.square-enix.com/ffxi/threads/62862'],
                    ['date'=>'2026-08','title'=>'BG-Wiki Reforged Artifact Armor +4','url'=>'https://www.bg-wiki.com/ffxi/Category:Reforged_Artifact_Armor_%2B4'],
                ],
                'updates'=>['2025-06: Reforged Artifact +4 was introduced through the Apollyon Furnace using Apollyon Units.'],
            ],
            'limbus_new_equipment'=>[
                'label'=>'Limbus crafted/reforged equipment',
                'latest_tier'=>'2026 Limbus crafted equipment + transferable augments',
                'introduced'=>'2025-09',
                'last_verified'=>self::VERIFIED_THROUGH,
                'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['limbus 2025/2026 rank-30 reinforcement','limbus crafted','limbus reforged','alabaster matter','murky matter'],
                'sources'=>[
                    ['date'=>'2025-09-10','title'=>'September 10, 2025 (JST) Version Update','url'=>'https://forum.square-enix.com/ffxi/threads/63276'],
                    ['date'=>'2026-06-10','title'=>'June 10, 2026 (JST) Version Update','url'=>'https://forum.square-enix.com/ffxi/threads/64317'],
                    ['date'=>'2026-07-09','title'=>'July 9, 2026 (JST) Version Update','url'=>'https://forum.square-enix.com/ffxi/threads/64429'],
                ],
                'updates'=>[
                    '2025-09: Alabaster/Murky Matter crafting and unit-based staged RP reforging were added.',
                    '2026-06: new Limbus endgame NMs, crafting materials/equipment and augment transfer to higher-quality equipment were added.',
                    '2026-07: Temenos tower structure was shortened/adjusted.',
                ],
            ],
            'reforged_empyrean_sortie'=>[
                'label'=>'Reforged Empyrean / Sortie',
                'latest_tier'=>'Reforged Empyrean +3','max_numeric_tier'=>3,
                'introduced'=>'2022-2023',
                'last_verified'=>self::VERIFIED_THROUGH,
                'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['reforged empyrean / sortie','reforged empyrean','ruspix'],
                'sources'=>[
                    ['date'=>'2024-07-10','title'=>'July 10, 2024 (JST) Version Update - Sortie RoE additions','url'=>'https://forum.square-enix.com/ffxi/threads/61910'],
                    ['date'=>'2026-08','title'=>'BG-Wiki current Empyrean Armor navigation','url'=>'https://www.bg-wiki.com/ffxi/Category:Empyrean_Armor'],
                ],
                'updates'=>['Current BG-Wiki armor navigation still ends Reforged Empyrean at +3; no +4 tier is asserted by Gear Center.'],
            ],
            'sortie_jse_earring'=>[
                'label'=>'Sortie JSE Earring random augments',
                'adapter_keys'=>['sortie_jse_earring_random_augments'],'latest_tier'=>'Sortie JSE earring reveal tiers',
                'introduced'=>'2022-2023','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['sortie jse earring random augments'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Sortie Rewards','url'=>'https://www.bg-wiki.com/ffxi/Category:Sortie_Rewards']],
                'updates'=>[],
            ],
            'unity_odyssey'=>[
                'label'=>'Unity Rewards → Odyssey Augments',
                'adapter_keys'=>['unity_odyssey_augments'],
                'latest_tier'=>'Unity +1 Rank 15 via Odyssey Sheol A/B/C',
                'introduced'=>'2020','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>[
                    'unity rewards → odyssey augments','unity rewards -> odyssey augments',
                    'unity / odyssey','unity odyssey augments','lustreless scale','lustreless hide','lustreless wing'
                ],
                'sources'=>[
                    ['date'=>'2026-08','title'=>'BG-Wiki Unity Rewards','url'=>'https://www.bg-wiki.com/ffxi/Category:Unity_Rewards'],
                    ['date'=>'2026-08','title'=>'BG-Wiki Odyssey','url'=>'https://www.bg-wiki.com/ffxi/Category:Odyssey'],
                ],
                'updates'=>['Current verified model: +1 Unity equipment augments to Rank 15 after the corresponding Sheol clear, using the matching Lustreless material family and Unity Accolades.'],
            ],
            'odyssey_gaol'=>[
                'label'=>'Odyssey Sheol: Gaol Augments',
                'adapter_keys'=>['odyssey_gaol_augments'],
                'latest_tier'=>'Odyssey Gaol Rank 30',
                'introduced'=>'2021-2022','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>[
                    'odyssey sheol: gaol augments','odyssey sheol gaol augments','odyssey gaol',
                    'pilgrim moogle','current odyssey/gaol rank systems'
                ],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Odyssey','url'=>'https://www.bg-wiki.com/ffxi/Category:Odyssey']],
                'updates'=>['Current verified model: Gaol equipment can progress to Rank 30; Vengeance/content state governs unlock caps and RP availability.'],
            ],
            'dynamis_divergence_weapons'=>[
                'label'=>'Dynamis-Divergence Weapon Augments',
                'adapter_keys'=>['dynamis_divergence_weapon_augments'],
                'latest_tier'=>'Dynamis-D weapon Rank 15/20/25 by quality',
                'introduced'=>'2018','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>[
                    'dynamis-divergence weapon augments','dynamis divergence weapon augments',
                    'dynamis-d weapon augments','divergence weapon augments'
                ],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Dynamis Divergence Weapon Augments','url'=>'https://www.bg-wiki.com/ffxi/Dynamis_Divergence_Weapon_Augments']],
                'updates'=>[
                    'Verified current: NQ caps at R15/5,950 RP, HQ1 at R20/13,060 RP, HQ2+ at R25/25,150 RP; weapons have three paths and use Heroism Crystal/Aggregate or Dynamis-D RP.',
                    '2025-11: Dynamis-Divergence shared areas were added; this changed content access/flow, not the crafted-weapon augment caps.',
                    '2025-12: shared-area entry/AoE/boss-HP behavior was adjusted; weapon augment progression remained unchanged.',
                    '2026-01: shared-area KO penalties were reduced via the resolved status; weapon augment progression remained unchanged.',
                ],
            ],
            'ambuscade_jse_cape'=>[
                'label'=>'Ambuscade JSE Cape','latest_tier'=>'Current Abdhaljs JSE cape augmentation',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['ambuscade jse cape','gorpa-masorpa'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Ambuscade','url'=>'https://www.bg-wiki.com/ffxi/Category:Ambuscade']],
                'updates'=>[],
            ],
            'oboro_ultimate'=>[
                'label'=>'Oboro Ultimate Weapon Augments','latest_tier'=>'Oboro Rank 15 ultimate-weapon augments',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['ultimate weapon augments','oboro ultimate'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Ultimate Weapon Augments','url'=>'https://www.bg-wiki.com/ffxi/BGWiki:Ultimate_Weapon_Augments']],
                'updates'=>[],
            ],
            'jse_neck'=>[
                'label'=>'JSE Neck / Oboro / Dynamis-D RP','latest_tier'=>'HQ2 Rank 25 JSE neck',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['jse neck','ninja nodowa','heroism crystal','heroism aggregate'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki JSE Neck','url'=>'https://www.bg-wiki.com/ffxi/JSE_Neck']],
                'updates'=>[],
            ],
            'nolan'=>[
                'label'=>'Nolan Escha Mezzotint',
                'adapter_keys'=>['nolan_escha_mezzotint'],'latest_tier'=>'Nolan Rank 15 Path A-D',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['nolan escha mezzotint','nolan'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Nolan','url'=>'https://www.bg-wiki.com/ffxi/Nolan']],
                'updates'=>[],
            ],
            'oseem'=>[
                'label'=>'Oseem Arcane Glyptics','latest_tier'=>'Current Oseem random augment system',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['oseem','arcane glyptics'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Arcane Glyptics','url'=>'https://www.bg-wiki.com/ffxi/Category:Escha_Rewards/Arcane_Glyptics_Inscription']],
                'updates'=>[],
            ],
            'trial_magian'=>[
                'label'=>'Trial of the Magians',
                'adapter_keys'=>['trial_of_magians'],'latest_tier'=>'Structured trial chain from current source rows',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['trial of the magians'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Trial of the Magians','url'=>'https://www.bg-wiki.com/ffxi/Category:Trial_of_the_Magians']],
                'updates'=>[],
            ],
            'moonshade'=>[
                'label'=>'Moonshade mission reward augment choice',
                'adapter_keys'=>['moonshade_mission_reward_augments'],'latest_tier'=>'Wings of the Goddess reward augment choice',
                'introduced'=>'legacy','last_verified'=>self::VERIFIED_THROUGH,'freshness'=>'POST_2024_VERIFIED',
                'aliases'=>['moonshade mission reward augment choice'],
                'sources'=>[['date'=>'2026-08','title'=>'BG-Wiki Lest We Forget','url'=>'https://www.bg-wiki.com/ffxi/Lest_We_Forget']],
                'updates'=>[],
            ],
        ];
    }

    /** @return array<string,mixed>|null */
    public static function matchLabel(string $label): ?array
    {
        $norm=self::norm($label);
        foreach(self::systems() as $key=>$row){
            foreach($row['aliases'] as $alias){
                $a=self::norm((string)$alias);
                if($a!=='' && (str_contains($norm,$a) || str_contains($a,$norm))){$row['key']=$key;return $row;}
            }
        }
        return null;
    }

    /** @return array<string,mixed>|null */
    public static function matchAdapterKey(string $adapterKey): ?array
    {
        $needle=self::normKey($adapterKey);
        if($needle==='')return null;
        foreach(self::systems() as $key=>$row){
            foreach(($row['adapter_keys']??[]) as $candidate){
                if(self::normKey((string)$candidate)===$needle){$row['key']=$key;return $row;}
            }
        }
        return null;
    }

    /** @return array<int,array<string,mixed>> */
    public static function timeline(): array
    {
        return [
            ['date'=>'2024-07-10','label'=>'Sortie Records of Eminence objectives added','system'=>'Sortie','source'=>'https://forum.square-enix.com/ffxi/threads/61910'],
            ['date'=>'2025-06-10','label'=>'Limbus renewal: Artifact +4 / Apollyon and Relic +4 / Temenos','system'=>'Limbus','source'=>'https://forum.square-enix.com/ffxi/threads/62862'],
            ['date'=>'2025-09-10','label'=>'Limbus Matter crafting + staged unit/RP reforging','system'=>'Limbus','source'=>'https://forum.square-enix.com/ffxi/threads/63276'],
            ['date'=>'2025-11-10','label'=>'Dynamis-Divergence shared areas added; existing weapon augment caps unchanged','system'=>'Dynamis-Divergence','source'=>'https://forum.square-enix.com/ffxi/threads/63504'],
            ['date'=>'2025-12-10','label'=>'Dynamis-Divergence shared-area combat/entry adjustments','system'=>'Dynamis-Divergence','source'=>'https://forum.square-enix.com/ffxi/threads/63632'],
            ['date'=>'2026-01-13','label'=>'Dynamis-Divergence shared-area resolved status / KO-loss adjustment','system'=>'Dynamis-Divergence','source'=>'https://forum.square-enix.com/ffxi/threads/63745'],
            ['date'=>'2026-06-10','label'=>'Limbus endgame NMs, new crafted equipment and augment transfer','system'=>'Limbus','source'=>'https://forum.square-enix.com/ffxi/threads/64317'],
            ['date'=>'2026-07-09','label'=>'Temenos floor/progression adjustments','system'=>'Limbus','source'=>'https://forum.square-enix.com/ffxi/threads/64429'],
        ];
    }

    private static function norm(string $s): string{return strtolower(trim(preg_replace('/\s+/u',' ',$s)??$s));}
    private static function normKey(string $s): string{return strtolower(trim(preg_replace('/[^a-z0-9_]+/i','_',str_replace(['-',' '],'_',$s))??$s,'_'));}
}
