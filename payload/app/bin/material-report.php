#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/bootstrap.php';
$root=dirname(__DIR__);$config=Config::load($root);$item=trim((string)($argv[1]??''));
if($item===''){fwrite(STDERR,"Usage: php bin/material-report.php \"Item Name\"\n");exit(2);}
$pdo=Database::connect($config['db_path']);Database::migrate($pdo,$root.'/sql/schema.sql');
$s=$pdo->prepare('SELECT s.id,c.id AS character_id FROM v_latest_snapshots s JOIN characters c ON c.id=s.character_id WHERE lower(c.name)=lower(:name) LIMIT 1');
$s->execute([':name'=>$config['character']]);$snap=$s->fetch();if(!$snap)throw new RuntimeException('No GearFinder snapshot found.');
$cid=(int)$snap['character_id'];$sid=(int)$snap['id'];
$bs=$pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');$bs->execute([':cid'=>$cid]);$asid=$bs->fetchColumn();
$catalog=new ItemCatalog($pdo,$cid,$sid,$asid!==false?(int)$asid:null);$logical=$catalog->find($item);if(!$logical)throw new RuntimeException('Unknown catalog item: '.$item);
$repo=new BgWikiRepository($pdo);$chainSvc=new ProgressionChainService($repo);$chain=$chainSvc->build($logical['name']);if(($logical['status']??'')==='OWNED')$chain=$chainSvc->forOwnedFocus($chain);
$resRepo=new ResourceInventoryRepository($pdo,$cid);
$ownedCopies=$catalog->ownedCopies($logical['name']);
$liveRows=array_values(array_filter($ownedCopies,static fn(array $r): bool => ($r['bridge_item_id']??null)!==null));
$liveAugment=[
    'copy_count'=>count($liveRows),
    'rank'=>count($liveRows)===1 && ($liveRows[0]['bridge_rank']??null)!==null ? (int)$liveRows[0]['bridge_rank'] : null,
    'path'=>count($liveRows)===1 ? ($liveRows[0]['bridge_path']??null) : null,
    'type'=>count($liveRows)===1 ? ($liveRows[0]['bridge_type']??null) : null,
];
$summary=(new ProgressionResourceService())->summarize($chain,$resRepo->holdings(),$liveAugment);
echo 'Item: '.$logical['name']."\n";echo 'Resource inventory: '.$resRepo->status()['status']."\n";
if(!$summary['has_data']){echo "No structured material/currency requirements in the actionable plan.\n";exit(0);}
foreach($summary['plans'] as $plan){
    echo "\n== ".$plan['plan_title']." ==\n";
    foreach(($plan['systems']??[]) as $sys){
        $state=(string)($sys['remaining_state']??'PENDING_LIVE_AUGMENT_STATE');
        $remaining=match($state){
            'COMPLETE'=>'0 '.$sys['point_label'],
            'PARTIAL_RANK_NEXT_RP_UNREAD'=>number_format((int)$sys['remaining_min_points']).'–'.number_format((int)$sys['remaining_max_points']).' '.$sys['point_label'].' (range; NextRP unread)',
            'AMBIGUOUS_MULTIPLE_COPIES'=>'ambiguous (multiple copies)',
            'LIVE_RANK_UNAVAILABLE'=>'live rank unavailable',
            default=>'PENDING LIVE',
        };
        $rank=($sys['current_rank']??null)!==null?' · current Rank '.(int)$sys['current_rank']:'';
        echo sprintf("SYSTEM: %s · target Rank %d · full target %s %s%s · remaining=%s\n",
            $sys['label'],(int)$sys['target_rank'],number_format((int)$sys['total_points']),$sys['point_label'],$rank,$remaining);
        foreach($sys['accepted_resources'] as $choice){
            $owned=$choice['owned_quantity']===null?'?':(string)$choice['owned_quantity'];
            echo sprintf("  %-28s %5d %s/unit · owned=%s\n",$choice['name'],(int)$choice['points_per_unit'],$sys['point_label'],$owned);
        }
    }
    foreach($plan['requirements'] as $r){
        $req=$r['required_quantity']===null?'?':(string)$r['required_quantity'];$own=$r['owned_quantity']===null?'?':(string)$r['owned_quantity'];$miss=$r['missing_quantity']===null?'?':(string)$r['missing_quantity'];
        echo sprintf("%-30s required=%-8s owned=%-8s missing=%-8s %s\n",$r['name'],$req,$own,$miss,$r['state']);
    }
    foreach($plan['alternative_groups'] as $g){echo 'ANY OF: '.implode(' | ',array_map(static fn($x)=>$x['name'],$g['choices']))."\n";}
}
