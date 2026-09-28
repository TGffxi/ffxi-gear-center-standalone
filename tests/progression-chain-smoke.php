#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/payload/app/src/bootstrap.php';

$records=[
 ['item_name'=>'Stage II Blade','page_title'=>'Stage II Blade','canonical_url'=>'https://example.test/stage2','section'=>'Upgraded From','fields'=>['Base Item'=>'Stage I Blade','NPC Name'=>'Upgrade NPC','Required Items'=>'Material A x10']],
 ['item_name'=>'Stage III Blade','page_title'=>'Stage III Blade','canonical_url'=>'https://example.test/stage3','section'=>'Upgraded From','fields'=>['Previous Item'=>'Stage II Blade','NPC Name'=>'Upgrade NPC','Required Items'=>'Material B x20']],
 ['item_name'=>'Stage III Blade','page_title'=>'Stage III Blade','canonical_url'=>'https://example.test/stage3','section'=>'Augmented Via','fields'=>['NPC Name'=>'Augment NPC','Zone'=>'Test Zone','Required Items'=>'RP x1000']],
 ['item_name'=>'Stage III Blade','page_title'=>'Stage III Blade','canonical_url'=>'https://example.test/stage3','section'=>'Used In Item Upgrades','fields'=>['Upgraded Item'=>'Stage IV Blade','Required Items'=>'Material C x30']],
];
$svc=new ProgressionChainService();
$g=$svc->buildFromRecords('Stage III Blade',$records);
if(!$g['has_data'])throw new RuntimeException('Expected chain data');
if(count($g['upstream_paths'])!==1)throw new RuntimeException('Expected one upstream path');
$path=$g['upstream_paths'][0];
if(count($path)!==2)throw new RuntimeException('Expected two-stage upstream chain');
if(($path[0]['from']??'')!=='Stage I Blade' || ($path[0]['to']??'')!=='Stage II Blade')throw new RuntimeException('Stage I -> II relation missing');
if(($path[1]['from']??'')!=='Stage II Blade' || ($path[1]['to']??'')!=='Stage III Blade')throw new RuntimeException('Stage II -> III relation missing');
$focusActions=array_values(array_filter($g['actions'],static fn($e)=>$e['from_norm']===LacProfileParser::normalizeName('Stage III Blade')));
if(count($focusActions)!==1 || ($focusActions[0]['kind']??'')!=='augment')throw new RuntimeException('Target augment action missing');
if(count($g['downstream_paths'])!==1 || ($g['downstream_paths'][0][0]['to']??'')!=='Stage IV Blade')throw new RuntimeException('Downstream upgrade relation missing');
if(count($g['plans'])!==1)throw new RuntimeException('Expected one actionable chain plan');
$steps=implode(' | ',$g['plans'][0]['steps']);
foreach(['Stage I Blade','Stage II Blade','Stage III Blade','Augment NPC','RP x1000'] as $needle)if(stripos($steps,$needle)===false)throw new RuntimeException('Plan missing '.$needle);


$versionGraph=$svc->buildFromRecords('Test Blade (Level 119 III)',[
 ['item_name'=>'Test Blade (Level 119 III)','section'=>'Version Chain','fields'=>['From Item'=>'Test Blade (Level 75)','To Item'=>'Test Blade (Level 80)','Source'=>'Versions']],
 ['item_name'=>'Test Blade (Level 119 III)','section'=>'Version Chain','fields'=>['From Item'=>'Test Blade (Level 80)','To Item'=>'Test Blade (Level 119 III)','Source'=>'Versions']],
]);
if(count($versionGraph['upstream_paths'])!==1 || count($versionGraph['upstream_paths'][0])!==2)throw new RuntimeException('Explicit version-chain relations failed');



// Path tables are augment choices, not additional augment actions. They must be
// exposed as options while the actionable plan keeps only the Nolan-like action.
$optionGraph=$svc->buildFromRecords('Ryuo Somen +1',[
 ['item_name'=>'Ryuo Somen +1','page_title'=>'Ryuo Somen +1','section'=>'Augmented Via','fields'=>['Augmented Via'=>'Nolan','Zone'=>'Norg - (I-7)','Required Items'=>'Eschalixir, +1, or +2']],
 ['item_name'=>'Ryuo Somen +1','page_title'=>'Ryuo Somen +1','section'=>'Augmented Via','fields'=>['Path A'=>'STR+12\nDEX+12\nAccuracy +20','Path B'=>'HP+65\nAccuracy +20\nAttack +20','Path C'=>'HP+65\nStore TP +5\nSubtle Blow +8','Path D'=>'Ninjutsu skill +20\nMag. Acc.+25\nMag. Atk. Bns.+25']],
]);
$focusActions=array_values(array_filter($optionGraph['actions'],static fn($e)=>$e['from_norm']===LacProfileParser::normalizeName('Ryuo Somen +1')));
if(count($focusActions)!==1)throw new RuntimeException('Augment path table leaked into actionable augment steps');
if(count($optionGraph['augment_options'])!==4)throw new RuntimeException('Expected four structured augment path options');
$pathC=array_values(array_filter($optionGraph['augment_options'],static fn($o)=>($o['path']??'')==='C'));
if(count($pathC)!==1 || stripos((string)$pathC[0]['details'],'Store TP +5')===false)throw new RuntimeException('Path C option extraction failed');
if(count($optionGraph['plans'])!==1 || count($optionGraph['plans'][0]['steps'])!==1)throw new RuntimeException('Ryuo plan should contain one augment action, not a duplicate path step');
echo "progression augment-option smoke: OK · paths separated from actions\n";

// Ambuscade JSE cape stat matrices are option/detail rows too.  The historical
// BG-Wiki cache contains one real Gorpa-Masorpa action followed by many rows
// with columns such as "Aug/Item" and "Max Aug".  Those rows must never expand
// into 100+ repeated "Augment Andartia's Mantle" progression steps.
$capeGraph=$svc->buildFromRecords("Andartia's Mantle",[
 ['item_name'=>"Andartia's Mantle",'page_title'=>"Andartia's Mantle",'section'=>'Augmented Via','fields'=>['Augmented Via'=>'Gorpa-Masorpa','Zone'=>'Mhaura - (G-9)','Required Items'=>'Abdhaljs Thread, Sap, Dye, Dust, or Resin']],
 ['item_name'=>"Andartia's Mantle",'page_title'=>"Andartia's Mantle",'section'=>'Augmented Via','fields'=>['Abdhaljs Thread'=>'Store TP','Aug/Item'=>'+1','Max Aug'=>'+10']],
 ['item_name'=>"Andartia's Mantle",'page_title'=>"Andartia's Mantle",'section'=>'Augmented Via','fields'=>['Abdhaljs Thread'=>'Double Attack','Aug/Item'=>'+1%','Max Aug'=>'+10%']],
 ['item_name'=>"Andartia's Mantle",'page_title'=>"Andartia's Mantle",'section'=>'Augmented Via','fields'=>['Abdhaljs Resin'=>'Damage taken','Aug/Item'=>'-1%','Max Aug'=>'-5%']],
]);
$capeActions=array_values(array_filter($capeGraph['actions'],static fn($e)=>$e['from_norm']===LacProfileParser::normalizeName("Andartia's Mantle")));
if(count($capeActions)!==1)throw new RuntimeException('JSE cape option/detail matrix leaked into actionable augment steps: '.count($capeActions));
$capeOwned=$svc->forOwnedFocus($capeGraph);
if(count($capeOwned['plans'])!==1 || count($capeOwned['plans'][0]['steps']??[])!==1)throw new RuntimeException('Owned JSE cape should have one current augment action only');
if(stripos((string)$capeOwned['plans'][0]['steps'][0],'Gorpa-Masorpa')===false)throw new RuntimeException('Owned JSE cape action lost Gorpa-Masorpa context');
echo "progression JSE-cape smoke: OK · detail matrix does not become 100+ actions\n";

// Once the concrete target variant is already owned, upstream upgrade stages are
// history only. The actionable plan must start at the owned variant and keep only
// its remaining self-action (here: augment).
$ownedGraph=$svc->forOwnedFocus($g);
if(($ownedGraph['current_state']??'')!=='owned')throw new RuntimeException('Owned focus state not marked');
if(count($ownedGraph['history_paths']??[])!==1)throw new RuntimeException('Owned focus lost upgrade history');
if(count($ownedGraph['plans'])!==1)throw new RuntimeException('Owned focus should retain one current-stage action plan');
$ownedSteps=$ownedGraph['plans'][0]['steps']??[];
if(count($ownedSteps)!==1 || stripos((string)$ownedSteps[0],'Augment Stage III Blade')===false)throw new RuntimeException('Owned focus plan still includes historical upgrade stages');
echo "progression current-state smoke: OK · owned target starts at current variant\n";

// A name that merely looks related must never become a relation without a structured row.
$unrelated=$svc->buildFromRecords('Stage III Blade',[['item_name'=>'Stage III Blade','section'=>'Notes','fields'=>['Notes'=>'Stage II Blade is mentioned in prose']]]);
if($unrelated['has_data'])throw new RuntimeException('Prose/name inference leaked into progression graph');

echo "progression-chain-smoke: OK · explicit multi-stage upgrade + augment chain only\n";
