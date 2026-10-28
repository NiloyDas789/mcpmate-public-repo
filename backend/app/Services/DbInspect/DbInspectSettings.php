<?php

namespace McpMate\Services\DbInspect;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Tools\ToolSettings;

/**
 * Whether a site admin has allowed the AI to inspect the database
 * (the /db routes). On while the Tools page's query_db tool is on
 * (ToolSettings, off on new installs).
 */
final class DbInspectSettings
{
    /** The switch's own option before 0.6.0; the 0.6.0 migration moved it into ToolSettings. */
    public const OPTION = 'db_inspect';

    /** The Tools page group this switch turns on and off (ToolSettings). */
    public const GROUP = 'database';

    public static function isEnabled(): bool
    {
        return ToolSettings::groupEnabled(self::GROUP);
    }

    public static function update(bool $enabled): bool
    {
        return ToolSettings::setGroup(self::GROUP, $enabled);
    }
}
