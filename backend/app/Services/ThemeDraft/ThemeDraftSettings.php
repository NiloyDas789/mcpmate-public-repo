<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Tools\ToolSettings;

/**
 * Whether a site admin has allowed the AI to work on theme drafts (the
 * /theme-draft routes and preview links). On while any tool of the Tools
 * page's "Theme drafts" group is on (ToolSettings, off on new installs);
 * each route also needs its own tool on (Router::authorizeMcp()).
 */
final class ThemeDraftSettings
{
    /** The switch's own option before 0.6.0; the 0.6.0 migration moved it into ToolSettings. */
    public const OPTION = 'theme_drafts';

    /** The Tools page group this switch turns on and off (ToolSettings). */
    public const GROUP = 'theme_drafts';

    public static function isEnabled(): bool
    {
        return ToolSettings::groupEnabled(self::GROUP);
    }

    public static function update(bool $enabled): bool
    {
        return ToolSettings::setGroup(self::GROUP, $enabled);
    }
}
