<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Active page-cache plugins (pure), named in the caching check's fix so the
 * admin knows where to exclude the sign-in addresses (docs/mcp-in-plugin.md
 * §10.3). A hard-coded list without a filter, like SecurityPlugins.
 */
final class CachePlugins
{
    /** Plugin folder => product name (not translated). */
    private const KNOWN = [
        'litespeed-cache'          => 'LiteSpeed Cache',
        'wp-rocket'                => 'WP Rocket',
        'w3-total-cache'           => 'W3 Total Cache',
        'wp-super-cache'           => 'WP Super Cache',
        'sg-cachepress'            => 'SiteGround Speed Optimizer',
        'wp-fastest-cache'         => 'WP Fastest Cache',
        'cache-enabler'            => 'Cache Enabler',
        'breeze'                   => 'Breeze',
        'hummingbird-performance'  => 'Hummingbird',
        'wp-optimize'              => 'WP-Optimize',
        'nitropack'                => 'NitroPack',
        'comet-cache'              => 'Comet Cache',
        'cloudflare'               => 'Cloudflare (APO)',
        'swift-performance-lite'   => 'Swift Performance',
        'flying-press'             => 'FlyingPress',
    ];

    /**
     * Product names for the active plugins in $pluginFiles (`folder/file.php`), in list order, once each.
     *
     * @param string[] $pluginFiles
     * @return string[]
     */
    public static function detect(array $pluginFiles): array
    {
        $names = [];

        foreach ($pluginFiles as $file) {
            $folder = strtolower((string) strtok((string) $file, '/'));

            if (isset(self::KNOWN[$folder])) {
                $names[] = self::KNOWN[$folder];
            }
        }

        return array_values(array_unique($names));
    }
}
