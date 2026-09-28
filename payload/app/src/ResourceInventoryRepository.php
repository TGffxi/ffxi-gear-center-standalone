<?php
declare(strict_types=1);

/** Read-only abstraction for ordinary items, key items and currencies. */
final class ResourceInventoryRepository
{
    public function __construct(private PDO $pdo, private int $characterId) {}

    public function latestSnapshot(): ?array
    {
        $s=$this->pdo->prepare(
            "SELECT * FROM resource_inventory_snapshots
             WHERE character_id=:cid
             ORDER BY id DESC LIMIT 1"
        );
        $s->execute([':cid'=>$this->characterId]);
        return $s->fetch() ?: null;
    }

    /** @return array<string,array<string,mixed>> */
    public function holdings(): array
    {
        $snapshot=$this->latestSnapshot();
        if(!$snapshot)return [];
        $s=$this->pdo->prepare(
            "SELECT resource_name_norm,MAX(resource_name) AS resource_name,
                    MAX(resource_kind) AS resource_kind,SUM(quantity) AS quantity,
                    GROUP_CONCAT(DISTINCT location_label) AS locations
             FROM resource_inventory_entries
             WHERE snapshot_id=:sid
               AND resource_kind<>'transient_item'
             GROUP BY resource_name_norm"
        );
        $s->execute([':sid'=>$snapshot['id']]);
        $out=[];
        foreach($s->fetchAll() as $row){
            $out[(string)$row['resource_name_norm']]=[
                'name'=>(string)$row['resource_name'],
                'kind'=>(string)$row['resource_kind'],
                'quantity'=>(int)$row['quantity'],
                'locations'=>(string)($row['locations']??''),
                'snapshot_id'=>(int)$snapshot['id'],
                'captured_at'=>(string)$snapshot['captured_at'],
                'source_type'=>(string)$snapshot['source_type'],
            ];
        }
        return $out;
    }

    public function status(): array
    {
        $snapshot=$this->latestSnapshot();
        if(!$snapshot)return [
            'status'=>'PENDING',
            'message'=>'No non-equipment resource snapshot yet; quantity verification is deferred to the consolidated FFXI test.',
            'snapshot'=>null,
        ];
        $s=$this->pdo->prepare('SELECT * FROM resource_inventory_collection_status WHERE snapshot_id=:sid');
        $s->execute([':sid'=>$snapshot['id']]);
        $collector=$s->fetch()?:null;
        $states=$collector?[(string)$collector['inventory_status'],(string)$collector['key_items_status'],(string)$collector['currencies_status']]:[];
        $complete=$states!==[]&&count(array_filter($states,static fn(string $v): bool=>$v==='COMPLETE'))===count($states);
        $suffix=$collector
            ? ' · inventory '.$collector['inventory_status'].' · key items '.$collector['key_items_status'].' · currencies '.$collector['currencies_status']
            : '';
        return [
            'status'=>$complete?'OK':'PARTIAL',
            'message'=>'Resource quantities available from '.(string)$snapshot['source_type'].' · '.(string)$snapshot['captured_at'].$suffix,
            'snapshot'=>$snapshot,'collectors'=>$collector,
        ];
    }
}
