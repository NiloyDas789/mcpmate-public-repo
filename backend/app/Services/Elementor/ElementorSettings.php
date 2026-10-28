<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Tools\ToolSettings;

/**
 * Whether a site admin has allowed the AI to read and edit Elementor pages
 * (the /elementor REST routes). On while any tool of the Tools page's
 * "Elementor" group is on (ToolSettings, off on new installs).
 */
final class ElementorSettings
{
    /** The switch's own option before 0.6.0; the 0.6.0 migration moved it into ToolSettings. */
    public const OPTION = 'elementor';

    /** The Tools page group this switch turns on and off (ToolSettings). */
    public const GROUP = 'elementor';

    public static function isEnabled(): bool
    {
        return ToolSettings::groupEnabled(self::GROUP);
    }

    public static function update(bool $enabled): bool
    {
        return ToolSettings::setGroup(self::GROUP, $enabled);
    }
}
