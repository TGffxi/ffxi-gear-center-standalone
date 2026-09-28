<?php
declare(strict_types=1);

final class LacLocator
{
    public static function discover(array $config, ?int $serverId = null): array
    {
        $base = rtrim((string)$config['ashita_root'], '/') . '/config/addons/LuAshitacast';
        if (!is_dir($base)) {
            throw new RuntimeException('LuAshitacast config directory not found: ' . $base);
        }

        $character = (string)($config['character'] ?? '');
        $dirs = [];
        if ($serverId !== null) {
            $candidate = $base . '/' . $character . '_' . $serverId;
            if (is_dir($candidate)) $dirs[] = $candidate;
        }
        foreach (glob($base . '/' . $character . '_*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (!in_array($dir, $dirs, true)) $dirs[] = $dir;
        }
        if ($dirs === []) {
            throw new RuntimeException('No LuAshitacast character directory found for ' . $character . ' under ' . $base);
        }

        $profileDir = $dirs[0];
        $profiles = [];
        foreach (glob($profileDir . '/*.lua') ?: [] as $file) {
            $job = strtoupper(pathinfo($file, PATHINFO_FILENAME));
            if (preg_match('/^[A-Z]{3}$/', $job)) {
                $profiles[$job] = $file;
            }
        }
        ksort($profiles);

        $commonDir = $base . '/common';
        $gcinclude = is_file($commonDir . '/gcinclude.lua') ? $commonDir . '/gcinclude.lua' : null;
        $gcdisplay = is_file($commonDir . '/gcdisplay.lua') ? $commonDir . '/gcdisplay.lua' : null;

        return [
            'base' => $base,
            'profile_dir' => $profileDir,
            'profiles' => $profiles,
            'common_dir' => $commonDir,
            'gcinclude' => $gcinclude,
            'gcdisplay' => $gcdisplay,
        ];
    }
}
