<?php
declare(strict_types=1);

final class LacProfileParser
{
    private const SLOTS = ['Main','Sub','Range','Ammo','Head','Neck','Ear1','Ear2','Body','Hands','Ring1','Ring2','Back','Waist','Legs','Feet'];

    public function parseJob(string $job, string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) throw new RuntimeException('Could not read LAC profile: ' . $path);
        $assign = LuaTableParser::extractAssignments($source);
        $parser = new LuaTableParser();
        $nodes = [];
        foreach ($assign as $name => $a) {
            try { $nodes[$name] = $parser->parse($a['text'], $a['line']); } catch (Throwable $e) { /* non-gear tables may be odd */ }
        }

        $sets = [];
        if (isset($nodes['sets'])) $this->walkSets($nodes['sets'], [], $nodes, $sets, $job, $path, false);

        $options = $this->parseDirectEquipOptions($source, $nodes, $path);
        if (isset($nodes['dwGearPool'])) {
            foreach ($nodes['dwGearPool']['entries'] ?? [] as $entry) {
                $row = $entry['value'];
                if (($row['type'] ?? '') !== 'table') continue;
                $m = LuaTableParser::tableMap($row);
                $slot = isset($m[2]) ? (string)LuaTableParser::scalar($m[2]) : '';
                $spec = $m[3] ?? null;
                if ($slot === '' || !is_array($spec)) continue;
                $item = $this->itemSpec($spec, $nodes);
                if ($item === null) continue;
                $options[] = array_merge([
                    'cycle_name' => 'AutoDW', 'option_value' => $item['item_name'], 'is_default' => false,
                    'slot_name' => $slot, 'activation_type' => 'conditional_pool', 'source_path' => $path,
                    'source_line' => $spec['line'] ?? $entry['line'],
                ], $item);
            }
        }

        $supplies = [];
        if (isset($nodes['profile.Packer'])) {
            foreach ($nodes['profile.Packer']['entries'] ?? [] as $entry) {
                if (($entry['value']['type'] ?? '') !== 'table') continue;
                $m = LuaTableParser::tableMap($entry['value']);
                $name = isset($m['Name']) ? LuaTableParser::scalar($m['Name']) : null;
                $qty = isset($m['Quantity']) ? LuaTableParser::scalar($m['Quantity']) : null;
                if (is_string($name) && $name !== '') $supplies[] = ['name' => $name, 'quantity' => $qty, 'source_line' => $entry['line']];
            }
        }

        return ['job' => $job, 'path' => $path, 'sha256' => hash_file('sha256', $path), 'sets' => $sets, 'options' => $options, 'supplies' => $supplies];
    }

    public function parseCommon(string $path): array
    {
        $source = file_get_contents($path);
        if ($source === false) throw new RuntimeException('Could not read gcinclude.lua: ' . $path);
        $assign = LuaTableParser::extractAssignments($source);
        if (!isset($assign['gcinclude.sets'])) return ['job'=>'COMMON','path'=>$path,'sha256'=>hash_file('sha256',$path),'sets'=>[],'options'=>[],'supplies'=>[]];
        $parser = new LuaTableParser();
        $node = $parser->parse($assign['gcinclude.sets']['text'], $assign['gcinclude.sets']['line']);
        $sets = [];
        $this->walkSets($node, [], [], $sets, 'COMMON', $path, true);
        return ['job'=>'COMMON','path'=>$path,'sha256'=>hash_file('sha256',$path),'sets'=>$sets,'options'=>[],'supplies'=>[]];
    }

    private function walkSets(array $node, array $pathParts, array $vars, array &$sets, string $job, string $sourcePath, bool $common): void
    {
        if (($node['type'] ?? '') !== 'table') return;
        $map = LuaTableParser::tableMap($node);
        $slotEntries = [];
        foreach (self::SLOTS as $slot) if (isset($map[$slot])) $slotEntries[$slot] = $map[$slot];
        if ($slotEntries !== []) {
            $raw = implode('.', array_map('strval', $pathParts));
            $name = $raw !== '' ? $raw : 'unnamed';
            [$activation, $defaultActive, $runtimeDetected] = $this->classifySet($job, $name, $common);
            $reqs = [];
            foreach ($slotEntries as $slot => $spec) {
                $item = $this->itemSpec($spec, $vars);
                if ($item === null) continue;
                $reqs[] = array_merge(['slot_name'=>$slot, 'source_line'=>$spec['line'] ?? $node['line']], $item);
            }
            $sets[] = [
                'set_key' => ($common ? 'COMMON/' : $job . '/') . str_replace('.', '/', $name),
                'raw_name' => $name,
                'category' => $pathParts[0] ?? 'General',
                'activation_type' => $activation,
                'default_active' => $defaultActive,
                'runtime_detected' => $runtimeDetected,
                'source_path' => $sourcePath,
                'source_line' => $node['line'] ?? 0,
                'requirements' => $reqs,
            ];
            return;
        }
        foreach ($node['entries'] ?? [] as $entry) {
            if (($entry['value']['type'] ?? '') !== 'table') continue;
            $key = $entry['key'];
            if (is_int($key)) continue;
            $this->walkSets($entry['value'], array_merge($pathParts, [(string)$key]), $vars, $sets, $job, $sourcePath, $common);
        }
    }

    private function classifySet(string $job, string $name, bool $common): array
    {
        if ($common) {
            return match (strtolower($name)) {
                'doomed', 'reraise', 'sleeping' => ['automatic_condition', true, true],
                'holy_water' => ['utility_reference', false, false],
                'crafting', 'zeni', 'fishing' => ['utility_reference', false, false],
                default => ['common', false, false],
            };
        }
        $n = strtolower($name);
        if ($n === 'idle' || $n === 'tp_default') return ['default', true, true];
        if (in_array($n, ['idle_regen','idle_refresh','dt','pet_dt'], true)) return ['automatic_condition', false, true];
        return ['conditional', false, true];
    }

    private function itemSpec(array $node, array $vars): ?array
    {
        if (($node['type'] ?? '') === 'string') return $this->packItem((string)$node['value'], null, null, null, []);
        if (($node['type'] ?? '') === 'ref') {
            $ref = (string)$node['value'];
            if (isset($vars[$ref])) return $this->itemSpec($vars[$ref], $vars);
            return null;
        }
        if (($node['type'] ?? '') !== 'table') return null;
        $m = LuaTableParser::tableMap($node);
        $nameNode = $m['Name'] ?? null;
        if (!is_array($nameNode)) return null;
        $name = LuaTableParser::scalar($nameNode);
        if (!is_string($name) || $name === '') return null;
        $path = isset($m['AugPath']) ? LuaTableParser::scalar($m['AugPath']) : null;
        $rank = isset($m['AugRank']) ? LuaTableParser::scalar($m['AugRank']) : null;
        $trial = isset($m['AugTrial']) ? LuaTableParser::scalar($m['AugTrial']) : null;
        $augments = [];
        if (isset($m['Augment']) && ($m['Augment']['type'] ?? '') === 'table') {
            foreach ($m['Augment']['entries'] ?? [] as $e) {
                $v = LuaTableParser::scalar($e['value']);
                if (is_string($v) && $v !== '') $augments[] = $v;
            }
        }
        return $this->packItem($name, $path, $rank, $trial, $augments);
    }

    private function packItem(string $name, mixed $path, mixed $rank, mixed $trial, array $augments): array
    {
        return [
            'item_name' => $name,
            'item_name_norm' => self::normalizeName($name),
            'aug_path' => is_scalar($path) ? (string)$path : null,
            'aug_rank' => is_numeric($rank) ? (int)$rank : (is_scalar($rank) ? (string)$rank : null),
            'aug_trial' => is_numeric($trial) ? (int)$trial : null,
            'augment_strings' => array_values($augments),
        ];
    }

    private function parseDirectEquipOptions(string $source, array $vars, string $path): array
    {
        $cycleVars = [];
        if (preg_match_all('/local\s+(\w+)\s*=\s*gcdisplay\.GetCycle\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $source, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $cycleVars[$m[1]] = $m[2];
        }
        $cycleDefaults = [];
        if (preg_match_all('/gcdisplay\.CreateCycle\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*\{([^}]*)\}/s', $source, $cm, PREG_SET_ORDER)) {
            foreach ($cm as $m) {
                if (preg_match('/[\'\"]([^\'\"]+)[\'\"]/', $m[2], $v)) $cycleDefaults[$m[1]] = $v[1];
            }
        }

        $lines = preg_split('/\R/', $source) ?: [];
        $current = null;
        $out = [];
        foreach ($lines as $idx => $line) {
            if (preg_match('/(?:if|elseif)\s*\(\s*(\w+)\s*==\s*[\'\"]([^\'\"]+)[\'\"]\s*\)\s*then/', $line, $m)) {
                $current = isset($cycleVars[$m[1]]) ? ['var'=>$m[1], 'value'=>$m[2], 'line'=>$idx+1] : null;
                continue;
            }
            if ($current && preg_match('/gFunc\.Equip\(\s*[\'\"]([^\'\"]+)[\'\"]\s*,\s*(.+)\s*\);?\s*$/', trim($line), $m)) {
                $slot = $m[1]; $expr = trim($m[2]); $spec = null;
                if (($expr[0] ?? '') === '{') {
                    try { $spec = (new LuaTableParser())->parse($expr, $idx+1); } catch (Throwable) { $spec = null; }
                } elseif (preg_match('/^[\'\"](.*)[\'\"]$/', $expr, $sm)) {
                    $spec = ['type'=>'string','value'=>stripcslashes($sm[1]),'line'=>$idx+1];
                }
                if ($spec !== null) {
                    $item = $this->itemSpec($spec, $vars);
                    if ($item !== null) {
                        $cycle = $cycleVars[$current['var']] ?? $current['var'];
                        $out[] = array_merge([
                            'cycle_name'=>$cycle, 'option_value'=>$current['value'],
                            'is_default'=>isset($cycleDefaults[$cycle]) && $cycleDefaults[$cycle] === $current['value'],
                            'slot_name'=>$slot, 'activation_type'=>'selectable', 'source_path'=>$path, 'source_line'=>$idx+1,
                        ], $item);
                    }
                }
                $current = null;
            }
        }
        return $out;
    }

    public static function normalizeName(string $name): string
    {
        $name = html_entity_decode($name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $name = str_replace(["’", "‘", "`"], "'", $name);
        return strtolower(trim(preg_replace('/\s+/u', ' ', $name) ?? $name));
    }
}
