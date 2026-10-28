<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Tools\ToolSettings;

/**
 * Whether a site admin has allowed the AI to upload media (POST /media).
 * On while the Tools page's upload_media tool is on (ToolSettings, off on
 * new installs).
 *
 * SVG files have a switch of their own (`MCPMATE_media_svg`, off on new
 * installs): SVG can carry code, so it is allowed separately even though
 * SvgSanitizer rebuilds every file. MediaUploader enforces it.
 */
final class MediaSettings
{
    /** The switch's own option before 0.6.0; the 0.6.0 migration moved it into ToolSettings. */
    public const OPTION = 'media_upload';

    public const SVG_OPTION = 'media_svg';

    /** The Tools page group this switch turns on and off (ToolSettings). */
    public const GROUP = 'media';

    public static function isEnabled(): bool
    {
        return ToolSettings::groupEnabled(self::GROUP);
    }

    public static function update(bool $enabled): bool
    {
        return ToolSettings::setGroup(self::GROUP, $enabled);
    }

    public static function svgEnabled(): bool
    {
        return in_array(Config::getOption(self::SVG_OPTION, false), [true, 1, '1'], true);
    }

    public static function updateSvg(bool $enabled): bool
    {
        Config::updateOption(self::SVG_OPTION, $enabled, false);

        return $enabled;
    }
}
