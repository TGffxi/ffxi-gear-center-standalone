#!/usr/bin/env php
<?php
declare(strict_types=1);

$root=dirname(__DIR__);$app=$root.'/payload/app';
require $app.'/src/bootstrap.php';

function lkgAssert(bool $c,string $m): void { if(!$c)throw new RuntimeException($m); }
if(!extension_loaded('pdo_sqlite')){fwrite(STDERR,"SKIP: pdo_sqlite is not loaded.\n");exit(77);}
$source=(string)($argv[1]??($root.'/seed-data/gear-center.sqlite'));
lkgAssert(is_file($source),'Source DB missing: '.$source);
$tmp=sys_get_temp_dir().'/gearcenter-live-lkg-'.getmypid().'-'.bin2hex(random_bytes(3));
lkgAssert(mkdir($tmp,0700,true),'Could not create temp dir');
$db=$tmp.'/clone.sqlite';
try{
    $src=new PDO('sqlite:'.$source,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $src->exec('VACUUM INTO '.$src->quote($db));$src=null;
    $pdo=Database::connect($db);Database::migrate($pdo,$app.'/sql/schema.sql');
    $characterId=(int)$pdo->query('SELECT id FROM characters ORDER BY id LIMIT 1')->fetchColumn();
    if($characterId<=0){
        $now=gmdate('c');
        $q=$pdo->prepare('INSERT INTO characters(name,server_id,created_at,updated_at) VALUES(:name,NULL,:now,:now)');
        $q->execute([':name'=>'ExamplePlayer',':now'=>$now]);
        $characterId=(int)$pdo->lastInsertId();
    }
    lkgAssert($characterId>0,'Could not create disposable QA character');

    $write=function(string $name,string $captured,array $status,array $items)use($tmp): string{
        $payload=[
            'schema'=>'ffxi-gear-center-live-snapshot-v2','captured_at'=>$captured,'inventory_counter'=>9001,
            'profile_path'=>'/qa/ExamplePlayer_99999','collection_status'=>$status,
            'items'=>$items,
            'key_items'=>[['key_item_id'=>1,'name'=>'QA Key Item','owned'=>true]],
            'currencies'=>[['name'=>'Gil','quantity'=>123456]],
        ];
        $p=$tmp.'/'.$name;
        file_put_contents($p,json_encode($payload,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $p;
    };
    $item=[
        'item_id'=>21906,'item_name'=>'Kikoku','bag_id'=>11,'bag_name'=>'Wardrobe 3','bag_slot'=>45,'count'=>1,
        'is_equipment'=>true,'augment_type'=>'DynamisD','path'=>'A','rank'=>15,
        'augment_strings'=>['DMG +8','Blade: Metsu: Damage +20%','Ninjutsu casting time -20%'],'raw_extra_hex'=>'1c',
    ];
    $complete=['inventory'=>'COMPLETE','key_items'=>'COMPLETE','currencies'=>'COMPLETE','unresolved_item_names'=>0];
    $partial=['inventory'=>'COMPLETE','key_items'=>'COMPLETE','currencies'=>'PARTIAL','unresolved_item_names'=>0];

    $good=$write('good.json','2099-01-01T00:00:00Z',$complete,[$item]);
    $a=(new AugmentBridgeImporter($pdo))->import($good,$characterId);
    $r=(new ResourceSnapshotImporter($pdo))->import($good,$characterId);
    lkgAssert((int)$a['items']===1 && (int)$r['entries']>=3,'Complete live snapshot did not import');
    $goodResourceId=(int)$r['snapshot_id'];

    $empty=$write('empty.json','2099-01-02T00:00:00Z',$complete,[]);
    foreach([AugmentBridgeImporter::class,ResourceSnapshotImporter::class] as $class){
        $thrown=false;try{(new $class($pdo))->import($empty,$characterId);}catch(RuntimeException $e){$thrown=true;}
        lkgAssert($thrown,$class.' accepted a zero-item pre-login frame');
    }
    $latestResource=(int)$pdo->query('SELECT id FROM resource_inventory_snapshots WHERE character_id='.(int)$characterId.' ORDER BY id DESC LIMIT 1')->fetchColumn();
    lkgAssert($latestResource===$goodResourceId,'Zero-item frame replaced resource last-known-good');

    $partialPath=$write('partial.json','2099-01-03T00:00:00Z',$partial,[$item]);
    (new AugmentBridgeImporter($pdo))->import($partialPath,$characterId);
    $thrown=false;try{(new ResourceSnapshotImporter($pdo))->import($partialPath,$characterId);}catch(RuntimeException $e){$thrown=true;}
    lkgAssert($thrown,'Partial resource frame replaced complete last-known-good');
    $latestResource=(int)$pdo->query('SELECT id FROM resource_inventory_snapshots WHERE character_id='.(int)$characterId.' ORDER BY id DESC LIMIT 1')->fetchColumn();
    lkgAssert($latestResource===$goodResourceId,'Partial resource frame changed latest complete resource snapshot');

    $older=$write('older.json','2098-12-31T23:59:00Z',$complete,[$item]);
    $thrown=false;try{(new ResourceSnapshotImporter($pdo))->import($older,$characterId);}catch(RuntimeException $e){$thrown=true;}
    lkgAssert($thrown,'Older resource frame replaced newer last-known-good');

    echo "PASS: live SQLite LKG · complete imported · zero/partial/older preserved\n";
}finally{
    foreach(glob($tmp.'/*')?:[] as $f)@unlink($f);@rmdir($tmp);
}
