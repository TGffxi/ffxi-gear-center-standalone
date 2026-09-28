<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
require dirname(__DIR__) . '/src/RuntimePlanningOverlayExporter.php';

$root=dirname(__DIR__);
$config=Config::load($root);
$output=getenv('GEARCENTER_RUNTIME_OVERLAY') ?: (getenv('HOME').'/Games/FFXI/data/Gearplaner/runtime-planning-overlay.json');
foreach(array_slice($argv,1) as $arg){
    if(str_starts_with($arg,'--output='))$output=substr($arg,9);
}
$pdo=Database::connect($config['db_path']);
Database::migrate($pdo,$root.'/sql/schema.sql');
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');
$s->execute([':name'=>$config['character']]);
$snapshot=$s->fetch();
if(!$snapshot){
    $payload=[
        'schema'=>RuntimePlanningOverlayExporter::SCHEMA,
        'generated_at'=>gmdate('c'),'character'=>['name'=>$config['character']],
        'snapshot_id'=>null,'augment_snapshot_id'=>null,
        'requirements'=>['schema'=>'gearplaner.requirements/0.6','tasks'=>[]],
        'goals'=>['schema'=>'gearplaner.goals/0.6','goals'=>[]],
        'progression_todos'=>[],
        'evidence'=>['status'=>'NO_IMPORTED_SNAPSHOT'],
        'rules'=>['sqlite_goals_are_explicit_personal_goals'=>true,'structured_bgwiki_only'=>true,'unknown_quantity_is_null'=>true,'alternatives_are_not_summed'=>true],
    ];
    $dir=dirname($output);if(!is_dir($dir))mkdir($dir,0775,true);
    file_put_contents($output,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n",LOCK_EX);
    fwrite(STDOUT,"Runtime planning overlay: no imported snapshot; wrote empty overlay to $output\n");
    exit(0);
}
$cid=(int)$snapshot['character_id'];$sid=(int)$snapshot['id'];
$b=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$b->execute([':cid'=>$cid]);$bid=$b->fetchColumn();
$exporter=new RuntimePlanningOverlayExporter($pdo,$cid,$sid,$bid===false?null:(int)$bid);
$payload=$exporter->write($output);
fwrite(STDOUT,sprintf("Runtime planning overlay: %d SQLite goal tasks, %d progression To-Dos -> %s\n",count($payload['requirements']['tasks']??[]),count($payload['progression_todos']??[]),$output));
