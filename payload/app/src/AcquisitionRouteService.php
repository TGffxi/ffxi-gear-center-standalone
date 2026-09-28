<?php
declare(strict_types=1);

final class AcquisitionRouteService
{
    /** @return array<int,array<string,mixed>> */
    public function build(string $itemName, array $records, ?array $page = null): array
    {
        $routes = [];
        foreach ($records as $record) {
            $section = trim((string)($record['section'] ?? ''));
            $fields = is_array($record['fields'] ?? null) ? $record['fields'] : [];
            if ($section === '' || $fields === []) continue;

            $kind = $this->kindForSection($section);
            if ($kind === null) continue;

            $actor = $this->actor($fields);
            $zone = $this->field($fields, ['Zone', 'Area', 'Location']);
            $notes = $this->field($fields, ['Notes', 'Note', 'Requirements', 'Required Items', 'Requirement']);
            $content = $this->detectContent($fields, $notes) ?? ($kind === 'redeem' && $actor !== '' ? $actor : ($zone !== '' ? $this->firstLine($zone) : null));
            $steps = $this->steps($itemName, $kind, $section, $actor, $zone, $notes, $fields);
            $title = $this->title($itemName, $kind, $actor, $section);
            $summaryParts = array_values(array_filter([$actor, $this->firstLine($zone), $notes], static fn($v) => trim((string)$v) !== ''));
            $summary = implode(' · ', array_slice($summaryParts, 0, 3));

            $fingerprint = [
                'item'=>LacProfileParser::normalizeName($itemName),
                'section'=>$section,
                'fields'=>$fields,
            ];
            $routeKey = substr(hash('sha256', CanonicalJson::encode($fingerprint)), 0, 24);

            $routes[$routeKey] = [
                'key'=>$routeKey,
                'kind'=>$kind,
                'group'=>$this->groupForKind($kind),
                'section'=>$section,
                'title'=>$title,
                'task_type'=>$this->taskType($kind),
                'content_name'=>$content,
                'summary'=>$summary,
                'steps'=>$steps,
                'fields'=>$fields,
                'source_title'=>(string)($page['page_title'] ?? 'BG-Wiki'),
                'source_url'=>(string)($page['canonical_url'] ?? ''),
                'confidence'=>'structured_bgwiki',
            ];
        }
        return array_values($routes);
    }

    public function find(string $itemName, array $records, ?array $page, string $routeKey): ?array
    {
        foreach ($this->build($itemName, $records, $page) as $route) {
            if (hash_equals((string)$route['key'], $routeKey)) return $route;
        }
        return null;
    }

    private function kindForSection(string $section): ?string
    {
        $s = strtolower(trim($section));
        return match (true) {
            str_contains($s, 'dropped from') => 'drop',
            str_contains($s, 'redeemed from') => 'redeem',
            str_contains($s, 'purchased from') || str_contains($s, 'bought from') => 'purchase',
            str_contains($s, 'quest') => 'quest',
            str_contains($s, 'augmented via') || str_contains($s, 'augment') => 'augment',
            str_contains($s, 'upgraded from') || str_contains($s, 'upgrade info') || str_contains($s, 'item upgrades') => 'upgrade',
            str_contains($s, 'used in recipe') || str_contains($s, 'crafted') || str_contains($s, 'synthesis') || str_contains($s, 'synergy') => 'craft',
            str_contains($s, 'obtained from') => 'obtain',
            default => null,
        };
    }

    private function groupForKind(string $kind): string
    {
        return in_array($kind, ['drop','redeem','purchase','quest','obtain','craft'], true) ? 'Acquisition' : 'Progression';
    }

    private function taskType(string $kind): string
    {
        return match ($kind) {
            'drop' => 'farm',
            'augment' => 'augment',
            'upgrade' => 'upgrade',
            'craft' => 'upgrade',
            default => 'obtain',
        };
    }

    private function actor(array $fields): string
    {
        return $this->field($fields, ['Monster Name','NPC Name','NPC','Quest','Source','Name','Battlefield','Event','Content']);
    }

    private function field(array $fields, array $names): string
    {
        foreach ($names as $name) {
            foreach ($fields as $key => $value) {
                if (strcasecmp(trim((string)$key), $name) === 0) return trim((string)$value);
            }
        }
        return '';
    }

    private function firstLine(string $value): string
    {
        $parts = preg_split('/\R+/u', trim($value)) ?: [];
        return trim((string)($parts[0] ?? ''));
    }

    private function detectContent(array $fields, string $notes): ?string
    {
        $haystack = strtolower(implode("\n", array_map('strval', array_values($fields))) . "\n" . $notes);
        $known = [
            'geas fete'=>'Geas Fete', 'sortie'=>'Sortie', 'odyssey'=>'Odyssey', 'dynamis - divergence'=>'Dynamis - Divergence',
            'dynamis divergence'=>'Dynamis - Divergence', 'ambuscade'=>'Ambuscade', 'omen'=>'Omen', 'vagary'=>'Vagary',
            'unity'=>'Unity', 'delve'=>'Delve', 'high-tier battlefield'=>'High-Tier Battlefield', 'htbf'=>'High-Tier Battlefield',
            'limbus'=>'Limbus', 'skirmish'=>'Skirmish', 'escha'=>'Escha', 'reisenjima'=>'Reisenjima',
        ];
        foreach ($known as $needle => $label) if (str_contains($haystack, $needle)) return $label;
        return null;
    }

    /** @return array<int,string> */
    private function steps(string $item, string $kind, string $section, string $actor, string $zone, string $notes, array $fields): array
    {
        $steps = [];
        $zoneOneLine = preg_replace('/\R+/u', ' / ', trim($zone)) ?? trim($zone);
        $notesOneLine = preg_replace('/\R+/u', ' / ', trim($notes)) ?? trim($notes);

        if ($kind === 'drop') {
            if ($zoneOneLine !== '') $steps[] = 'Travel to ' . $zoneOneLine;
            if ($notesOneLine !== '') $steps[] = 'Prepare source requirement: ' . $notesOneLine;
            $steps[] = $actor !== '' ? 'Defeat ' . $actor . ' and obtain ' . $item : 'Farm the listed source until ' . $item . ' drops';
        } elseif ($kind === 'redeem') {
            if ($notesOneLine !== '') $steps[] = 'Prepare redemption requirement: ' . $notesOneLine;
            if ($actor !== '' && $zoneOneLine !== '') $steps[] = 'Visit ' . $actor . ' at ' . $zoneOneLine;
            elseif ($actor !== '') $steps[] = 'Visit ' . $actor;
            $steps[] = $actor !== '' ? 'Redeem ' . $item . ' from ' . $actor : 'Redeem ' . $item . ' from the listed source';
        } elseif ($kind === 'purchase') {
            if ($actor !== '' && $zoneOneLine !== '') $steps[] = 'Visit ' . $actor . ' at ' . $zoneOneLine;
            elseif ($zoneOneLine !== '') $steps[] = 'Travel to ' . $zoneOneLine;
            if ($notesOneLine !== '') $steps[] = 'Meet purchase requirement: ' . $notesOneLine;
            $steps[] = 'Purchase ' . $item . ($actor !== '' ? ' from ' . $actor : '');
        } elseif ($kind === 'quest') {
            if ($zoneOneLine !== '') $steps[] = 'Travel to ' . $zoneOneLine;
            if ($notesOneLine !== '') $steps[] = 'Quest requirement: ' . $notesOneLine;
            $steps[] = 'Complete ' . ($actor !== '' ? $actor : 'the listed quest') . ' and obtain ' . $item;
        } elseif ($kind === 'augment') {
            if ($notesOneLine !== '') $steps[] = 'Prepare augment requirement: ' . $notesOneLine;
            if ($actor !== '' && $zoneOneLine !== '') $steps[] = 'Visit ' . $actor . ' at ' . $zoneOneLine;
            $steps[] = 'Apply the listed augment/progression to ' . $item;
        } elseif ($kind === 'upgrade' || $kind === 'craft') {
            if ($notesOneLine !== '') $steps[] = 'Prepare upgrade requirement: ' . $notesOneLine;
            if ($actor !== '' && $zoneOneLine !== '') $steps[] = 'Visit ' . $actor . ' at ' . $zoneOneLine;
            $steps[] = 'Complete the listed ' . ($kind === 'craft' ? 'recipe' : 'upgrade') . ' for ' . $item;
        } else {
            if ($zoneOneLine !== '') $steps[] = 'Travel to ' . $zoneOneLine;
            if ($notesOneLine !== '') $steps[] = 'Meet source requirement: ' . $notesOneLine;
            $steps[] = 'Obtain ' . $item . ' via ' . ($actor !== '' ? $actor : $section);
        }

        return array_values(array_unique(array_filter($steps, static fn($x) => trim($x) !== '')));
    }

    private function title(string $item, string $kind, string $actor, string $section): string
    {
        return match ($kind) {
            'drop' => 'Farm ' . $item . ($actor !== '' ? ' from ' . $actor : ''),
            'redeem' => 'Redeem ' . $item . ($actor !== '' ? ' from ' . $actor : ''),
            'purchase' => 'Purchase ' . $item . ($actor !== '' ? ' from ' . $actor : ''),
            'quest' => 'Quest for ' . $item . ($actor !== '' ? ': ' . $actor : ''),
            'augment' => 'Augment ' . $item . ($actor !== '' ? ' via ' . $actor : ''),
            'upgrade' => 'Upgrade ' . $item . ($actor !== '' ? ' via ' . $actor : ''),
            'craft' => 'Craft/upgrade ' . $item,
            default => 'Obtain ' . $item . ' via ' . $section,
        };
    }
}
