<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Which registered abilities a site admin has enabled for POST /abilities/run.
 *
 * Every ability ships disabled, including ones a plugin registers later.
 * Admins switch abilities on in the plugin settings; the names are stored
 * in the `MCPMATE_abilities` option. Enabling a destructive ability
 * only makes it requestable: each run still waits for approval.
 */
final class AbilitySettings
{
    public const OPTION = 'abilities';

    /**
     * Enabled ability names.
     *
     * @return string[]
     */
    public static function enabled(): array
    {
        $saved = Config::getOption(self::OPTION, []);

        return is_array($saved) ? array_values(array_filter($saved, 'is_string')) : [];
    }

    public static function isEnabled(string $name): bool
    {
        return in_array($name, self::enabled(), true);
    }

    /**
     * Replace the enabled list. Names not in $available are dropped.
     *
     * @param mixed    $names
     * @param string[] $available Names of the abilities that may be enabled (Abilities::all()).
     * @return string[] The stored list.
     */
    public static function update($names, array $available): array
    {
        $names = is_array($names) ? $names : [];
        $names = array_values(array_unique(array_filter(
            $names,
            static fn ($name) => is_string($name) && in_array($name, $available, true)
        )));
        sort($names);

        Config::updateOption(self::OPTION, $names, false);

        return $names;
    }
}
