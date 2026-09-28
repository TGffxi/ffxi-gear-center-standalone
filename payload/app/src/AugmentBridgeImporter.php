<?php
declare(strict_types=1);

final class AugmentBridgeImporter
{
    public function __construct(private PDO $pdo) {}

    public function import(string $path, int $characterId): array
    {
        if (!is_readable($path)) {
            throw new RuntimeException('Augment bridge snapshot not readable: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) throw new RuntimeException('Could not read augment bridge snapshot.');
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $supported=['ffxi-gear-center-augment-bridge-v1','ffxi-gear-center-live-snapshot-v2'];
        if (!is_array($data) || !in_array((string)($data['schema'] ?? ''),$supported,true)) {
            throw new RuntimeException('Unsupported or invalid augment bridge schema.');
        }
        $items = $data['items'] ?? null;
        if (!is_array($items)) throw new RuntimeException('Augment bridge snapshot has no items array.');
        // Never let an Ashita pre-login/partial frame replace a previously valid
        // physical inventory world with an empty bridge snapshot.  The main
        // runtime already keeps a last-known-good character snapshot; the SQLite
        // augment layer must obey the same contract.
        if (count($items) === 0) throw new RuntimeException('Augment bridge snapshot is empty; preserving last-known-good SQLite state.');
        $captured = (string)($data['captured_at'] ?? '');
        if ($captured === '') throw new RuntimeException('Augment bridge snapshot has no captured_at value.');
        $counter = (int)($data['inventory_counter'] ?? 0);
        $sha = hash('sha256', $raw);
        $now = gmdate('c');

        $same=$this->pdo->prepare("SELECT s.id AS snapshot_id,r.id AS run_id,s.captured_at,s.item_count FROM augment_bridge_import_runs r JOIN augment_bridge_snapshots s ON s.import_run_id=r.id WHERE r.character_id=:cid AND r.source_sha256=:sha AND r.status='ok' ORDER BY r.id DESC LIMIT 1");
        $same->execute([':cid'=>$characterId, ':sha'=>$sha]);
        if($existing=$same->fetch()){
            return ['run_id'=>(int)$existing['run_id'],'snapshot_id'=>(int)$existing['snapshot_id'],'captured_at'=>(string)$existing['captured_at'],'items'=>(int)$existing['item_count'],'sha256'=>$sha,'unchanged'=>true];
        }

        $latest=$this->pdo->prepare('SELECT captured_at FROM augment_bridge_snapshots WHERE character_id=:cid ORDER BY id DESC LIMIT 1');
        $latest->execute([':cid'=>$characterId]);
        $latestCaptured=(string)($latest->fetchColumn()?:'');
        if($latestCaptured!=='' && self::isOlderCapture($captured,$latestCaptured)){
            throw new RuntimeException('Augment bridge snapshot is older than the last-known-good SQLite snapshot; preserving newer state.');
        }

        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare('INSERT INTO augment_bridge_import_runs(character_id,source_path,source_sha256,started_at,status,captured_at,item_count) VALUES(:cid,:path,:sha,:now,\'running\',:captured,:count)');
            $run->execute([':cid'=>$characterId, ':path'=>$path, ':sha'=>$sha, ':now'=>$now, ':captured'=>$captured, ':count'=>count($items)]);
            $runId=(int)$this->pdo->lastInsertId();

            $snap = $this->pdo->prepare('INSERT INTO augment_bridge_snapshots(character_id,import_run_id,captured_at,imported_at,inventory_counter,item_count) VALUES(:cid,:rid,:captured,:now,:counter,:count)');
            $snap->execute([':cid'=>$characterId, ':rid'=>$runId, ':captured'=>$captured, ':now'=>$now, ':counter'=>$counter, ':count'=>count($items)]);
            $snapshotId=(int)$this->pdo->lastInsertId();

            $ins = $this->pdo->prepare('INSERT INTO augment_bridge_items(snapshot_id,item_id,bag_id,bag_slot,count,augment_type,aug_path,aug_rank,aug_trial,trial_complete,augment_strings_json,raw_extra_hex,augment_error,raw_json) VALUES(:sid,:item,:bag,:slot,:count,:type,:path,:rank,:trial,:complete,:strings,:extra,:error,:raw)');
            foreach ($items as $row) {
                if (!is_array($row)) continue;
                $itemId=(int)($row['item_id']??0); $bag=(int)($row['bag_id']??-1); $slot=(int)($row['bag_slot']??0);
                if ($itemId <= 0 || $bag < 0 || $slot < 0) continue;
                $strings=$row['augment_strings']??[]; if(!is_array($strings))$strings=[];
                $tc=$row['trial_complete']??null;
                $ins->execute([
                    ':sid'=>$snapshotId, ':item'=>$itemId, ':bag'=>$bag, ':slot'=>$slot, ':count'=>(int)($row['count']??1),
                    ':type'=>isset($row['augment_type'])?(string)$row['augment_type']:null,
                    ':path'=>isset($row['path'])?(string)$row['path']:null,
                    ':rank'=>isset($row['rank'])?(int)$row['rank']:null,
                    ':trial'=>isset($row['trial'])?(int)$row['trial']:null,
                    ':complete'=>is_bool($tc)?($tc?1:0):null,
                    ':strings'=>json_encode(array_values($strings), JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    ':extra'=>(string)($row['raw_extra_hex']??''),
                    ':error'=>isset($row['augment_error'])?(string)$row['augment_error']:null,
                    ':raw'=>json_encode($row, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                ]);
            }
            $done=$this->pdo->prepare('UPDATE augment_bridge_import_runs SET completed_at=:now,status=\'ok\' WHERE id=:id');
            $done->execute([':now'=>$now, ':id'=>$runId]);
            $this->pdo->commit();
            return ['run_id'=>$runId,'snapshot_id'=>$snapshotId,'captured_at'=>$captured,'items'=>count($items),'sha256'=>$sha];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    private static function isOlderCapture(string $candidate,string $current): bool
    {
        $a=strtotime($candidate);$b=strtotime($current);
        return $a!==false && $b!==false && $a<$b;
    }
}
