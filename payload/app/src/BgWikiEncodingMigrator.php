<?php
declare(strict_types=1);

/** Transactional, idempotent migration for legacy structured BG-Wiki mojibake. */
final class BgWikiEncodingMigrator
{
    public function __construct(private PDO $pdo) {}

    /** @return array{rows:int,strings:int,remaining:int} */
    public function run(): array
    {
        $rows=$this->pdo->query('SELECT id,fields_json FROM bgwiki_progression_records ORDER BY id')->fetchAll();
        $update=$this->pdo->prepare('UPDATE bgwiki_progression_records SET fields_json=:json WHERE id=:id');
        $changedRows=0;$changedStrings=0;
        $this->pdo->beginTransaction();
        try{
            foreach($rows as $row){
                $decoded=json_decode((string)$row['fields_json'],true,512,JSON_THROW_ON_ERROR);
                [$fixed,$n]=self::repairStructuredValue($decoded);
                if($n===0)continue;
                $json=json_encode($fixed,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $update->execute([':json'=>$json,':id'=>(int)$row['id']]);
                $changedRows++;$changedStrings+=$n;
            }
            $this->pdo->commit();
        }catch(Throwable $e){
            if($this->pdo->inTransaction())$this->pdo->rollBack();
            throw $e;
        }

        $remaining=0;
        foreach($this->pdo->query('SELECT fields_json FROM bgwiki_progression_records')->fetchAll() as $row){
            $decoded=json_decode((string)$row['fields_json'],true,512,JSON_THROW_ON_ERROR);
            [, $n]=self::repairStructuredValue($decoded);$remaining+=$n;
        }
        if($remaining!==0)throw new RuntimeException('BG-Wiki encoding migration left '.$remaining.' repairable strings.');
        return ['rows'=>$changedRows,'strings'=>$changedStrings,'remaining'=>$remaining];
    }

    /** @return array{0:mixed,1:int} */
    private static function repairStructuredValue(mixed $value): array
    {
        if(is_string($value)){
            $fixed=BgWikiExtractor::repairLegacyMojibakeString($value);
            return [$fixed,$fixed===$value?0:1];
        }
        if(!is_array($value))return [$value,0];
        $out=[];$changes=0;
        foreach($value as $key=>$child){
            $newKey=$key;
            if(is_string($key)){
                $newKey=BgWikiExtractor::repairLegacyMojibakeString($key);
                if($newKey!==$key)$changes++;
            }
            [$fixed,$n]=self::repairStructuredValue($child);$changes+=$n;
            if(array_key_exists($newKey,$out) && $newKey!==$key){
                throw new RuntimeException('Encoding repair would create duplicate JSON key: '.$newKey);
            }
            $out[$newKey]=$fixed;
        }
        return [$out,$changes];
    }
}
