#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/payload/app/src/bootstrap.php';

$cases=[
    "â€œStore TPâ€\u{009D}"=>'“Store TP”',
    '-5%â€¡'=>'-5%‡',
    'Normal UTF-8 – “quoted”'=>'Normal UTF-8 – “quoted”',
    'Mâcon'=>'Mâcon',
];
foreach($cases as $bad=>$expected){
    $actual=BgWikiExtractor::repairLegacyMojibakeString($bad);
    if($actual!==$expected)throw new RuntimeException('Encoding repair mismatch: '.json_encode([$bad,$actual,$expected],JSON_UNESCAPED_UNICODE));
}
echo "bgwiki encoding repair smoke: OK · strict reversible repair only\n";

if(!class_exists('DOMDocument') || !class_exists('DOMXPath')){
    echo "bgwiki UTF-8 DOM parse smoke: SKIP · DOM extension unavailable\n";
    exit(0);
}
$html='<!doctype html><html><body><div class="mw-parser-output"><h2>Augmented Via</h2><table>'
    .'<tr><th>Abdhaljs Thread</th><th>Aug/Item</th><th>Max Aug</th></tr>'
    .'<tr><td>“Store TP”</td><td>+1</td><td>+10</td></tr>'
    .'<tr><td>“Double Attack”</td><td>+1%</td><td>+10%</td></tr>'
    .'</table></div></body></html>';
$records=BgWikiExtractor::progressionRecords($html,'html');
$json=json_encode($records,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
foreach(['“Store TP”','“Double Attack”'] as $needle){
    if(!str_contains($json,$needle))throw new RuntimeException('UTF-8 DOM fragment parse corrupted '.$needle.': '.$json);
}
foreach(['â€œ','â€','Ã'] as $bad){
    if(str_contains($json,$bad))throw new RuntimeException('UTF-8 DOM fragment parse produced mojibake: '.$json);
}
echo "bgwiki UTF-8 DOM parse smoke: OK · curly quotes preserved\n";
