#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';

$parser=new AllJobsTargetParser();
$failed=false;
foreach(['WAR','NIN'] as $job){
    // Main Page intentionally triggers the structured-primary path; this
    // reproduces the exact broken v0.4 importer condition without touching DB.
    $r=$parser->parseJobPage('<html><body>Main Page</body></html>',$job,'Main Page');
    $sets=$r['sets']??[];$targets=0;$variants=[];$slots=[];
    foreach($sets as $set){
        $targets+=count($set['items']??[]);
        $variants[(string)($set['variant_name']??'')]=true;
        foreach($set['items']??[] as $item){$slots[(string)($item['slot_name']??'')]=true;}
    }
    $ok=count($sets)>0 && $targets>0 && isset($slots['Main']) && isset($slots['Head']);
    printf("[%s] %-3s %3d sets · %4d targets · variants=%d · source=%s\n",
        $ok?'OK':'ERR',$job,count($sets),$targets,count($variants),(string)($r['source_kind']??'?'));
    if(!$ok)$failed=true;
}
if($failed) exit(2);
echo "bg_job_guides primary smoke: OK\n";
