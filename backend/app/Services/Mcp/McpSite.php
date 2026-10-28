<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * This site as the tools name it: a stable id (`site_id` in every tool's
 * output, and the scope of this-site skills) and the URL links are built
 * from.
 */
final class McpSite
{
    public const OPTION = 'mcp_site_id';

    /**
     * A UUID made once per site and kept (option `MCPMATE_mcp_site_id`).
     */
    public static function id(): string
    {
        $id = (string) Config::getOption(self::OPTION, '');

        if (!preg_match('/^[0-9a-f-]{36}$/D', $id)) {
            $id = wp_generate_uuid4();
            Config::updateOption(self::OPTION, $id, true);
        }

        return $id;
    }

    /**
     * The home URL without a trailing slash: approval, editor and preview
     * links are built from it, and links a route returns must be on its host.
     */
    public static function url(): string
    {
        return untrailingslashit(home_url());
    }
}
