<?php
declare(strict_types=1);

final class GearFinderLocator
{
    public static function discover(array $config, ?string $explicitDir = null): array
    {
        if ($explicitDir !== null) {
            return self::describe($explicitDir, $config['character'] ?? null);
        }
        if (!empty($config['gearfinder_dir'])) {
            return self::describe((string)$config['gearfinder_dir'], $config['character'] ?? null);
        }

        $ashitaRoot = rtrim((string)$config['ashita_root'], '/');
        $bases = [
            $ashitaRoot . '/config/addons/gearfinder',
            $ashitaRoot . '/config/addons/GearFinder',
        ];
        $character = (string)($config['character'] ?? '');
        $candidates = [];

        foreach ($bases as $base) {
            if (!is_dir($base)) {
                continue;
            }
            $dirs = glob($base . '/*', GLOB_ONLYDIR) ?: [];
            foreach ($dirs as $dir) {
                if (!is_file($dir . '/gear.json') || !is_file($dir . '/res.json')) {
                    continue;
                }
                $desc = self::describe($dir, $character !== '' ? $character : null);
                if ($character !== '' && strcasecmp($desc['character'] ?? '', $character) !== 0) {
                    continue;
                }
                $desc['mtime'] = max(filemtime($dir . '/gear.json') ?: 0, filemtime($dir . '/res.json') ?: 0);
                $candidates[] = $desc;
            }
        }

        if ($candidates === []) {
            throw new RuntimeException('No GearFinder cache directory with gear.json + res.json found under Ashita.');
        }
        usort($candidates, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $candidates[0];
    }

    private static function describe(string $dir, ?string $fallbackCharacter): array
    {
        $dir = rtrim($dir, '/');
        if (!is_file($dir . '/gear.json') || !is_file($dir . '/res.json')) {
            throw new RuntimeException('GearFinder directory must contain gear.json and res.json: ' . $dir);
        }

        $base = basename($dir);
        $character = $fallbackCharacter;
        $serverId = null;
        if (preg_match('/^(.+?)[_-](\d+)$/', $base, $m)) {
            $character = $m[1];
            $serverId = (int)$m[2];
        }

        if ($character === null || $character === '') {
            $character = $base;
        }

        return [
            'dir' => $dir,
            'gear' => $dir . '/gear.json',
            'res' => $dir . '/res.json',
            'character' => $character,
            'server_id' => $serverId,
        ];
    }
}
