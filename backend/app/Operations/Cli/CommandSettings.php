<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Which registered commands a site admin has enabled.
 *
 * Every command ships disabled. Admins switch commands on in the plugin
 * settings; the list is stored in the `MCPMATE_cli_commands` option.
 * Enabling a destructive command only makes it requestable: each run
 * still waits for approval unless it is a dry run.
 */
final class CommandSettings
{
    public const OPTION = 'cli_commands';

    /**
     * Enabled command names.
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
     * Replace the enabled list. Names that are not registered are dropped.
     *
     * @param mixed $names
     * @return string[] The stored list.
     */
    public static function update($names, CommandRegistry $registry): array
    {
        $names = is_array($names) ? $names : [];
        $names = array_values(array_unique(array_filter(
            $names,
            static fn ($name) => is_string($name) && $registry->has($name)
        )));
        sort($names);

        Config::updateOption(self::OPTION, $names, false);

        return $names;
    }
}
