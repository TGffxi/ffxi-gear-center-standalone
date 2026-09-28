<?php
declare(strict_types=1);
require dirname(__DIR__) . '/payload/app/src/bootstrap.php';

$assert=function(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);};
$svc=new ProgressionResourceService();

$oboro=[
    'key'=>'kikoku-rp','title'=>'Progress Kikoku','path'=>[],
    'actions'=>[[
        'key'=>'oboro','kind'=>'augment','section'=>'Augmented Via',
        'from'=>'Kikoku (Level 119 III)','to'=>'Kikoku (Level 119 III)',
        'actor'=>'Oboro','zone'=>'Port Jeuno - (E-6)',
        'requirements'=>"S. Astral Detritus & M. Astral Detritus\nSee Oboro for prerequisites",
        'fields'=>[
            'Augmented Via'=>'Oboro','Zone'=>'Port Jeuno - (E-6)',
            'Required Items'=>"S. Astral Detritus & M. Astral Detritus\nSee Oboro for prerequisites",
        ],
        'source_title'=>'Kikoku (Level 119 III)','source_url'=>'https://example.invalid/kikoku',
    ]],
];
$r=$svc->forPlan($oboro,[]);
$assert(count($r['systems'])===1,'Oboro detritus row should resolve to one progression system.');
$sys=$r['systems'][0];
$assert($sys['key']==='ultimate_weapon_augments','Wrong system for Oboro detritus row.');
$assert($sys['total_points']===5960,'Ultimate weapon total should be 5960 RP.');
$assert(count($sys['accepted_resources'])===2,'Ultimate weapon system should expose both accepted detritus resources.');
$assert($r['requirements']===[],'Detritus exchange resources must not also appear as fixed simultaneous requirements.');

$complete=$svc->forPlan($oboro,[],['copy_count'=>1,'rank'=>15,'path'=>'A','type'=>'Dynamis']);
$completeSys=$complete['systems'][0];
$assert($completeSys['remaining_state']==='COMPLETE' && $completeSys['remaining_points']===0,'Live Rank 15 must resolve the Ultimate Weapon system to COMPLETE with 0 RP remaining.');
$assert($completeSys['current_path']==='A','Live path should be carried into the progression-system state.');

$partial=$svc->forPlan($oboro,[],['copy_count'=>1,'rank'=>14,'path'=>'A','type'=>'Dynamis']);
$partialSys=$partial['systems'][0];
$assert($partialSys['remaining_state']==='PARTIAL_RANK_NEXT_RP_UNREAD','Rank below target should stay partial when NextRP is unread.');
$assert($partialSys['remaining_min_points']===1 && $partialSys['remaining_max_points']===980,'Rank 14 Ultimate Weapon remaining RP range should be 1–980 when exact within-rank progress is unavailable.');

$ambiguous=$svc->forPlan($oboro,[],['copy_count'=>2,'rank'=>15]);
$assert($ambiguous['systems'][0]['remaining_state']==='AMBIGUOUS_MULTIPLE_COPIES','Multiple physical copies must not be silently assigned one live rank state.');

$nolan=[
    'key'=>'ryuo-rp','title'=>'Progress Ryuo Somen +1','path'=>[],
    'actions'=>[[
        'key'=>'nolan','kind'=>'augment','section'=>'Augmented Via',
        'from'=>'Ryuo Somen +1','to'=>'Ryuo Somen +1',
        'actor'=>'Nolan','zone'=>'Norg - (I-7)','requirements'=>'Eschalixir, +1, or +2',
        'fields'=>['Augmented Via'=>'Nolan','Zone'=>'Norg - (I-7)','Required Items'=>'Eschalixir, +1, or +2'],
        'source_title'=>'Ryuo Somen +1','source_url'=>'https://example.invalid/ryuo',
    ]],
];
$n=$svc->forPlan($nolan,[]);
$assert(count($n['systems'])===1,'Nolan Eschalixir row should resolve to one progression system.');
$assert($n['systems'][0]['key']==='nolan_escha_mezzotint','Wrong system for Nolan row.');
$assert($n['systems'][0]['total_points']===5980,'Nolan Rank-15 total should be 5980 RP.');
$assert(count($n['systems'][0]['accepted_resources'])===3,'Nolan system should expose three Eschalixir tiers.');
$assert($n['alternative_groups']===[],'System resources must not be duplicated as a generic ANY-OF group.');

$parsed=$svc->parseRequirementText("3 x Pluton\nSee Oboro for prerequisites\nSpeak to Nolan for prerequisites");
$assert(count($parsed)===1 && $parsed[0]['name']==='Pluton' && $parsed[0]['quantity']===3,'Instruction text leaked into material requirements.');

echo "progression-systems-smoke: OK · Oboro/Nolan RP systems + instruction filtering + live rank states\n";
