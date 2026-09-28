<?php
declare(strict_types=1);

final class Config
{
    public static function load(string $root): array
    {
        $character = trim((string)(getenv('GEAR_CENTER_CHARACTER') ?: ''));
        $characterFile = $root . '/data/character.txt';
        if ($character === '' && is_file($characterFile)) {
            $character = trim((string)file_get_contents($characterFile));
        }

        $defaults = [
            'app_name' => 'FFXI Gear Center',
            'character' => $character,
            'ashita_root' => getenv('HOME') . '/Games/FFXI/ashita',
            'gearfinder_dir' => null,
            'db_path' => $root . '/data/gear-center.sqlite',
        ];

        $local = $root . '/config/local.php';
        if (!is_file($local)) {
            return $defaults;
        }

        $loaded = require $local;
        if (!is_array($loaded)) {
            throw new RuntimeException('config/local.php must return an array.');
        }

        return array_replace($defaults, $loaded);
    }
}
