<?php
declare(strict_types=1);

final class BagMap
{
    private const NAMES = [
        0 => 'Inventory',
        1 => 'Safe',
        2 => 'Storage',
        3 => 'Temporary',
        4 => 'Locker',
        5 => 'Satchel',
        6 => 'Sack',
        7 => 'Case',
        8 => 'Wardrobe 1',
        9 => 'Safe 2',
        10 => 'Wardrobe 2',
        11 => 'Wardrobe 3',
        12 => 'Wardrobe 4',
        13 => 'Wardrobe 5',
        14 => 'Wardrobe 6',
        15 => 'Wardrobe 7',
        16 => 'Wardrobe 8',
        17 => 'Recycle',
    ];

    public static function name(int $id): string
    {
        return self::NAMES[$id] ?? ('Container ' . $id);
    }

    public static function all(): array
    {
        return self::NAMES;
    }
}
