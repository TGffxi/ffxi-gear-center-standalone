<?php
declare(strict_types=1);

final class LacImporter
{
    public function __construct(private PDO $pdo) {}

    public function import(array $lac, int $characterId): array
    {
        $parser = new LacProfileParser();
        $profiles = [];
        foreach ($lac['profiles'] as $job => $path) {
            if (!in_array($job, ['NIN','THF'], true)) continue; // alpha2 scope
            $profiles[] = $parser->parseJob($job, $path);
        }
        if (!empty($lac['gcinclude'])) $profiles[] = $parser->parseCommon($lac['gcinclude']);

        $now = gmdate('c');
        $currentHashes = [];
        foreach ($profiles as $profile) $currentHashes[$profile['job']] = $profile['sha256'];
        $latest = $this->pdo->prepare('SELECT job,source_sha256 FROM v_latest_lac_profiles WHERE character_id=:cid');
        $latest->execute([':cid'=>$characterId]);
        $latestHashes = [];
        foreach ($latest->fetchAll() as $row) $latestHashes[$row['job']] = $row['source_sha256'];
        ksort($currentHashes); ksort($latestHashes);
        if ($currentHashes !== [] && $currentHashes === $latestHashes) {
            $summary = ['profiles'=>count($profiles),'sets'=>0,'requirements'=>0,'options'=>0,'supplies'=>0];
            foreach ($profiles as $profile) {
                $summary['sets'] += count($profile['sets']);
                foreach ($profile['sets'] as $set) $summary['requirements'] += count($set['requirements']);
                $summary['options'] += count($profile['options']);
                $summary['supplies'] += count($profile['supplies']);
            }
            return ['run_id'=>null,'summary'=>$summary,'skipped'=>true];
        }
        $this->pdo->beginTransaction();
        try {
            $run = $this->pdo->prepare('INSERT INTO lac_import_runs(character_id, started_at, status, profile_dir) VALUES(:cid,:started,:status,:dir)');
            $run->execute([':cid'=>$characterId, ':started'=>$now, ':status'=>'running', ':dir'=>$lac['profile_dir']]);
            $runId = (int)$this->pdo->lastInsertId();

            $profileStmt = $this->pdo->prepare('INSERT INTO lac_profile_snapshots(character_id, import_run_id, job, source_path, source_sha256, captured_at, imported_at) VALUES(:cid,:rid,:job,:path,:sha,:captured,:imported)');
            $setStmt = $this->pdo->prepare('INSERT INTO lac_sets(profile_snapshot_id,set_key,raw_name,category,activation_type,default_active,runtime_detected,source_path,source_line) VALUES(:psid,:key,:raw,:category,:activation,:default_active,:runtime,:path,:line)');
            $reqStmt = $this->pdo->prepare('INSERT INTO lac_requirements(set_id,slot_name,item_name,item_name_norm,aug_path,aug_rank,aug_trial,augment_strings_json,source_line) VALUES(:sid,:slot,:name,:norm,:path,:rank,:trial,:aug,:line)');
            $optStmt = $this->pdo->prepare('INSERT INTO lac_options(profile_snapshot_id,cycle_name,option_value,is_default,slot_name,item_name,item_name_norm,activation_type,aug_path,aug_rank,aug_trial,augment_strings_json,source_path,source_line) VALUES(:psid,:cycle,:value,:def,:slot,:name,:norm,:activation,:path,:rank,:trial,:aug,:source_path,:line)');
            $supStmt = $this->pdo->prepare('INSERT INTO lac_supplies(profile_snapshot_id,name,name_norm,quantity_text,source_line) VALUES(:psid,:name,:norm,:qty,:line)');

            $summary = ['profiles'=>0,'sets'=>0,'requirements'=>0,'options'=>0,'supplies'=>0];
            foreach ($profiles as $profile) {
                $captured = gmdate('c', filemtime($profile['path']) ?: time());
                $profileStmt->execute([':cid'=>$characterId, ':rid'=>$runId, ':job'=>$profile['job'], ':path'=>$profile['path'], ':sha'=>$profile['sha256'], ':captured'=>$captured, ':imported'=>$now]);
                $psid = (int)$this->pdo->lastInsertId();
                $summary['profiles']++;
                foreach ($profile['sets'] as $set) {
                    $setStmt->execute([
                        ':psid'=>$psid, ':key'=>$set['set_key'], ':raw'=>$set['raw_name'], ':category'=>$set['category'],
                        ':activation'=>$set['activation_type'], ':default_active'=>$set['default_active']?1:0,
                        ':runtime'=>$set['runtime_detected']?1:0, ':path'=>$set['source_path'], ':line'=>$set['source_line'],
                    ]);
                    $sid=(int)$this->pdo->lastInsertId(); $summary['sets']++;
                    foreach ($set['requirements'] as $r) {
                        $reqStmt->execute([
                            ':sid'=>$sid, ':slot'=>$r['slot_name'], ':name'=>$r['item_name'], ':norm'=>$r['item_name_norm'],
                            ':path'=>$r['aug_path'], ':rank'=>$r['aug_rank'], ':trial'=>$r['aug_trial'],
                            ':aug'=>CanonicalJson::encode($r['augment_strings']), ':line'=>$r['source_line'],
                        ]);
                        $summary['requirements']++;
                    }
                }
                foreach ($profile['options'] as $o) {
                    $optStmt->execute([
                        ':psid'=>$psid, ':cycle'=>$o['cycle_name'], ':value'=>$o['option_value'], ':def'=>$o['is_default']?1:0,
                        ':slot'=>$o['slot_name'], ':name'=>$o['item_name'], ':norm'=>$o['item_name_norm'], ':activation'=>$o['activation_type'],
                        ':path'=>$o['aug_path'], ':rank'=>$o['aug_rank'], ':trial'=>$o['aug_trial'], ':aug'=>CanonicalJson::encode($o['augment_strings']),
                        ':source_path'=>$o['source_path'], ':line'=>$o['source_line'],
                    ]);
                    $summary['options']++;
                }
                foreach ($profile['supplies'] as $s) {
                    $supStmt->execute([':psid'=>$psid, ':name'=>$s['name'], ':norm'=>LacProfileParser::normalizeName($s['name']), ':qty'=>(string)($s['quantity'] ?? ''), ':line'=>$s['source_line']]);
                    $summary['supplies']++;
                }
            }

            $done = $this->pdo->prepare('UPDATE lac_import_runs SET completed_at=:done,status=:status,profile_count=:profiles,set_count=:sets,requirement_count=:reqs WHERE id=:id');
            $done->execute([':done'=>$now,':status'=>'ok',':profiles'=>$summary['profiles'],':sets'=>$summary['sets'],':reqs'=>$summary['requirements'],':id'=>$runId]);
            $this->pdo->commit();
            return ['run_id'=>$runId,'summary'=>$summary];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

}
