<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Active plugins known to block requests from outside (pure). Named in the
 * checks' and the connect step's fixes so the admin knows where to look.
 * A hard-coded list without a filter (setup open question 13).
 */
final class SecurityPlugins
{
    /** Plugin folder => product name (not translated). */
    private const KNOWN = [
        'wordfence'                          => 'Wordfence',
        'better-wp-security'                 => 'Solid Security',
        'ithemes-security-pro'               => 'Solid Security Pro',
        'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
        'sucuri-scanner'                     => 'Sucuri Security',
        'wp-cerber'                          => 'WP Cerber',
        'ninjafirewall'                      => 'NinjaFirewall',
        'wp-simple-firewall'                 => 'Shield Security',
        'wp-defender'                        => 'Defender',
        'defender-security'                  => 'Defender',
        'malcare-security'                   => 'MalCare',
    ];

    /** Folders like `disable-json-api` and `disable-wp-rest-api`. */
    private const DISABLE_REST = '/^disable-.*(rest|json)/';

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
            } elseif (preg_match(self::DISABLE_REST, $folder)) {
                $names[] = ucwords(str_replace('-', ' ', $folder));
            }
        }

        return array_values(array_unique($names));
    }
}
