<?php
declare(strict_types=1);

final class AugmentBridgeLocator
{
    public static function discover(array $config, ?int $serverId = null): array
    {
        $lac = LacLocator::discover($config, $serverId);
        return [
            'profile_dir' => $lac['profile_dir'],
            'path' => $lac['profile_dir'] . '/gearcenter_augments.json',
        ];
    }
}
