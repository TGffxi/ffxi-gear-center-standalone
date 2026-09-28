<?php
declare(strict_types=1);

final class RequirementMatcher
{
    private ?int $augmentSnapshotId = null;

    public function __construct(private PDO $pdo, private int $characterId, private int $snapshotId)
    {
        $s=$this->pdo->prepare('SELECT id FROM v_latest_augment_bridge_snapshots WHERE character_id=:cid LIMIT 1');
        $s->execute([':cid'=>$characterId]);
        $id=$s->fetchColumn();
        $this->augmentSnapshotId=$id!==false?(int)$id:null;
    }

    public function hasAugmentBridge(): bool { return $this->augmentSnapshotId !== null; }

    public function matchRequirement(array $req): array
    {
        $norm=(string)$req['item_name_norm'];
        $candidates=$this->owned($norm);
        if($candidates===[]) return ['status'=>'MISSING','label'=>'Missing','owned'=>0,'candidate'=>null,'detail'=>'Item not found in latest GearFinder snapshot.'];

        $hasStructured=($req['aug_path']??null)!==null || ($req['aug_rank']??null)!==null || ($req['aug_trial']??null)!==null;
        $wantedStrings=json_decode((string)($req['augment_strings_json']??'[]'),true);
        $wantedStrings=is_array($wantedStrings)?array_values($wantedStrings):[];
        $needsAugment=$hasStructured || $wantedStrings!==[];
        if(!$needsAugment) return ['status'=>'OWNED','label'=>'Owned','owned'=>count($candidates),'candidate'=>$candidates[0],'detail'=>'Base item found in latest GearFinder snapshot.'];

        $sawBridge=false;
        foreach($candidates as $c){
            if(($c['bridge_item_id']??null)===null) continue;
            $sawBridge=true;
            if(self::bridgeSatisfies($c,$req,$wantedStrings)){
                return ['status'=>'EXACT','label'=>'Exact augment','owned'=>count($candidates),'candidate'=>$c,'detail'=>'LuAshitacast bridge confirms Path/Rank/Trial/augment requirements from the live item Extra data.'];
            }
        }
        if($sawBridge){
            return ['status'=>'AUGMENT_MISMATCH','label'=>'Augment mismatch','owned'=>count($candidates),'candidate'=>$candidates[0],'detail'=>'Base item owned, but LuAshitacast bridge data does not satisfy this requirement.'];
        }
        return ['status'=>'OWNED_UNVERIFIED','label'=>'Owned · augment unread','owned'=>count($candidates),'candidate'=>$candidates[0],'detail'=>'Base item owned, but no matching live LuAshitacast augment reading is available yet.'];
    }

    public function setProgress(int $setId): array
    {
        $s=$this->pdo->prepare('SELECT * FROM lac_requirements WHERE set_id=:id ORDER BY id');$s->execute([':id'=>$setId]);
        $rows=$s->fetchAll();$done=0;$present=0;$unverified=0;$mismatch=0;$results=[];
        foreach($rows as $r){
            $m=$this->matchRequirement($r);$results[]=['requirement'=>$r,'match'=>$m];
            if(in_array($m['status'],['OWNED','EXACT'],true))$done++;
            if($m['status']!=='MISSING')$present++;
            if($m['status']==='OWNED_UNVERIFIED')$unverified++;
            if($m['status']==='AUGMENT_MISMATCH')$mismatch++;
        }
        $total=count($rows);
        return ['total'=>$total,'done'=>$done,'present'=>$present,'unverified'=>$unverified,'mismatch'=>$mismatch,'results'=>$results,'percent'=>$total?round($done*100/$total):100,'present_percent'=>$total?round($present*100/$total):100];
    }

    private function owned(string $norm): array
    {
        if($this->augmentSnapshotId!==null){
            $sql='SELECT esi.*,d.name,d.item_id,abi.id AS bridge_item_id,abi.augment_type AS bridge_type,abi.aug_path AS bridge_path,abi.aug_rank AS bridge_rank,abi.aug_trial AS bridge_trial,abi.trial_complete AS bridge_trial_complete,abi.augment_strings_json AS bridge_strings_json,abi.raw_extra_hex AS bridge_raw_extra,abi.augment_error AS bridge_error FROM equipment_snapshot_items esi JOIN item_definitions d ON d.item_id=esi.item_id LEFT JOIN augment_bridge_items abi ON abi.snapshot_id=:asid AND abi.item_id=esi.item_id AND abi.bag_id=esi.bag_id AND abi.bag_slot=esi.bag_slot WHERE esi.snapshot_id=:sid AND lower(trim(d.name))=:norm ORDER BY esi.bag_id,esi.bag_slot';
            $s=$this->pdo->prepare($sql);$s->execute([':asid'=>$this->augmentSnapshotId,':sid'=>$this->snapshotId,':norm'=>$norm]);return $s->fetchAll();
        }
        $s=$this->pdo->prepare('SELECT esi.*,d.name,d.item_id,NULL AS bridge_item_id FROM equipment_snapshot_items esi JOIN item_definitions d ON d.item_id=esi.item_id WHERE esi.snapshot_id=:sid AND lower(trim(d.name))=:norm ORDER BY esi.bag_id,esi.bag_slot');
        $s->execute([':sid'=>$this->snapshotId,':norm'=>$norm]);return $s->fetchAll();
    }

    private static function bridgeSatisfies(array $c,array $req,array $wantedStrings): bool
    {
        if(($c['bridge_error']??null)!==null && (string)$c['bridge_error']!=='') return false;
        if(($req['aug_path']??null)!==null && strcasecmp((string)($c['bridge_path']??''),(string)$req['aug_path'])!==0)return false;
        if(($req['aug_rank']??null)!==null && (int)($c['bridge_rank']??-999)!==(int)$req['aug_rank'])return false;
        if(($req['aug_trial']??null)!==null && (int)($c['bridge_trial']??-999)!==(int)$req['aug_trial'])return false;
        if($wantedStrings!==[]){
            $actual=json_decode((string)($c['bridge_strings_json']??'[]'),true);$actual=is_array($actual)?$actual:[];
            foreach($wantedStrings as $want){if(!in_array((string)$want,$actual,true))return false;}
        }
        return true;
    }
    public static function parseLacAugments(array $strings): array
    {
        // Retained for diagnostics/backward-compatible tests. Live verification no longer depends on GearFinder stat translation.
        $stats=[]; $complete=true;
        $add=static function(string $k,int $v) use (&$stats):void{$stats[$k]=($stats[$k]??0)+$v;};
        foreach($strings as $raw){
            $x=trim((string)$raw); $matched=false;
            if(preg_match('/^(STR|DEX|VIT|AGI|INT|MND|CHR)\+(-?\d+)$/i',$x,$m)){ $add(strtolower($m[1]),(int)$m[2]); $matched=true; }
            elseif(preg_match('/^Accuracy\+(\d+)\s+Attack\+(\d+)$/i',$x,$m)){ $add('acc',(int)$m[1]);$add('atk',(int)$m[2]);$matched=true; }
            elseif(preg_match('/^Accuracy\+(\d+)$/i',$x,$m)){ $add('acc',(int)$m[1]);$matched=true; }
            elseif(preg_match('/^Attack\+(\d+)$/i',$x,$m)){ $add('atk',(int)$m[1]);$matched=true; }
            elseif(preg_match('/^"?Dbl\.Atk\."?\+(\d+)$/i',$x,$m)){ $add('dblatk',(int)$m[1]);$matched=true; }
            elseif(preg_match('/^Damage taken-(\d+)%$/i',$x,$m)){ $add('dt',(int)$m[1]);$matched=true; }
            else $complete=false;
        }
        return ['stats'=>$stats,'complete'=>$complete];
    }

}
