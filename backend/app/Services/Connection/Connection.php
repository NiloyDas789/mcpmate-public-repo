<?php

namespace McpMate\Services\Connection;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\OAuth\OAuthUrls;
use McpMate\Services\Settings\Preferences;

/**
 * How AI apps reach this site (docs/mcp-in-plugin.md §4.1): the MCP URL R
 * and the OAuth issuer I, under MCPMATE_PUBLIC_URL (a tunnel for a
 * local site) or home_url(). Nothing is registered anywhere: the MCP URL
 * works as soon as the plugin is active.
 */
final class Connection
{
    /**
     * MCPMATE_PUBLIC_URL (a tunnel for a local site), or ''.
     */
    public static function publicUrl(): string
    {
        $url = defined('MCPMATE_PUBLIC_URL') ? constant('MCPMATE_PUBLIC_URL') : '';

        return is_string($url) && wp_http_validate_url($url) ? untrailingslashit($url) : '';
    }

    public static function isLocal(): bool
    {
        return wp_get_environment_type() === 'local';
    }

    /**
     * What the Connections page, the Dashboard and setup show. Public
     * addresses only.
     *
     * @return array{mcp_url: string, issuer: string, home_url: string, public_url: string, open_registration: bool}
     */
    public static function summary(): array
    {
        return [
            'mcp_url'           => OAuthUrls::resource(),
            'issuer'            => OAuthUrls::issuer(),
            'home_url'          => untrailingslashit(home_url()),
            'public_url'        => self::publicUrl(),
            'open_registration' => Preferences::openRegistration(),
        ];
    }
}
