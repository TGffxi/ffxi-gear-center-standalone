<?php
declare(strict_types=1);

/** Imports the inventory/resource portion of the unified Ashita snapshot. */
final class ResourceSnapshotImporter
{
    private const SCHEMA='ffxi-gear-center-live-snapshot-v2';
    private const SOURCE_TYPE='ashita_live_snapshot_v2';

    public function __construct(private PDO $pdo) {}

    public function import(string $path,int $characterId): array
    {
        if(!is_readable($path))throw new RuntimeException('Live snapshot not readable: '.$path);
        $raw=file_get_contents($path);
        if($raw===false)throw new RuntimeException('Could not read live snapshot.');
        $data=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
        if(!is_array($data)||($data['schema']??'')!==self::SCHEMA){
            throw new RuntimeException('Unsupported or invalid resource snapshot schema.');
        }
        $captured=(string)($data['captured_at']??'');
        if($captured==='')throw new RuntimeException('Live snapshot has no captured_at value.');
        foreach(['items','key_items','currencies'] as $key){
            if(!isset($data[$key])||!is_array($data[$key])){
                throw new RuntimeException('Live snapshot has no '.$key.' array.');
            }
        }
        if(count($data['items'])===0){
            throw new RuntimeException('Live resource snapshot contains zero physical items; preserving last-known-good SQLite resource state.');
        }

        $sha=hash('sha256',$raw);
        $same=$this->pdo->prepare(
            'SELECT id,captured_at,entry_count FROM resource_inventory_snapshots '
            .'WHERE character_id=:cid AND source_type=:type AND source_sha256=:sha ORDER BY id DESC LIMIT 1'
        );
        $same->execute([':cid'=>$characterId,':type'=>self::SOURCE_TYPE,':sha'=>$sha]);
        if($existing=$same->fetch()){
            return [
                'snapshot_id'=>(int)$existing['id'],'captured_at'=>(string)$existing['captured_at'],
                'entries'=>(int)$existing['entry_count'],'sha256'=>$sha,'unchanged'=>true,
                'collection_status'=>$data['collection_status']??[],
            ];
        }

        $latest=$this->pdo->prepare(
            'SELECT s.captured_at,c.inventory_status,c.key_items_status,c.currencies_status '
            .'FROM resource_inventory_snapshots s '
            .'LEFT JOIN resource_inventory_collection_status c ON c.snapshot_id=s.id '
            .'WHERE s.character_id=:cid ORDER BY s.id DESC LIMIT 1'
        );
        $latest->execute([':cid'=>$characterId]);
        $previous=$latest->fetch()?:null;
        if($previous && self::isOlderCapture($captured,(string)$previous['captured_at'])){
            throw new RuntimeException('Live resource snapshot is older than the last-known-good SQLite resource snapshot; preserving newer state.');
        }
        $candidateStatus=is_array($data['collection_status']??null)?$data['collection_status']:[];
        if($previous && self::collectorComplete($previous) && !self::collectorComplete($candidateStatus)){
            throw new RuntimeException('Live resource snapshot is partial while SQLite has a complete last-known-good snapshot; preserving complete state.');
        }

        $entries=[];
        foreach($data['items'] as $row){
            if(!is_array($row))continue;
            $name=trim((string)($row['item_name']??''));
            $itemId=(int)($row['item_id']??0);
            $quantity=(int)($row['count']??0);
            $bag=(int)($row['bag_id']??-1);
            $slot=(int)($row['bag_slot']??-1);
            if($name===''||$itemId<=0||$quantity<=0||$bag<0||$slot<0)continue;
            $bagName=trim((string)($row['bag_name']??''));
            if($bagName==='')$bagName=BagMap::name($bag);
            $kind=in_array($bag,[3,17],true)
                ? 'transient_item'
                : (!empty($row['is_equipment'])?'equipment':'item');
            $entries[]=$this->entry(
                $name,$kind,$itemId,$quantity,$bagName.'/'.$slot,$row
            );
        }
        foreach($data['key_items'] as $row){
            if(!is_array($row)||empty($row['owned']))continue;
            $id=(int)($row['key_item_id']??-1);
            if($id<0)continue;
            $name=trim((string)($row['name']??''));
            if($name==='')$name='Key Item #'.$id;
            $entries[]=$this->entry($name,'key_item',null,1,'Key Items',$row);
        }
        foreach($data['currencies'] as $row){
            if(!is_array($row))continue;
            $name=trim((string)($row['name']??''));
            $quantity=(int)($row['quantity']??0);
            if($name===''||$quantity<0)continue;
            $entries[]=$this->entry($name,'currency',null,$quantity,'Currencies',$row);
        }

        $now=gmdate('c');
        $this->pdo->beginTransaction();
        try{
            $snap=$this->pdo->prepare(
                'INSERT INTO resource_inventory_snapshots(character_id,source_type,source_ref,source_sha256,captured_at,imported_at,entry_count) '
                .'VALUES(:cid,:type,:ref,:sha,:captured,:now,:count)'
            );
            $snap->execute([
                ':cid'=>$characterId,':type'=>self::SOURCE_TYPE,':ref'=>$path,':sha'=>$sha,
                ':captured'=>$captured,':now'=>$now,':count'=>count($entries),
            ]);
            $snapshotId=(int)$this->pdo->lastInsertId();
            $ins=$this->pdo->prepare(
                'INSERT INTO resource_inventory_entries(snapshot_id,resource_name_norm,resource_name,resource_kind,item_id,quantity,location_label,raw_json) '
                .'VALUES(:sid,:norm,:name,:kind,:item,:quantity,:location,:raw)'
            );
            foreach($entries as $entry){
                $ins->execute([':sid'=>$snapshotId]+$entry);
            }
            $status=is_array($data['collection_status']??null)?$data['collection_status']:[];
            $health=$this->pdo->prepare(
                'INSERT INTO resource_inventory_collection_status(snapshot_id,inventory_status,key_items_status,currencies_status,unresolved_item_names,detail_json) '
                .'VALUES(:sid,:inventory,:key_items,:currencies,:unresolved,:detail)'
            );
            $health->execute([
                ':sid'=>$snapshotId,':inventory'=>(string)($status['inventory']??'UNKNOWN'),
                ':key_items'=>(string)($status['key_items']??'UNKNOWN'),
                ':currencies'=>(string)($status['currencies']??'UNKNOWN'),
                ':unresolved'=>(int)($status['unresolved_item_names']??0),
                ':detail'=>json_encode($status,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
            ]);
            $this->pdo->commit();
            return [
                'snapshot_id'=>$snapshotId,'captured_at'=>$captured,'entries'=>count($entries),
                'sha256'=>$sha,'unchanged'=>false,'collection_status'=>$data['collection_status']??[],
            ];
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }
    }

    private static function isOlderCapture(string $candidate,string $current): bool
    {
        $a=strtotime($candidate);$b=strtotime($current);
        return $a!==false && $b!==false && $a<$b;
    }

    private static function collectorComplete(array $status): bool
    {
        foreach(['inventory','key_items','currencies'] as $key){
            $value=$status[$key]??$status[$key.'_status']??null;
            if(strtoupper(trim((string)$value))!=='COMPLETE')return false;
        }
        return true;
    }

    private function entry(string $name,string $kind,?int $itemId,int $quantity,string $location,array $raw): array
    {
        return [
            ':norm'=>LacProfileParser::normalizeName($name),':name'=>$name,':kind'=>$kind,
            ':item'=>$itemId,':quantity'=>$quantity,':location'=>$location,
            ':raw'=>json_encode($raw,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
        ];
    }
}
