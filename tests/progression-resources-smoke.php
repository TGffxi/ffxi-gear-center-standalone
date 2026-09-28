<?php
declare(strict_types=1);
require dirname(__DIR__) . '/payload/app/src/bootstrap.php';

$svc=new ProgressionResourceService();

$mk=function(string $key,string $requirements,string $section='Upgrade Info'): array {
    return [
        'key'=>$key,'kind'=>'upgrade','section'=>$section,
        'from'=>'Stage A','from_norm'=>'stage a','to'=>'Stage B','to_norm'=>'stage b',
        'fields'=>['Required Items'=>$requirements],
        'source_title'=>'Fixture','source_url'=>'https://example.invalid/wiki/Fixture',
    ];
};
$plan=[
    'key'=>'plan1','title'=>'Progress Target','path'=>[
        $mk('a',"3 x Pluton\n2 x Riftborn Boulder"),
        $mk('b',"Pluton x5\n1 x Riftborn Boulder"),
    ],
    'actions'=>[
        $mk('c','10,000 x Beitetsu','Augmented Via'),
        $mk('d','S. Astral Detritus & M. Astral Detritus','Augmented Via'),
        $mk('e','Eschalixir, +1, or +2','Augmented Via'),
    ],
];
$holdings=[
    'pluton'=>['quantity'=>6,'source_type'=>'fixture'],
    'riftborn boulder'=>['quantity'=>3,'source_type'=>'fixture'],
    'beitetsu'=>['quantity'=>2500,'source_type'=>'fixture'],
];
$r=$svc->forPlan($plan,$holdings);
$by=[];foreach($r['requirements'] as $x)$by[$x['name_norm']]=$x;
$assert=function(bool $ok,string $msg): void {if(!$ok)throw new RuntimeException($msg);};
$assert(($by['pluton']['required_quantity']??null)===8,'Pluton should aggregate to 8.');
$assert(($by['pluton']['missing_quantity']??null)===2,'Pluton missing should be 2.');
$assert(($by['riftborn boulder']['required_quantity']??null)===3,'Riftborn Boulder should aggregate to 3.');
$assert(($by['riftborn boulder']['missing_quantity']??null)===0,'Riftborn Boulder should be complete.');
$assert(($by['beitetsu']['required_quantity']??null)===10000,'Beitetsu should parse comma quantity.');
$assert(($by['beitetsu']['missing_quantity']??null)===7500,'Beitetsu missing should be 7500.');
$assert(array_key_exists('s. astral detritus',$by) && $by['s. astral detritus']['required_quantity']===null,'Unquantified detritus must remain unknown.');
$assert(array_key_exists('m. astral detritus',$by) && $by['m. astral detritus']['required_quantity']===null,'Both ampersand resources should be preserved.');
$assert(count($r['alternative_groups'])===1,'Eschalixir variants should be one alternative group.');
$choices=array_map(static fn($x)=>$x['name'],$r['alternative_groups'][0]['choices']);
$assert($choices===['Eschalixir','Eschalixir +1','Eschalixir +2'],'Eschalixir shorthand alternatives were parsed incorrectly.');

echo "progression-resources-smoke: OK · fixed totals + unknown quantities + ANY-OF alternatives\n";
