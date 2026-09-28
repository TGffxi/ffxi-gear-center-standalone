#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$jobs=array_keys(AllJobsTargetParser::jobs());$prefetch=true;$verify=true;$maxDepth=4;$maxFetch=500;$progressChunk=25;$progressEvery=100;$verifyEvenIfLimited=false;
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--jobs=')){$v=strtoupper(trim(substr($arg,7)));$jobs=$v==='ALL'?array_keys(AllJobsTargetParser::jobs()):array_values(array_filter(array_map('trim',explode(',',$v))));}
    elseif($arg==='--no-prefetch')$prefetch=false;
    elseif($arg==='--no-verify')$verify=false;
    elseif($arg==='--verify-even-if-prefetch-limited')$verifyEvenIfLimited=true;
    elseif(str_starts_with($arg,'--max-depth='))$maxDepth=max(1,(int)substr($arg,12));
    elseif(str_starts_with($arg,'--max-fetch='))$maxFetch=max(1,(int)substr($arg,12));
    elseif(str_starts_with($arg,'--progress-chunk='))$progressChunk=max(1,(int)substr($arg,17));
    elseif(str_starts_with($arg,'--progress-every='))$progressEvery=max(1,(int)substr($arg,17));
}
foreach($jobs as $job)if(!isset(AllJobsTargetParser::jobs()[$job]))throw new InvalidArgumentException('Unknown job: '.$job);

$repo=new TargetRepository($pdo);$rows=[];$items=[];$counts=[];
foreach($jobs as $job){
    $jr=$repo->latestItems($job);$counts[$job]=count($jr);
    foreach($jr as $r){
        $rows[]=$r;
        $name=trim((string)($r['source_link_title']?:$r['source_item_name']));
        if($name!=='')$items[LacProfileParser::normalizeName($name)]=$name;
    }
}

echo "===== VERIFY EXISTING TARGET CATALOG v2 =====\n";
echo "No guide re-import is performed. Existing Target rows only.\n";
echo 'Jobs: '.implode(', ',$jobs)."\n";
echo 'Target rows: '.count($rows).' · unique item pages: '.count($items)."\n";
foreach($counts as $job=>$n) echo sprintf("  %-3s %4d rows\n",$job,$n);
if(!$rows){fwrite(STDERR,"[ERR] Existing target catalog is empty for requested jobs.\n");exit(2);}

$prefetchLimited=false;
if($prefetch){
    echo "\n===== TARGET ITEM PREFETCH (progress-aware) =====\n";
    echo "Budget this run: {$maxFetch} new pages · batch: {$progressChunk} · depth: {$maxDepth}\n";
    fflush(STDOUT);
    $service=new TargetPrefetchService($pdo);
    $remaining=$maxFetch;$totalFetched=0;$batch=0;$lastCached=0;$allMissing=[];$allErrors=[];$started=microtime(true);
    while($remaining>0){
        $batch++;$chunk=min($progressChunk,$remaining);$t0=microtime(true);
        $p=$service->prefetch(array_values($items),$maxDepth,$chunk);
        $fetched=count($p['fetched']??[]);$lastCached=count($p['already_cached']??[]);
        $totalFetched+=$fetched;$remaining-=$fetched;
        foreach(($p['missing']??[]) as $k=>$v)$allMissing[$k]=$v;
        foreach(($p['errors']??[]) as $k=>$v)$allErrors[$k]=$v;
        $dt=max(0.001,microtime(true)-$t0);$elapsed=max(0.001,microtime(true)-$started);
        printf("[%02d] +%d fetched · cached %d · total +%d/%d · %.1fs batch · %.1fs total\n",
            $batch,$fetched,$lastCached,$totalFetched,$maxFetch,$dt,$elapsed);
        fflush(STDOUT);
        $limited=($p['limit_reached']??false)===true;
        if(!$limited){$prefetchLimited=false;break;}
        if($fetched===0){
            $prefetchLimited=true;
            echo "[WARN] Prefetch reports more work but fetched no new page; stopping to avoid a loop.\n";
            break;
        }
        if($remaining<=0){$prefetchLimited=true;break;}
    }
    echo 'Unique requested: '.count($items)."\nFetched this run: {$totalFetched} · currently cached: {$lastCached} · missing: ".count($allMissing).' · errors: '.count($allErrors)."\n";
    foreach(array_slice($allMissing,0,20,true) as $name=>$err)echo "[MISS] $name · $err\n";
    foreach(array_slice($allErrors,0,20,true) as $name=>$err)echo "[ERR] $name · $err\n";
    if($prefetchLimited){
        echo "[WARN] Prefetch limit reached; rerun this exact command to resume from cache.\n";
        if($verify && !$verifyEvenIfLimited){
            echo "[INFO] STRICT verification is deferred until prefetch is complete, avoiding another full 6k+ row verification on every resume pass.\n";
            $verify=false;
        }
    }else{
        echo "[OK] Prefetch complete for the currently reachable target dependency graph.\n";
    }
}

if($verify){
    echo "\n===== STRICT TARGET VERIFICATION (progress-aware) =====\n";
    $v=new TargetVerificationService($pdo);$n=0;$strict=0;$total=count($rows);$started=microtime(true);
    foreach($rows as $r){
        $res=$v->verify($r);$repo->saveVerification((int)$r['id'],$res);$n++;
        if(($res['strict_status']??'')==='VERIFIED')$strict++;
        if($n===$total || $n%$progressEvery===0){
            $elapsed=max(0.001,microtime(true)-$started);$rate=$n/$elapsed;$left=max(0,$total-$n);$eta=$rate>0?$left/$rate:0;
            printf("[%5d/%5d] strict=%d · review=%d · %.1f rows/s · ETA %s\n",
                $n,$total,$strict,$n-$strict,$rate,$eta>=60?sprintf('%.1f min',$eta/60):sprintf('%.0f s',$eta));
            fflush(STDOUT);
        }
    }
    echo "Verified rows: $n · strict: $strict · review: ".($n-$strict)."\n";
}else{
    echo "\n[INFO] Verification not run in this pass.\n";
}

echo "\nRun next when prefetch is complete: php bin/global-target-report.php\n";
