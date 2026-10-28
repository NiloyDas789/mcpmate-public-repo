<?php

namespace McpMate\Services\CoreProxy;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Tools\ToolSettings;

/**
 * Whether a site admin has allowed POST /proxy (core posts and pages).
 * On while any tool of the Tools page's "Posts and pages" group is on
 * (ToolSettings, off on new installs).
 */
final class CoreProxySettings
{
    /** The switch's own option before 0.6.0; the 0.6.0 migration moved it into ToolSettings. */
    public const OPTION = 'core_proxy';

    /** The Tools page group this switch turns on and off (ToolSettings). */
    public const GROUP = 'posts';

    public static function isEnabled(): bool
    {
        return ToolSettings::groupEnabled(self::GROUP);
    }

    public static function update(bool $enabled): bool
    {
        return ToolSettings::setGroup(self::GROUP, $enabled);
    }
}
