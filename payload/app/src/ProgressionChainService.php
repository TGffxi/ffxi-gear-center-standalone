<?php
declare(strict_types=1);

/**
 * Builds conservative upgrade / augment relations from already structured BG-Wiki rows.
 *
 * No relation is inferred from naming patterns. An edge only exists when a cached
 * structured record explicitly names another item (Upgraded From / Used In ...),
 * or when the record describes an augment/upgrade action on the current item.
 */
final class ProgressionChainService
{
    public function __construct(private ?BgWikiRepository $repo = null) {}

    /** @return array<string,mixed> */
    public function build(string $focusItem, int $maxDepth = 5): array
    {
        if ($this->repo === null) throw new RuntimeException('BG-Wiki repository is required for database-backed chain building.');
        $page=$this->repo->byItem($focusItem);
        $graphFocus=$page ? (string)$page['page_title'] : $focusItem;
        $result=$this->buildFromRecords($graphFocus, $this->repo->graphRecords(), $maxDepth);
        $result['requested_focus']=$focusItem;
        $result['resolved_focus']=$graphFocus;
        if(LacProfileParser::normalizeName($graphFocus)!==LacProfileParser::normalizeName($focusItem)){
            foreach($result['plans'] as &$plan){
                $plan['title']='Progress '.$focusItem.' via '.$graphFocus;
                $plan['fields']['requested_focus']=$focusItem;
            }
            unset($plan);
        }
        return $result;
    }

    /** @param array<int,array<string,mixed>> $records @return array<string,mixed> */
    public function buildFromRecords(string $focusItem, array $records, int $maxDepth = 5): array
    {
        $edges = $this->edges($records);
        $focusNorm = LacProfileParser::normalizeName($focusItem);
        $relational = array_values(array_filter($edges, static fn(array $e): bool => $e['from_norm'] !== $e['to_norm']));
        $actions = array_values(array_filter($edges, static fn(array $e): bool => $e['from_norm'] === $e['to_norm']));

        $incoming = [];
        $outgoing = [];
        foreach ($relational as $edge) {
            $incoming[$edge['to_norm']][] = $edge;
            $outgoing[$edge['from_norm']][] = $edge;
        }

        $upstreamPaths = $this->walkUp($focusNorm, $incoming, max(1, $maxDepth), []);
        $downstreamPaths = $this->walkDown($focusNorm, $outgoing, max(1, $maxDepth), []);

        // walkUp/walkDown return [[]] when there is no relation. Hide that in UI.
        $upstreamPaths = array_values(array_filter($upstreamPaths, static fn(array $p): bool => $p !== []));
        $downstreamPaths = array_values(array_filter($downstreamPaths, static fn(array $p): bool => $p !== []));

        $relevantNorms = [$focusNorm => true];
        foreach (array_merge($upstreamPaths, $downstreamPaths) as $path) {
            foreach ($path as $edge) {
                $relevantNorms[$edge['from_norm']] = true;
                $relevantNorms[$edge['to_norm']] = true;
            }
        }
        $relevantActions = array_values(array_filter($actions, static fn(array $e): bool => isset($relevantNorms[$e['from_norm']])));

        $nodes = [];
        $register = static function (array &$nodes, string $name): void {
            $norm = LacProfileParser::normalizeName($name);
            if ($norm === '') return;
            if (!isset($nodes[$norm])) $nodes[$norm] = ['name'=>$name,'name_norm'=>$norm];
        };
        $register($nodes, $focusItem);
        foreach (array_merge($relational, $relevantActions) as $edge) {
            if (!isset($relevantNorms[$edge['from_norm']]) && !isset($relevantNorms[$edge['to_norm']])) continue;
            $register($nodes, $edge['from']);
            $register($nodes, $edge['to']);
        }

        $plans = $this->plans($focusItem, $upstreamPaths, $relevantActions);
        $augmentOptions = $this->augmentOptions($focusItem, $records);

        return [
            'focus'=>$focusItem,
            'nodes'=>array_values($nodes),
            'upstream_paths'=>$upstreamPaths,
            'downstream_paths'=>$downstreamPaths,
            'actions'=>$relevantActions,
            'augment_options'=>$augmentOptions,
            'plans'=>$plans,
            'has_data'=>($upstreamPaths !== [] || $downstreamPaths !== [] || $relevantActions !== [] || $augmentOptions !== []),
        ];
    }

    /**
     * Re-scope a resolved chain to an item that is already physically owned.
     * Earlier upgrade stages remain visible as history/reference, but actionable
     * plans start at the current owned variant and therefore contain only
     * self-actions such as augment/rank work on that variant.
     *
     * @param array<string,mixed> $graph
     * @return array<string,mixed>
     */
    public function forOwnedFocus(array $graph): array
    {
        $focus = (string)($graph['resolved_focus'] ?? $graph['focus'] ?? '');
        $requested = (string)($graph['requested_focus'] ?? $focus);
        $focusNorm = LacProfileParser::normalizeName($focus);
        $focusActions = array_values(array_filter(
            $graph['actions'] ?? [],
            static fn(array $e): bool => (string)($e['from_norm'] ?? '') === $focusNorm
        ));

        $graph['history_paths'] = $graph['upstream_paths'] ?? [];
        $graph['current_state'] = 'owned';
        $graph['plans'] = [];
        if ($focusActions === []) return $graph;

        $steps = [];
        foreach ($focusActions as $action) $steps[] = $this->actionStep($focus, $action);
        $fingerprint = ['focus'=>$focusNorm,'owned'=>true,'actions'=>array_column($focusActions,'key')];
        $key = substr(hash('sha256', CanonicalJson::encode($fingerprint)),0,24);
        $sourceUrl='';$sourceTitle='';
        foreach($focusActions as $edge){
            if($sourceUrl==='' && ($edge['source_url']??'')!=='')$sourceUrl=(string)$edge['source_url'];
            if($sourceTitle==='' && ($edge['source_title']??'')!=='')$sourceTitle=(string)$edge['source_title'];
        }
        $graph['plans'][] = [
            'key'=>$key,
            'kind'=>'progression_plan',
            'group'=>'Progression',
            'section'=>'Upgrade / Augment chain',
            'title'=>'Progress ' . $requested . ($focus !== $requested ? ' via ' . $focus : ''),
            'task_type'=>'upgrade',
            'content_name'=>null,
            'summary'=>count($steps).' current-stage progression step'.(count($steps)===1?'':'s'),
            'steps'=>$steps,
            'fields'=>['focus'=>$focus,'requested_focus'=>$requested,'current_owned'=>true],
            'source_title'=>$sourceTitle ?: 'BG-Wiki',
            'source_url'=>$sourceUrl,
            'confidence'=>'structured_bgwiki_current_state',
            'path'=>[],
            'actions'=>$focusActions,
        ];
        return $graph;
    }

    /** @return array<int,array<string,mixed>> */
    public function plansFor(string $focusItem): array
    {
        return $this->build($focusItem)['plans'];
    }

    public function findPlan(string $focusItem, string $key): ?array
    {
        $graph=$this->build($focusItem);
        $candidates=$graph['plans']??[];
        // Item-detail pages re-scope plans when the physical target is already
        // owned. Accept those keys too so a POST created from the current-state
        // UI can always be resolved server-side.
        $owned=$this->forOwnedFocus($graph);
        foreach(($owned['plans']??[]) as $plan)$candidates[]=$plan;
        foreach ($candidates as $plan) {
            if (hash_equals((string)$plan['key'], $key)) return $plan;
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $records @return array<int,array<string,mixed>> */
    public function edges(array $records): array
    {
        $out = [];
        foreach ($records as $record) {
            $owner = trim((string)($record['item_name'] ?? $record['page_title'] ?? ''));
            $section = trim((string)($record['section'] ?? ''));
            $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
            if ($owner === '' || $section === '' || $fields === []) continue;

            $sectionNorm = strtolower($section);
            $kind = null;
            $from = $owner;
            $to = $owner;

            if (str_contains($sectionNorm, 'version chain')) {
                $kind = 'upgrade';
                $source = $this->itemField($fields, ['From Item']);
                $target = $this->itemField($fields, ['To Item']);
                if ($source === '' || $target === '') continue;
                $from = $source;
                $to = $target;
            } elseif (str_contains($sectionNorm, 'upgraded from')) {
                $kind = 'upgrade';
                $source = $this->itemField($fields, ['Base Item','Previous Item','Upgraded From','Item','Item Name','Weapon','Armor']);
                if ($source === '') continue;
                $from = $source;
            } elseif (str_contains($sectionNorm, 'used in item upgrades')) {
                $kind = 'upgrade';
                $target = $this->itemField($fields, ['Upgraded Item','Upgrade To','Result','Item','Item Name']);
                if ($target === '') continue;
                $to = $target;
            } elseif (str_contains($sectionNorm, 'used in recipe')) {
                $kind = 'craft';
                $target = $this->itemField($fields, ['Result','Product','Item','Item Name']);
                if ($target === '') continue;
                $to = $target;
            } elseif (str_contains($sectionNorm, 'augmented via') || str_contains($sectionNorm, 'augment')) {
                if (self::isAugmentReferenceFields($fields)) continue;
                $kind = 'augment';
            } elseif (str_contains($sectionNorm, 'upgrade info')) {
                $kind = 'upgrade';
                // Reforged tables explicitly carry both Base and Reforged Armor.
                // Preserve both ends so a transcluded five-slot table does not map
                // every row to the current page item.
                $source = $this->itemField($fields, ['Base','Base Item','Previous Item','Upgraded From']);
                $target = $this->itemField($fields, ['Reforged Armor','Upgrade To','Result','Upgraded Item','Reward']);
                if ($source !== '') $from = $source;
                if ($target !== '') $to = $target;
            } else {
                continue;
            }

            $from = $this->cleanItemName($from);
            $to = $this->cleanItemName($to);
            if ($from === '' || $to === '') continue;
            $fromNorm = LacProfileParser::normalizeName($from);
            $toNorm = LacProfileParser::normalizeName($to);
            if ($fromNorm === '' || $toNorm === '') continue;

            $actor = $this->field($fields, ['NPC Name','NPC','Name','Augmented Via','Upgrade NPC','Source']);
            $zone = $this->field($fields, ['Zone','Area','Location']);
            $requirements = $this->requirementsText($fields);
            $summary = implode(' · ', array_values(array_filter([$actor,$this->oneLine($zone),$this->oneLine($requirements)], static fn($v)=>trim((string)$v)!=='')));
            $edge = [
                'kind'=>$kind,
                'section'=>$section,
                'from'=>$from,
                'from_norm'=>$fromNorm,
                'to'=>$to,
                'to_norm'=>$toNorm,
                'actor'=>$actor,
                'zone'=>$zone,
                'requirements'=>$requirements,
                'summary'=>$summary,
                'fields'=>$fields,
                'source_title'=>(string)($record['page_title'] ?? ''),
                'source_url'=>(string)($record['canonical_url'] ?? ''),
                'confidence'=>'structured_bgwiki',
            ];
            $edge['key'] = substr(hash('sha256', CanonicalJson::encode([
                'from'=>$fromNorm,'to'=>$toNorm,'kind'=>$kind,'section'=>$section,'fields'=>$fields,
            ])), 0, 24);
            $out[$edge['key']] = $edge;
        }
        return array_values($out);
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private function walkUp(string $node, array $incoming, int $depth, array $seen): array
    {
        if ($depth <= 0 || isset($seen[$node]) || empty($incoming[$node])) return [[]];
        $seen[$node] = true;
        $paths = [];
        foreach ($incoming[$node] as $edge) {
            if ($edge['from_norm'] === $node) continue;
            foreach ($this->walkUp($edge['from_norm'], $incoming, $depth - 1, $seen) as $prefix) {
                $paths[] = array_merge($prefix, [$edge]);
            }
        }
        return $paths ?: [[]];
    }

    /** @return array<int,array<int,array<string,mixed>>> */
    private function walkDown(string $node, array $outgoing, int $depth, array $seen): array
    {
        if ($depth <= 0 || isset($seen[$node]) || empty($outgoing[$node])) return [[]];
        $seen[$node] = true;
        $paths = [];
        foreach ($outgoing[$node] as $edge) {
            if ($edge['to_norm'] === $node) continue;
            foreach ($this->walkDown($edge['to_norm'], $outgoing, $depth - 1, $seen) as $suffix) {
                $paths[] = array_merge([$edge], $suffix);
            }
        }
        return $paths ?: [[]];
    }

    /** @param array<int,array<string,mixed>> $records @return array<int,array<string,mixed>> */
    private function augmentOptions(string $focusItem, array $records): array
    {
        $focusNorm=LacProfileParser::normalizeName($focusItem);$out=[];
        foreach($records as $record){
            $owner=trim((string)($record['item_name']??$record['page_title']??''));
            if(LacProfileParser::normalizeName($owner)!==$focusNorm)continue;
            $section=strtolower(trim((string)($record['section']??'')));$fields=is_array($record['fields']??null)?$record['fields']:[];
            if(!str_contains($section,'augment') || !self::isAugmentReferenceFields($fields))continue;
            foreach($fields as $key=>$value){
                if(!preg_match('/^Path\\s+([A-Z0-9]+)$/i',trim((string)$key),$m))continue;
                $details=trim((string)$value);if($details==='')continue;$path=strtoupper($m[1]);
                $k=$focusNorm.'|'.$path.'|'.LacProfileParser::normalizeName($details);
                $out[$k]=['item_name'=>$focusItem,'path'=>$path,'label'=>'Path '.$path,'details'=>$details,'source_title'=>(string)($record['page_title']??''),'source_url'=>(string)($record['canonical_url']??''),'confidence'=>'structured_bgwiki'];
            }
            $pathValue=$this->field($fields,['Path','AugPath','Augment Path']);
            if($pathValue!=='' && preg_match('/(?:Path\\s*)?([A-Z0-9]+)/i',$pathValue,$m)){
                $path=strtoupper($m[1]);$details=$this->field($fields,['Augments','Stats','Effect','Effects','Details','Notes']);
                if($details!==''){$k=$focusNorm.'|'.$path.'|'.LacProfileParser::normalizeName($details);$out[$k]=['item_name'=>$focusItem,'path'=>$path,'label'=>'Path '.$path,'details'=>$details,'source_title'=>(string)($record['page_title']??''),'source_url'=>(string)($record['canonical_url']??''),'confidence'=>'structured_bgwiki'];}
            }
        }
        return array_values($out);
    }

    public static function isAugmentReferenceFields(array $fields): bool
    {
        $pathKeys=0;$substantive=0;$normalizedKeys=[];
        foreach($fields as $key=>$value){
            $k=trim((string)$key);$v=trim((string)$value);if($v==='')continue;
            $kl=strtolower($k);$normalizedKeys[$kl]=true;
            if(preg_match('/^Path\s+[A-Z0-9]+$/i',$k)){$pathKeys++;continue;}
            if(in_array($kl,['path','augpath','augment path','augments','stats','effect','effects','details'],true)){$substantive++;continue;}
            // These fields identify an actual augment action / NPC transaction.
            // If present, the row must remain actionable rather than being folded
            // into the surrounding option/detail table.
            if(in_array($kl,['npc name','npc','augmented via','zone','required items','requirements','cost','currency'],true))return false;
        }

        // BG-Wiki's JSE cape augmentation table is a detail matrix. Rows such as
        //   Abdhaljs Thread | Store TP | +1 | +10
        // carry the generic columns "Aug/Item" and "Max Aug" and describe possible
        // stat choices. They are NOT 100+ sequential augmentation actions. The
        // actual action is the separate Gorpa-Masorpa / Required Items row.
        $hasAugPerItem=isset($normalizedKeys['aug/item']) || isset($normalizedKeys['aug per item']);
        $hasMaxAug=isset($normalizedKeys['max aug']) || isset($normalizedKeys['max augment']);
        $matrixText='';
        foreach($fields as $value)if(is_scalar($value))$matrixText.="\n".(string)$value;
        $embeddedHeaders=preg_match('/(?:^|\R)Aug\/Item(?:\R|$)/i',$matrixText)===1
            && preg_match('/(?:^|\R)Max Aug(?:ment)?(?:\R|$)/i',$matrixText)===1;
        if(($hasAugPerItem && $hasMaxAug) || $embeddedHeaders)return true;

        return $pathKeys>0 || ($substantive>=2 && $this->field($fields,['Path','AugPath','Augment Path'])!=='');
    }

    /** @return array<int,array<string,mixed>> */
    private function plans(string $focusItem, array $upstreamPaths, array $actions): array
    {
        $focusNorm = LacProfileParser::normalizeName($focusItem);
        $focusActions = array_values(array_filter($actions, static fn(array $e): bool => $e['from_norm'] === $focusNorm));
        $paths = $upstreamPaths ?: ($focusActions ? [[]] : []);
        $plans = [];
        foreach ($paths as $pathIndex=>$path) {
            $steps = [];
            $root = $focusItem;
            if ($path) {
                $root = (string)$path[0]['from'];
                $steps[] = 'Ensure base item is available: ' . $root;
                foreach ($path as $edge) {
                    $label = ucfirst((string)$edge['kind']) . ' ' . $edge['from'] . ' → ' . $edge['to'];
                    $extra = array_values(array_filter([$edge['actor'],$this->oneLine((string)$edge['zone']),$this->oneLine((string)$edge['requirements'])], static fn($v)=>trim((string)$v)!==''));
                    if ($extra) $label .= ' · ' . implode(' · ', $extra);
                    $steps[] = $label;
                }
            }
            foreach ($focusActions as $action) {
                $steps[] = $this->actionStep($focusItem, $action);
            }
            if (!$steps) continue;
            $fingerprint = ['focus'=>$focusNorm,'path'=>array_column($path,'key'),'actions'=>array_column($focusActions,'key')];
            $key = substr(hash('sha256', CanonicalJson::encode($fingerprint)),0,24);
            $sourceUrl = '';
            $sourceTitle = '';
            foreach (array_merge($path,$focusActions) as $edge) {
                if ($sourceUrl === '' && ($edge['source_url'] ?? '') !== '') $sourceUrl = (string)$edge['source_url'];
                if ($sourceTitle === '' && ($edge['source_title'] ?? '') !== '') $sourceTitle = (string)$edge['source_title'];
            }
            $plans[$key] = [
                'key'=>$key,
                'kind'=>'progression_plan',
                'group'=>'Progression',
                'section'=>'Upgrade / Augment chain',
                'title'=>'Progress ' . $focusItem . ($root !== $focusItem ? ' from ' . $root : ''),
                'task_type'=>'upgrade',
                'content_name'=>null,
                'summary'=>count($steps) . ' structured progression step' . (count($steps)===1?'':'s'),
                'steps'=>$steps,
                'fields'=>['focus'=>$focusItem,'root'=>$root,'path_index'=>$pathIndex],
                'source_title'=>$sourceTitle ?: 'BG-Wiki',
                'source_url'=>$sourceUrl,
                'confidence'=>'structured_bgwiki_chain',
                'path'=>$path,
                'actions'=>$focusActions,
            ];
        }
        return array_values($plans);
    }

    private function actionStep(string $focusItem, array $action): string
    {
        $verb = ($action['kind'] ?? '') === 'augment' ? 'Augment ' : 'Complete upgrade progression for ';
        $label = $verb . $focusItem;
        $extra = array_values(array_filter([
            (string)($action['actor'] ?? ''),
            $this->oneLine((string)($action['zone'] ?? '')),
            $this->oneLine((string)($action['requirements'] ?? '')),
        ], static fn($v)=>trim((string)$v)!==''));
        if ($extra) $label .= ' · ' . implode(' · ', $extra);
        return $label;
    }

    private function itemField(array $fields, array $keys): string
    {
        foreach ($keys as $wanted) {
            foreach ($fields as $key=>$value) {
                if (strcasecmp(trim((string)$key), $wanted) !== 0) continue;
                $candidate = trim((string)$value);
                if ($candidate !== '') return $candidate;
            }
        }
        return '';
    }

    private function field(array $fields, array $keys): string
    {
        foreach ($keys as $wanted) {
            foreach ($fields as $key=>$value) if (strcasecmp(trim((string)$key),$wanted)===0) return trim((string)$value);
        }
        return '';
    }

    private function requirementsText(array $fields): string
    {
        $keys=['Required Items','Required Item','Items Required','Requirements','Requirement','Cost','Currency','Materials','Material','Job Ingredient','Slot Ingredient','Required Gallimaufry','Required Shard','Void Ingredient','Required Temenos Units','Required Apollyon Units','Upgrade Item','Objective','Ingredients','Notes','Note'];
        $parts=[];
        foreach($keys as $wanted){
            foreach($fields as $key=>$value){
                if(strcasecmp(trim((string)$key),$wanted)!==0)continue;
                $v=trim((string)$value);if($v!==''&&!in_array($v,$parts,true))$parts[]=$v;
            }
        }
        return implode("
",$parts);
    }

    private function cleanItemName(string $value): string
    {
        $parts = preg_split('/\R+/u', trim($value)) ?: [];
        $value = trim((string)($parts[0] ?? ''));
        // Strip a leading quantity only when it is clearly separated from the name.
        $value = preg_replace('/^\s*\d+\s*[x×]\s*/ui','',$value) ?? $value;
        // BG-Wiki reward cells can render as "Item Name Image (augment stats)".
        // Keep the logical item name; the stats remain available in the source row.
        $value = preg_replace('/\s+Image(?:\s*\(.*)?$/ui','',$value) ?? $value;
        return trim($value);
    }

    private function oneLine(string $value): string
    {
        return trim(preg_replace('/\R+/u',' / ',trim($value)) ?? trim($value));
    }
}
