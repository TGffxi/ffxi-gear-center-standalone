#!/usr/bin/env php
<?php
declare(strict_types=1);
$root=dirname(__DIR__);
require $root.'/src/bootstrap.php';
require_once $root.'/src/TargetRequirementsExporter.php';
$config=Config::load($root);$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$jobs=array_keys(AllJobsTargetParser::jobs());
$out=getenv('GEARCENTER_REQUIREMENTS')?:getenv('HOME').'/Games/FFXI/data/Gearplaner/requirements.json';
foreach(array_slice($argv,1) as $arg){
  if(str_starts_with($arg,'--jobs=')){$v=strtoupper(trim(substr($arg,7)));$jobs=$v==='ALL'?array_keys(AllJobsTargetParser::jobs()):array_values(array_filter(array_map('trim',explode(',',$v))));}
  elseif(str_starts_with($arg,'--out='))$out=substr($arg,6);
}
foreach($jobs as $job)if(!isset(AllJobsTargetParser::jobs()[$job]))throw new InvalidArgumentException('Unknown job: '.$job);
$generated=TargetRequirementsExporter::build($pdo,$jobs);
$existing=['schema'=>'gearplaner.requirements/0.6','tasks'=>[]];
if(is_file($out)){
  $d=json_decode((string)file_get_contents($out),true);
  if(is_array($d)){
    if(($d['schema']??'')==='gearplaner.requirements/0.5')$d['schema']='gearplaner.requirements/0.6';
    $existing=$d;
  }
}
$merged=TargetRequirementsExporter::mergePreservingManual($existing,$generated);
$dir=dirname($out);if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('Could not create '.$dir);
$tmp=$out.'.tmp';$bak=$out.'.bak';if(is_file($out))copy($out,$bak);
$json=json_encode($merged,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('JSON encode failed');
file_put_contents($tmp,$json."\n",LOCK_EX);rename($tmp,$out);
echo "===== GEAR CENTER 1.0: GUIDE-SETS -> PLANUNG =====\n";
echo 'Jobs: '.count($generated['meta']['jobs'])."\n";
echo 'Target rows: '.$generated['meta']['target_rows']."\n";
echo 'Guide tasks: '.$generated['meta']['task_count']."\n";
echo 'Preserved non-generated tasks: '.($merged['meta']['preserved_non_generated_tasks']??0)."\n";
echo 'Requirements: '.$out."\n";
echo "[OK] Existing verified target catalog only; no network fetch or verification.\n";
echo "[OK] No personal goal was created or removed.\n";
