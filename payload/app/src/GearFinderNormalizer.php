<?php
declare(strict_types=1);

final class GearFinderNormalizer
{
    public static function readJsonArray(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Missing file: ' . $path);
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Could not read file: ' . $path);
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !array_is_list($data)) {
            throw new RuntimeException(basename($path) . ' must contain a JSON array.');
        }
        return $data;
    }

    public static function hashFile(string $path): string
    {
        $hash = hash_file('sha256', $path);
        if ($hash === false) {
            throw new RuntimeException('Could not hash file: ' . $path);
        }
        return $hash;
    }

    public static function resourceMap(array $res): array
    {
        $map = [];
        foreach ($res as $index => $row) {
            self::requireKeys($row, ['id', 'name', 'jobs', 'equipSlots', 'lvl', 'ilvl', 'su'], 'res.json[' . $index . ']');
            $id = (int)$row['id'];
            if (isset($map[$id])) {
                throw new RuntimeException('Duplicate item id in res.json: ' . $id);
            }
            $map[$id] = $row;
        }
        return $map;
    }

    public static function validateGear(array $gear, array $resourceMap): array
    {
        $warnings = [];
        $locations = [];

        foreach ($gear as $index => $row) {
            self::requireKeys($row, ['id', 'bag', 'slot', 'slip', 'working', 'augStats'], 'gear.json[' . $index . ']');
            $id = (int)$row['id'];
            if (!isset($resourceMap[$id])) {
                $warnings[] = 'Item id ' . $id . ' exists in gear.json but not res.json.';
            }
            $loc = (int)$row['bag'] . ':' . (int)$row['slot'];
            if (isset($locations[$loc])) {
                $warnings[] = 'Duplicate bag/slot location in gear.json: ' . $loc;
            }
            $locations[$loc] = true;
        }

        return $warnings;
    }

    public static function augmentStats(array $gearRow): array
    {
        $aug = $gearRow['augStats'] ?? [];
        if (!is_array($aug)) {
            return [];
        }
        $base = $aug['base'] ?? [];
        return is_array($base) ? $base : [];
    }

    public static function augmentSignature(array $gearRow): string
    {
        $stats = self::augmentStats($gearRow);
        if ($stats === []) {
            return 'none';
        }
        return 'gfstats:' . hash('sha256', CanonicalJson::encode($stats));
    }

    public static function normalizedAugmentJson(array $gearRow): string
    {
        return CanonicalJson::encode(self::augmentStats($gearRow));
    }

    public static function normalizedResourceStatsJson(array $resRow): string
    {
        $stats = $resRow['stats'] ?? [];
        return CanonicalJson::encode(is_array($stats) ? $stats : []);
    }

    public static function summary(array $gear, array $resourceMap): array
    {
        $bagCounts = [];
        $augmented = 0;
        $slips = 0;
        $idCounts = [];

        foreach ($gear as $row) {
            $bag = (int)$row['bag'];
            $bagCounts[$bag] = ($bagCounts[$bag] ?? 0) + 1;
            $id = (int)$row['id'];
            $idCounts[$id] = ($idCounts[$id] ?? 0) + 1;
            if (self::augmentStats($row) !== []) {
                $augmented++;
            }
            if ((int)$row['slip'] !== 0) {
                $slips++;
            }
        }
        ksort($bagCounts);
        $duplicateIds = array_filter($idCounts, static fn(int $count): bool => $count > 1);

        return [
            'gear_entries' => count($gear),
            'unique_item_ids' => count($idCounts),
            'resource_entries' => count($resourceMap),
            'augmented_entries' => $augmented,
            'porter_slip_entries' => $slips,
            'duplicate_item_ids' => count($duplicateIds),
            'bag_counts' => $bagCounts,
        ];
    }

    private static function requireKeys(array $row, array $keys, string $context): void
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                throw new RuntimeException($context . ' missing required key: ' . $key);
            }
        }
    }
}
