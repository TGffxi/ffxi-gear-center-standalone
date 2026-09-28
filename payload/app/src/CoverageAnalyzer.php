<?php
declare(strict_types=1);

/**
 * Measures how completely Gear Center can plan the character's relevant gear.
 *
 * Coverage is about planning knowledge, not whether an item is already complete.
 * Relevant items are those referenced by current LAC requirements/options or by
 * active/planned goals.  Inventory-only reference items do not inflate the score.
 */
final class CoverageAnalyzer
{
    private ItemCatalog $catalog;
    private BgWikiRepository $bg;
    private AcquisitionRouteService $routes;
    private ProgressionChainService $chains;
    private ProgressionSystemService $systems;
    /** @var array<int,array<string,mixed>> */
    private array $graphRecords;

    public function __construct(
        private PDO $pdo,
        private int $characterId,
        private int $snapshotId,
        private ?int $augmentSnapshotId = null,
    ) {
        $this->catalog = new ItemCatalog($pdo, $characterId, $snapshotId, $augmentSnapshotId);
        $this->bg = new BgWikiRepository($pdo);
        $this->routes = new AcquisitionRouteService();
        $this->chains = new ProgressionChainService($this->bg);
        $this->systems = new ProgressionSystemService();
        $this->graphRecords = $this->bg->graphRecords();
    }

    /** @return array<string,mixed> */
    public function report(string $job='all', string $coverage='all'): array
    {
        // GP08_COVERAGE_CACHE_HOTFIX_BEGIN
        // Web requests never run the expensive full audit synchronously.
        // CLI calls remain authoritative and unchanged.
        if (PHP_SAPI !== 'cli') {
            $cached = $this->gp08LoadCoverageCache($job);
            if ($cached !== null) {
                return $cached;
            }
            $this->gp08TriggerCoverageRefresh($job);
            return $this->gp08CoveragePlaceholder($job);
        }
        // GP08_COVERAGE_CACHE_HOTFIX_END

        $job = strtoupper(trim($job));
        $coverage = strtoupper(trim($coverage));
        $items = [];
        $counts = [
            'FULL'=>0,
            'ACQUISITION_ONLY'=>0,
            'SYSTEM_KNOWN_DATA_MISSING'=>0,
            'PARTIAL'=>0,
            'UNRESOLVED'=>0,
        ];
        $needCounts = ['NO_ACTION'=>0,'ACQUIRE'=>0,'AUGMENT_OR_RANK'=>0,'OPTIONAL'=>0];
        $gapSystems = [];

        foreach ($this->catalog->search('', 'all', 'all', 2000) as $item) {
            if (!$this->isRelevant($item)) continue;
            if ($job !== 'ALL' && !in_array($job, array_map('strtoupper', $item['jobs'] ?? []), true)) {
                // Direct goals can have no job. Keep them only in ALL scope.
                continue;
            }

            $usage = $this->catalog->lacUsage((string)$item['name']);
            $facts = $this->factsFor($item, $usage);
            $row = self::classifyFacts($facts);
            $row = array_merge($facts, $row);

            if ($coverage !== 'ALL' && $row['coverage'] !== $coverage) continue;
            $items[] = $row;
            $counts[$row['coverage']]++;
            $needCounts[$row['need']]++;

            if ($row['coverage'] === 'SYSTEM_KNOWN_DATA_MISSING') {
                $key = $row['system_candidate'] ?: 'Unclassified structured progression';
                $gapSystems[$key] = ($gapSystems[$key] ?? 0) + 1;
            }
        }

        usort($items, static function(array $a,array $b): int {
            $cp=['UNRESOLVED'=>0,'SYSTEM_KNOWN_DATA_MISSING'=>1,'PARTIAL'=>2,'ACQUISITION_ONLY'=>3,'FULL'=>4];
            $np=['ACQUIRE'=>0,'AUGMENT_OR_RANK'=>1,'OPTIONAL'=>2,'NO_ACTION'=>3];
            return ($cp[$a['coverage']] <=> $cp[$b['coverage']])
                ?: ($np[$a['need']] <=> $np[$b['need']])
                ?: strcasecmp((string)$a['name'],(string)$b['name']);
        });
        arsort($gapSystems);

        $total = array_sum($counts);
        $modeled = $counts['FULL'] + $counts['ACQUISITION_ONLY'];
        return [
            'scope_job'=>$job,
            'total'=>$total,
            'counts'=>$counts,
            'need_counts'=>$needCounts,
            'modeled_percent'=>$total ? round(($modeled/$total)*100,1) : 100.0,
            'full_percent'=>$total ? round(($counts['FULL']/$total)*100,1) : 100.0,
            'gap_systems'=>$gapSystems,
            'items'=>$items,
        ];
    }

    /**
     * Pure classifier kept public so smoke tests can validate the policy without SQLite.
     * @param array<string,mixed> $f
     * @return array<string,mixed>
     */
    public static function classifyFacts(array $f): array
    {
        $owned=(int)($f['owned_count']??0)>0;
        $required=(int)($f['requirement_count']??0)>0 || (int)($f['active_goal_count']??0)>0;
        $optional=!$required && (int)($f['option_count']??0)>0;
        $targetAug=!empty($f['has_target_augment']);
        $targetActionRequired=array_key_exists('target_action_required',$f)?(bool)$f['target_action_required']:$targetAug;
        $bg=!empty($f['bgwiki_cached']);
        $routes=(int)($f['acquisition_route_count']??0);
        $chain=!empty($f['chain_has_data']);
        $actionCount=(int)($f['progression_action_count']??0);
        $knownSystem=(int)($f['known_system_count']??0);
        $knownComplete=(int)($f['known_system_complete_count']??$knownSystem);
        $augmentOptions=(int)($f['augment_option_count']??0);
        $structured=(int)($f['structured_record_count']??0);

        $need = $optional ? 'OPTIONAL' : ((!$owned && $required) ? 'ACQUIRE' : (($owned && $targetAug && $targetActionRequired) ? 'AUGMENT_OR_RANK' : 'NO_ACTION'));
        $coverage='UNRESOLVED';$reason='No actionable external progression data is cached.';

        // If current desired state requires no work, inventory/LAC already answer the
        // planning question. External acquisition history is useful but not required.
        if ($need==='NO_ACTION') {
            $coverage='FULL';
            $reason='Current desired state is already owned and has no structured augment/rank target.';
        } elseif ($need==='OPTIONAL' && $owned) {
            $coverage='FULL';
            $reason='Selectable item is already owned; no current progression action is required.';
        } elseif ($need==='ACQUIRE') {
            if ($routes>0 && $chain) {
                $coverage='FULL';
                $reason='Missing target has structured acquisition plus upgrade/progression data.';
            } elseif ($routes>0) {
                $coverage='ACQUISITION_ONLY';
                $reason='A structured way to obtain the item is known; no additional upgrade system is modeled.';
            } elseif ($chain) {
                $coverage='PARTIAL';
                $reason='Upgrade/progression data exists, but no structured acquisition route to the target is cached.';
            } elseif ($bg && $structured>0) {
                $coverage='PARTIAL';
                $reason='BG-Wiki has structured data, but it is not yet actionable as an acquisition route.';
            } elseif ($bg) {
                $coverage='PARTIAL';
                $reason='BG-Wiki page is cached, but progression extraction is incomplete.';
            }
        } elseif ($need==='AUGMENT_OR_RANK') {
            if ($knownComplete>0) {
                $coverage='FULL';
                $reason='Target augment/rank has a complete progression-system adapter; live state is the only deferred input.';
            } elseif ($knownSystem>0) {
                $coverage='SYSTEM_KNOWN_DATA_MISSING';
                $reason='The progression family is recognized, but item-specific trial/path data still needs a structured source before the route is fully actionable.';
            } elseif ($actionCount>0 && ($augmentOptions>0 || !$targetAug)) {
                $coverage='SYSTEM_KNOWN_DATA_MISSING';
                $reason='Structured augment/upgrade action exists, but no full system adapter/live calculation rule matches it yet.';
            } elseif ($chain) {
                $coverage='SYSTEM_KNOWN_DATA_MISSING';
                $reason='A progression chain is known, but target-specific augment/rank modeling is incomplete.';
            } elseif ($bg && $structured>0) {
                $coverage='PARTIAL';
                $reason='Structured BG-Wiki data exists, but the LAC augment/rank target cannot yet be mapped to an actionable system.';
            } elseif ($bg) {
                $coverage='PARTIAL';
                $reason='BG-Wiki page is cached, but augment/rank extraction is incomplete.';
            }
        } elseif ($need==='OPTIONAL') {
            if ($routes>0 || $chain) {
                $coverage=$chain?'FULL':'ACQUISITION_ONLY';
                $reason='Selectable item has structured optional progression data.';
            } elseif ($bg) {
                $coverage='PARTIAL';
                $reason='Optional item has a cached source page but no actionable route yet.';
            }
        }

        return ['coverage'=>$coverage,'need'=>$need,'coverage_reason'=>$reason];
    }

    private function isRelevant(array $item): bool
    {
        return (int)($item['requirement_count']??0)>0
            || (int)($item['option_count']??0)>0
            || (int)($item['active_goal_count']??0)>0;
    }

    /** @param array<string,mixed> $item @param array<int,array<string,mixed>> $usage @return array<string,mixed> */
    private function factsFor(array $item,array $usage): array
    {
        $name=(string)$item['name'];
        $page=$this->bg->byItem($name);
        $records=$this->bg->progressionRecordsByItem($name);
        $routes=array_values(array_filter($this->routes->build($name,$records,$page),static fn(array $r):bool=>($r['group']??'')==='Acquisition'));
        $graphFocus=$page?(string)$page['page_title']:$name;
        $chain=$this->chains->buildFromRecords($graphFocus,$this->graphRecords,5);
        $knownSystems=[];$knownComplete=[];$systemCandidate='';
        $resolveSystemEdge=function(array $edge) use ($item,$name,&$knownSystems,&$knownComplete,&$systemCandidate): void {
            // Enrich generic BG-Wiki edges with authoritative item-resource metadata.
            // Freshness/system identity must not depend on whether an action survived
            // current-state chain trimming; the same resolver is therefore also run
            // against direct structured records from the concrete item page below.
            $edgeForSystem=$edge;
            $edgeForSystem['item_id']=$item['item_id']??null;
            $edgeForSystem['item_name']=$name;
            $edgeForSystem['item_superior_level']=$item['superior_level']??null;
            $system=$this->systems->resolveEdge($edgeForSystem,[],[]);
            if($system){
                $knownSystems[(string)$system['key']]=(string)$system['label'];
                if(($system['data_complete']??true)===true)$knownComplete[(string)$system['key']]=true;
                return;
            }
            if($systemCandidate===''){
                $actor=trim((string)($edge['actor']??''));$section=trim((string)($edge['section']??''));
                $systemCandidate=trim(($actor!==''?$actor.' · ':'').$section);
            }
        };

        foreach(($chain['actions']??[]) as $edge)$resolveSystemEdge($edge);

        // Direct page records are authoritative system evidence even when the
        // progression graph intentionally hides them from the current-state plan
        // (for example owned optional Path gear).  Only edges touching the logical
        // or canonical focus item are considered, preventing transcluded multi-item
        // tables from leaking unrelated systems into this item.
        $nameNorm=LacProfileParser::normalizeName($name);
        $pageNorm=LacProfileParser::normalizeName($graphFocus);
        foreach($this->chains->edges($records) as $edge){
            $fromNorm=(string)($edge['from_norm']??'');
            $toNorm=(string)($edge['to_norm']??'');
            if($fromNorm!==$nameNorm && $toNorm!==$nameNorm && $fromNorm!==$pageNorm && $toNorm!==$pageNorm)continue;
            $resolveSystemEdge($edge);
        }

        $targetHints=$this->systems->targetHints($name,$usage,$records,$item);
        $nonActionCompleteTarget=false;
        foreach($targetHints as $hint){
            $knownSystems[(string)$hint['key']]=(string)$hint['label'];
            if(($hint['data_complete']??false)===true){
                $knownComplete[(string)$hint['key']]=true;
                if(($hint['action_required']??true)===false)$nonActionCompleteTarget=true;
            } elseif($systemCandidate==='')$systemCandidate=(string)$hint['label'];
        }
        $hasTarget=false;$targets=[];
        foreach($usage as $u){
            $parts=[];
            if(($u['aug_path']??null)!==null && trim((string)$u['aug_path'])!=='')$parts[]='Path '.trim((string)$u['aug_path']);
            if(($u['aug_rank']??null)!==null && trim((string)$u['aug_rank'])!=='')$parts[]='Rank '.trim((string)$u['aug_rank']);
            if(($u['aug_trial']??null)!==null && trim((string)$u['aug_trial'])!=='')$parts[]='Trial '.trim((string)$u['aug_trial']);
            $aug=json_decode((string)($u['augment_strings_json']??'[]'),true);
            if(is_array($aug) && $aug!==[])$parts[]=implode('; ',array_map('strval',$aug));
            if($parts){$hasTarget=true;$targets[]=implode(' | ',$parts);}
        }
        $targets=array_values(array_unique($targets));
        return [
            'name'=>$name,
            'name_norm'=>$item['name_norm'],
            'item_id'=>$item['item_id'],
            'superior_level'=>$item['superior_level']??null,
            'status'=>$item['status'],
            'owned_count'=>(int)$item['owned_count'],
            'requirement_count'=>(int)$item['requirement_count'],
            'option_count'=>(int)$item['option_count'],
            'active_goal_count'=>(int)$item['active_goal_count'],
            'jobs'=>$item['jobs'],
            'bgwiki_cached'=>$page!==null,
            'bgwiki_page_title'=>$page['page_title']??null,
            'structured_record_count'=>count($records),
            'acquisition_route_count'=>count($routes),
            'chain_has_data'=>!empty($chain['has_data']),
            'progression_action_count'=>count($chain['actions']??[]),
            'augment_option_count'=>count($chain['augment_options']??[]),
            'known_system_count'=>count($knownSystems),
            'known_system_complete_count'=>count($knownComplete),
            'known_systems'=>array_values($knownSystems),
            // Stable adapter keys are carried alongside human labels so the
            // freshness audit does not depend on UI wording/typography.
            'known_system_keys'=>array_values(array_keys($knownSystems)),
            'system_candidate'=>$systemCandidate,
            'has_target_augment'=>$hasTarget,
            'target_action_required'=>$hasTarget ? !$nonActionCompleteTarget : false,
            'target_augments'=>$targets,
        ];
    }


    // GP08_COVERAGE_CACHE_HELPERS_BEGIN
    private function gp08CoverageJob(string $job): string
    {
        $job = strtoupper(trim($job));
        return in_array($job, ['ALL','NIN','THF','COMMON'], true) ? $job : 'ALL';
    }

    private function gp08CoverageCacheDir(): string
    {
        return dirname(__DIR__) . '/data/cache/coverage';
    }

    /** @return array<string,mixed>|null */
    private function gp08LoadCoverageCache(string $job): ?array
    {
        $job = $this->gp08CoverageJob($job);
        $dir = $this->gp08CoverageCacheDir();
        $file = $dir . '/' . $job . '.json';
        if (!is_file($file)) return null;
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) return null;

        $age = max(0, time() - (int)@filemtime($file));
        $data['cache_meta'] = array_merge(
            is_array($data['cache_meta'] ?? null) ? $data['cache_meta'] : [],
            ['state'=>'ready','job'=>$job,'age_seconds'=>$age]
        );

        // Serve stale valid data while a refresh runs outside the HTTP request.
        if ($age > 1800) $this->gp08TriggerCoverageRefresh($job);
        return $data;
    }

    private function gp08TriggerCoverageRefresh(string $job): void
    {
        $job = $this->gp08CoverageJob($job);
        $dir = $this->gp08CoverageCacheDir();
        @mkdir($dir, 0775, true);
        $marker = $dir . '/' . $job . '.refreshing';
        if (is_file($marker) && (time() - (int)@filemtime($marker)) < 3600) return;
        @file_put_contents($marker, json_encode(['requested_at'=>gmdate('c'),'job'=>$job]));

        $script = dirname(__DIR__) . '/bin/coverage-cache-refresh.php';
        if (!is_file($script)) return;
        $php = PHP_BINARY ?: 'php';
        $log = $dir . '/' . $job . '.background.log';
        $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) .
            ' --job=' . escapeshellarg($job) . ' >>' . escapeshellarg($log) . ' 2>&1 &';
        @exec($cmd);
    }

    /** @return array<string,mixed> */
    private function gp08CoveragePlaceholder(string $job): array
    {
        return [
            'total'=>0,
            'counts'=>[
                'FULL'=>0,'ACQUISITION_ONLY'=>0,'SYSTEM_KNOWN_DATA_MISSING'=>0,
                'PARTIAL'=>0,'UNRESOLVED'=>0,
            ],
            'need_counts'=>[],
            'modeled_percent'=>0,
            'full_percent'=>0,
            'gap_systems'=>[],
            'items'=>[],
            'cache_meta'=>[
                'state'=>'building',
                'job'=>$this->gp08CoverageJob($job),
                'requested_at'=>gmdate('c'),
            ],
        ];
    }
    // GP08_COVERAGE_CACHE_HELPERS_END
}
