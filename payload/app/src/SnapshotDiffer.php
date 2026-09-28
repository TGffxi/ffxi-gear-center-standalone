<?php
declare(strict_types=1);

final class SnapshotDiffer
{
    public function __construct(private PDO $pdo, private int $characterId) {}

    public function latest(): array
    {
        $s=$this->pdo->prepare('SELECT id,captured_at,item_count FROM snapshots WHERE character_id=:cid ORDER BY id DESC LIMIT 2');
        $s->execute([':cid'=>$this->characterId]);$sn=$s->fetchAll();
        if(count($sn)<2)return ['available'=>false,'current'=>$sn[0]??null,'previous'=>null,'added'=>[],'removed'=>[],'moved'=>[],'augment_changed'=>[]];
        [$cur,$prev]=$sn;
        $a=$this->items((int)$cur['id']);$b=$this->items((int)$prev['id']);
        $curLoc=[];$prevLoc=[];
        foreach($a as $r)$curLoc[$r['bag_id'].':'.$r['bag_slot']]=$r;
        foreach($b as $r)$prevLoc[$r['bag_id'].':'.$r['bag_slot']]=$r;
        $augment=[];
        foreach($curLoc as $loc=>$r){if(isset($prevLoc[$loc])&&(int)$prevLoc[$loc]['item_id']===(int)$r['item_id']&&$prevLoc[$loc]['augment_signature']!==$r['augment_signature'])$augment[]=['before'=>$prevLoc[$loc],'after'=>$r];}
        $group=function(array $rows):array{$o=[];foreach($rows as $r){$k=$r['item_id'].'|'.$r['augment_signature'];$o[$k][]=$r;}return $o;};
        $ga=$group($a);$gb=$group($b);$added=[];$removed=[];$moved=[];
        foreach(array_unique(array_merge(array_keys($ga),array_keys($gb))) as $k){$ca=$ga[$k]??[];$cb=$gb[$k]??[];$na=count($ca);$nb=count($cb);if($na>$nb)$added[]=array_merge($ca[0],['delta'=>$na-$nb]);elseif($nb>$na)$removed[]=array_merge($cb[0],['delta'=>$nb-$na]);elseif($na>0){$la=array_map(fn($r)=>$r['bag_id'].':'.$r['bag_slot'],$ca);$lb=array_map(fn($r)=>$r['bag_id'].':'.$r['bag_slot'],$cb);sort($la);sort($lb);if($la!==$lb)$moved[]=['item'=>$ca[0],'from'=>$lb,'to'=>$la];}}
        return ['available'=>true,'current'=>$cur,'previous'=>$prev,'added'=>$added,'removed'=>$removed,'moved'=>$moved,'augment_changed'=>$augment];
    }

    private function items(int $sid): array
    {
        $s=$this->pdo->prepare('SELECT esi.*,d.name FROM equipment_snapshot_items esi JOIN item_definitions d ON d.item_id=esi.item_id WHERE esi.snapshot_id=:sid ORDER BY esi.item_id,esi.bag_id,esi.bag_slot');$s->execute([':sid'=>$sid]);return $s->fetchAll();
    }
}
