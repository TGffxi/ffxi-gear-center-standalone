<?php
declare(strict_types=1);

/** Persistent, context-bound cache for the expensive coverage report. */
final class CoverageCache
{
    public const SCHEMA = 'ffxi-gear-center.coverage-cache/0.8';
    private const MAX_AGE_SECONDS = 1800;
    private const LOCK_MAX_AGE_SECONDS = 3600;

    public function __construct(
        private PDO $pdo,
        private string $root,
        private string $dbPath,
        private int $characterId,
        private int $snapshotId,
        private ?int $augmentSnapshotId = null,
    ) {}

    /** @return array<string,mixed> */
    public function loadOrTrigger(string $job): array
    {
        $job = $this->job($job);
        $fingerprint = $this->fingerprint($job);
        $file = $this->cacheFile($job);
        $data = $this->readValid($file);
        if ($data !== null && hash_equals($fingerprint, (string)($data['cache_meta']['fingerprint'] ?? ''))) {
            $age = max(0, time() - (int)filemtime($file));
            $data['cache_meta']['age_seconds'] = $age;
            $data['cache_meta']['state'] = $age > self::MAX_AGE_SECONDS ? 'stale' : 'ready';
            if ($age > self::MAX_AGE_SECONDS) $this->trigger($job);
            return $data;
        }
        $this->trigger($job);
        return $this->placeholder($job, $data === null ? 'missing_or_invalid' : 'context_changed');
    }

    /** @param array<string,mixed> $report */
    public function publish(string $job, array $report, float $durationSeconds): void
    {
        $job = $this->job($job);
        if (!isset($report['items']) || !is_array($report['items'])) {
            throw new RuntimeException('Coverage report has no items array.');
        }
        $report['cache_meta'] = [
            'schema' => self::SCHEMA,
            'state' => 'ready',
            'job' => $job,
            'fingerprint' => $this->fingerprint($job),
            'character_id' => $this->characterId,
            'snapshot_id' => $this->snapshotId,
            'augment_snapshot_id' => $this->augmentSnapshotId,
            'generated_at' => gmdate('c'),
            'generated_at_unix' => time(),
            'duration_seconds' => round($durationSeconds, 3),
            'source' => 'background_cli',
        ];
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) throw new RuntimeException('Could not encode coverage cache JSON.');
        $file = $this->cacheFile($job);
        $tmp = $file . '.tmp-' . getmypid();
        if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Could not atomically publish coverage cache.');
        }
    }

    /** @return array<string,mixed> */
    public function status(string $job): array
    {
        $job = $this->job($job);
        $file = $this->cacheFile($job);
        $data = $this->readValid($file);
        $marker = $this->markerFile($job);
        if ($data === null) {
            return ['job'=>$job, 'state'=>is_file($marker)?'building':'invalid_or_empty', 'items'=>0, 'age_seconds'=>null, 'fingerprint_match'=>false];
        }
        $age = max(0, time() - (int)filemtime($file));
        $match = hash_equals($this->fingerprint($job), (string)($data['cache_meta']['fingerprint'] ?? ''));
        return [
            'job'=>$job,
            'state'=>!$match?'context_changed':($age>self::MAX_AGE_SECONDS?'stale':'ready'),
            'items'=>(int)($data['total'] ?? count($data['items'])),
            'age_seconds'=>$age,
            'fingerprint_match'=>$match,
            'refreshing'=>is_file($marker),
        ];
    }

    public function clearMarker(string $job): void
    {
        $marker = $this->markerFile($this->job($job));
        if (is_file($marker)) @unlink($marker);
    }

    private function trigger(string $job): void
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) return;
        $marker = $this->markerFile($job);
        if (is_file($marker) && time() - (int)filemtime($marker) > self::LOCK_MAX_AGE_SECONDS) @unlink($marker);
        $handle = @fopen($marker, 'x');
        if ($handle === false) return;
        fwrite($handle, json_encode(['pid'=>getmypid(), 'requested_at'=>gmdate('c'), 'job'=>$job]) . "\n");
        fclose($handle);

        $script = $this->root . '/bin/coverage-cache-refresh.php';
        if (!is_file($script)) {
            @unlink($marker);
            return;
        }
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $log = $dir . '/' . $job . '.background.log';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script)
            . ' --job=' . escapeshellarg($job) . ' >>' . escapeshellarg($log) . ' 2>&1 &';
        @exec($command);
    }

    /** @return array<string,mixed>|null */
    private function readValid(string $file): ?array
    {
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return null;
        if (($data['cache_meta']['schema'] ?? '') !== self::SCHEMA) return null;
        return $data;
    }

    /** @return array<string,mixed> */
    private function placeholder(string $job, string $reason): array
    {
        return [
            'scope_job'=>$job, 'total'=>0,
            'counts'=>['FULL'=>0,'ACQUISITION_ONLY'=>0,'SYSTEM_KNOWN_DATA_MISSING'=>0,'PARTIAL'=>0,'UNRESOLVED'=>0],
            'need_counts'=>[], 'modeled_percent'=>0, 'full_percent'=>0, 'gap_systems'=>[], 'items'=>[],
            'cache_meta'=>[
                'schema'=>self::SCHEMA, 'state'=>'building', 'job'=>$job, 'reason'=>$reason,
                'requested_at'=>gmdate('c'), 'fingerprint'=>$this->fingerprint($job),
            ],
        ];
    }

    private function fingerprint(string $job): string
    {
        $files = [
            $this->root . '/src/CoverageAnalyzer.php',
            $this->root . '/src/LatestTierAudit.php',
            $this->root . '/src/SystemFreshnessRegistry.php',
            $this->root . '/sql/schema.sql',
        ];
        $fileState = [];
        foreach ($files as $file) {
            $fileState[$file] = is_file($file) ? [(int)filemtime($file), (int)filesize($file)] : null;
        }
        $context = [
            'schema'=>self::SCHEMA, 'job'=>$job, 'character_id'=>$this->characterId,
            'snapshot_id'=>$this->snapshotId, 'augment_snapshot_id'=>$this->augmentSnapshotId,
            'database_path'=>realpath($this->dbPath) ?: $this->dbPath,
            'database_state'=>$this->databaseState(),
            'knowledge_version'=>defined('SystemFreshnessRegistry::KNOWLEDGE_VERSION') ? SystemFreshnessRegistry::KNOWLEDGE_VERSION : null,
            'verified_through'=>defined('SystemFreshnessRegistry::VERIFIED_THROUGH') ? SystemFreshnessRegistry::VERIFIED_THROUGH : null,
            'files'=>$fileState,
        ];
        return hash('sha256', (string)json_encode($context, JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string,array<string,int|string|null>> */
    private function databaseState(): array
    {
        $tables = [
            'snapshots'=>'imported_at', 'equipment_snapshot_items'=>null, 'item_definitions'=>'last_seen_at',
            'lac_profile_snapshots'=>'imported_at', 'lac_requirements'=>null, 'lac_options'=>null,
            'goals'=>'updated_at', 'item_overrides'=>'updated_at',
            'bgwiki_pages'=>'fetched_at', 'bgwiki_item_map'=>'updated_at',
            'bgwiki_progression_records'=>'created_at', 'target_job_snapshots'=>'imported_at',
            'target_items'=>'verified_at', 'target_item_identity_evidence'=>'updated_at',
        ];
        $out = [];
        foreach ($tables as $table=>$timeColumn) {
            $sql = 'SELECT COUNT(*) AS n, COALESCE(MAX(rowid),0) AS max_rowid'
                . ($timeColumn !== null ? ', MAX(' . $timeColumn . ') AS changed_at' : '')
                . ' FROM ' . $table;
            try {
                $row = $this->pdo->query($sql)->fetch(PDO::FETCH_ASSOC) ?: [];
                $out[$table] = [
                    'count'=>(int)($row['n'] ?? 0), 'max_rowid'=>(int)($row['max_rowid'] ?? 0),
                    'changed_at'=>$timeColumn !== null ? ($row['changed_at'] ?? null) : null,
                ];
            } catch (Throwable $e) {
                $out[$table] = ['count'=>-1, 'max_rowid'=>-1, 'changed_at'=>'unavailable'];
            }
        }
        return $out;
    }

    private function job(string $job): string
    {
        $job = strtoupper(trim($job));
        return in_array($job, ['ALL','NIN','THF','COMMON'], true) ? $job : 'ALL';
    }

    private function cacheDir(): string
    {
        return $this->root . '/data/cache/coverage';
    }

    private function cacheFile(string $job): string
    {
        $dir = $this->cacheDir();
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        return $dir . '/' . $job . '.json';
    }

    private function markerFile(string $job): string
    {
        return $this->cacheDir() . '/' . $job . '.refreshing';
    }
}
