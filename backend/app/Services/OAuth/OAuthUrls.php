<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Connection\Connection;

/**
 * The addresses of the plugin's OAuth server (docs/mcp-in-plugin.md §4.1).
 *
 *   B  the public base: MCPMATE_PUBLIC_URL (a tunnel) or home_url()
 *   I  the issuer, `B/mcpmate`: every OAuth endpoint lives under it
 *   R  the resource, the MCP URL `B/wp-json/mcpmate/mcp`
 *
 * The issuer has a path on purpose: its metadata can then also be served
 * path-appended under WordPress (`I/.well-known/…`) for hosts that keep
 * `/.well-known` for themselves.
 */
final class OAuthUrls
{
    /** The issuer's path under B, and the first segment of every OAuth rewrite rule. */
    public const ISSUER_PATH = 'mcpmate';

    /** The MCP route, in its own REST namespace (not REST_NS). */
    public const MCP_ROUTE = 'mcpmate/mcp';

    public const SCOPE_READ = 'site:read';

    public const SCOPE_WRITE = 'site:write';

    public const SCOPES = [self::SCOPE_READ, self::SCOPE_WRITE];

    /** Accepted and ignored: every grant gets a refresh token. */
    public const SCOPE_OFFLINE = 'offline_access';

    private const LOCAL_SUFFIXES = ['.local', '.test', '.localhost'];

    public static function base(): string
    {
        $public = Connection::publicUrl();

        return $public !== '' ? $public : untrailingslashit(home_url());
    }

    public static function issuer(): string
    {
        return self::base() . '/' . self::ISSUER_PATH;
    }

    /**
     * R. With Plain permalinks the REST API only answers `?rest_route=`, and
     * the OAuth rewrite rules do not exist either (Diagnostics says so).
     */
    public static function resource(): string
    {
        if ((string) get_option('permalink_structure') === '') {
            return self::base() . '/?rest_route=/' . self::MCP_ROUTE;
        }

        return self::base() . '/' . rest_get_url_prefix() . '/' . self::MCP_ROUTE;
    }

    /**
     * An endpoint under the issuer: `authorize`, `token`, `register`,
     * `revoke`, `resource-metadata`.
     */
    public static function endpoint(string $name): string
    {
        return self::issuer() . '/' . $name;
    }

    /**
     * Whether $candidate names R. Compared as URLs (RFC 8707): scheme and
     * host case-insensitive, no fragment, a trailing slash ignored.
     */
    public static function isResource(string $candidate): bool
    {
        $normalized = self::normalize($candidate);

        return $normalized !== null && $normalized === self::normalize(self::resource());
    }

    /**
     * A loopback address or a local development name (`.local`, `.test`,
     * `.localhost`), for the plain-HTTP exception of rule 8.
     */
    public static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            return true;
        }

        foreach (self::LOCAL_SUFFIXES as $suffix) {
            if ((string) substr($host, -strlen($suffix)) === $suffix) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $url): ?string
    {
        $parts = wp_parse_url(trim($url));

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $normalized = strtolower($parts['scheme']) . '://' . strtolower($parts['host'])
            . (isset($parts['port']) ? ':' . $parts['port'] : '')
            . rtrim($parts['path'] ?? '', '/');

        return isset($parts['query']) ? $normalized . '?' . $parts['query'] : $normalized;
    }
}
