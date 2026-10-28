<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redirect URI rules (docs/mcp-in-plugin.md §4.3, the cloud's
 * `redirectUriProblem()`): `https://`, or `http://` on localhost,
 * 127.0.0.1 or [::1]. No fragment, no credentials, no custom schemes.
 *
 * A requested URI must equal a registered one exactly, except that a
 * loopback URI may use any port (OAuth 2.1 §8.4.2: native apps pick a free
 * port when they start).
 *
 * Pure: no WordPress calls.
 */
final class RedirectUris
{
    /** Redirect URIs per client. */
    public const MAX = 10;

    public const MAX_LENGTH = 2048;

    private const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    /**
     * Why $uri cannot be a redirect URI, or null when it can.
     */
    public static function problem(string $uri): ?string
    {
        if ($uri === '' || strlen($uri) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $uri)) {
            return 'redirect_uris must be absolute URLs';
        }

        $parts = self::parse($uri);

        if ($parts === null) {
            return 'redirect_uris must be absolute URLs';
        }

        if (isset($parts['fragment'])) {
            return 'redirect_uris must not contain a fragment';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'redirect_uris must not contain credentials';
        }

        if ($parts['scheme'] === 'https' || ($parts['scheme'] === 'http' && self::isLoopback($parts['host']))) {
            return null;
        }

        return 'redirect_uris must use https, or http on localhost, 127.0.0.1 or [::1]';
    }

    public static function isLoopback(string $host): bool
    {
        return in_array(strtolower($host), self::LOOPBACK, true);
    }

    /**
     * The registered URI $requested matches, or null. Exact match; for a
     * loopback registration the port may differ.
     *
     * @param array<int, mixed> $registered
     */
    public static function match(string $requested, array $registered): ?string
    {
        if (self::problem($requested) !== null) {
            return null;
        }

        foreach ($registered as $uri) {
            if (!is_string($uri)) {
                continue;
            }

            if ($uri === $requested) {
                return $uri;
            }

            $a = self::parse($uri);
            $b = self::parse($requested);

            if ($a === null || $b === null || $a['scheme'] !== 'http' || !self::isLoopback($a['host'])) {
                continue;
            }

            unset($a['port'], $b['port']);

            if ($a === $b) {
                return $requested;
            }
        }

        return null;
    }

    /**
     * The host shown on the approval screen ("Sends access to").
     */
    public static function host(string $uri): string
    {
        $parts = self::parse($uri);

        if ($parts === null) {
            return '';
        }

        return $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * $uri with $params added to its query (RFC 6749 §4.1.2: existing query
     * components are kept).
     *
     * @param array<string, string> $params
     */
    public static function withQuery(string $uri, array $params): string
    {
        $params = array_filter($params, static fn (string $value): bool => $value !== '');

        return $uri . (strpos($uri, '?') === false ? '?' : '&') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{scheme: string, host: string, port?: int, path?: string, query?: string, fragment?: string, user?: string, pass?: string}|null
     */
    private static function parse(string $uri): ?array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, unit-tested without WordPress.
        $parts = parse_url($uri);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $parts['scheme'] = strtolower($parts['scheme']);
        $parts['host']   = strtolower($parts['host']);

        return $parts;
    }
}
