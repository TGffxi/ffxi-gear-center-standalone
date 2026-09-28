<?php
declare(strict_types=1);

/**
 * Lazily synchronize the unified LuAshitacast live snapshot into the historical
 * SQLite world.  The main Gearplaner API reads the bridge JSON directly; item
 * details/material holdings read SQLite.  Keeping both worlds in sync prevents
 * a fresh FFXI login from coexisting with a days-old ResourceInventoryRepository
 * snapshot.
 *
 * This synchronizer never clears state.  Invalid, empty, partial or older source
 * frames are reported and the importers preserve last-known-good SQLite rows.
 */
final class LiveStateSynchronizer
{
    public function __construct(private PDO $pdo, private array $config) {}

    /** @return array<string,mixed> */
    public function sync(int $characterId, ?int $serverId=null): array
    {
        $path='';
        try{
            $loc=AugmentBridgeLocator::discover($this->config,$serverId);
            $path=(string)($loc['path']??'');
            if($path==='' || !is_file($path)){
                return ['status'=>'MISSING','path'=>$path,'message'=>'No local live bridge snapshot found; preserving SQLite last-known-good state.'];
            }
            $raw=file_get_contents($path);
            if($raw===false)throw new RuntimeException('Could not read local live bridge snapshot.');
            $payload=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            if(!is_array($payload))throw new RuntimeException('Local live bridge snapshot is not a JSON object.');
            $schema=(string)($payload['schema']??'');
            $items=$payload['items']??null;
            if(!is_array($items))throw new RuntimeException('Local live bridge snapshot has no items array.');
            if(count($items)===0)throw new RuntimeException('Local live bridge snapshot has zero physical items (pre-login/partial); preserving last-known-good SQLite state.');

            // For the unified v2 payload, physical inventory completeness is the
            // prerequisite for replacing the physical/augment world.  Key items
            // and currencies may still be partial; ResourceSnapshotImporter then
            // independently protects a previously complete resource snapshot.
            if($schema==='ffxi-gear-center-live-snapshot-v2'){
                $status=is_array($payload['collection_status']??null)?$payload['collection_status']:[];
                if(strtoupper(trim((string)($status['inventory']??'')))!=='COMPLETE'){
                    throw new RuntimeException('Local live bridge inventory collector is not COMPLETE; preserving last-known-good physical state.');
                }
            }

            $augment=(new AugmentBridgeImporter($this->pdo))->import($path,$characterId);
            $resources=null;
            $resourceError=null;
            if($schema==='ffxi-gear-center-live-snapshot-v2'){
                try{
                    $resources=(new ResourceSnapshotImporter($this->pdo))->import($path,$characterId);
                }catch(Throwable $e){
                    // A complete older resource snapshot is preferable to a new
                    // partial one.  Augment inventory can still be current.
                    $resourceError=$e->getMessage();
                }
            }

            $changed=empty($augment['unchanged']) || ($resources!==null && empty($resources['unchanged']));
            return [
                'status'=>$resourceError===null?($changed?'UPDATED':'UNCHANGED'):'PARTIAL',
                'path'=>$path,
                'schema'=>$schema,
                'captured_at'=>(string)($payload['captured_at']??''),
                'items'=>count($items),
                'augment'=>$augment,
                'resources'=>$resources,
                'resource_error'=>$resourceError,
                'message'=>$resourceError===null
                    ? 'Live SQLite state synchronized from '.basename($path).'.'
                    : 'Physical/augment state synchronized; resource last-known-good preserved: '.$resourceError,
            ];
        }catch(Throwable $e){
            return [
                'status'=>'PRESERVED','path'=>$path,
                'message'=>$e->getMessage(),
                'error'=>$e->getMessage(),
            ];
        }
    }
}
