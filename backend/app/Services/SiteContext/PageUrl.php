<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Validates a front-end URL of this site before it is fetched.
 *
 * Accepted: an absolute http(s) URL on the home URL's host and port, or a
 * path starting with `/`, in both cases under the home URL's path. Rejected:
 * other hosts, credentials in the URL, whitespace/control characters and
 * backslashes, and WordPress system paths (wp-admin, wp-json, wp-includes,
 * wp-content, *.php other than index.php, `rest_route`).
 *
 * The URL that is fetched is rebuilt from the home URL's scheme, host and
 * port plus the validated path and query, so the HTTP client never parses
 * the caller's authority part.
 */
final class PageUrl
{
    public const ERROR_INVALID = 'invalid_url';

    public const ERROR_FOREIGN_HOST = 'foreign_host';

    public const ERROR_BLOCKED_PATH = 'blocked_path';

    public const MAX_LENGTH = 2048;

    /** Path segments that are never front-end pages. */
    private const BLOCKED_SEGMENTS = ['wp-admin', 'wp-json', 'wp-includes', 'wp-content'];

    /** Query keys that route a request away from the front end. */
    private const BLOCKED_QUERY_KEYS = ['rest_route'];

    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @return array{url: string, error: string} `url` is set on success; `error` is one of the ERROR_* codes.
     */
    public static function resolve(string $input, string $homeUrl): array
    {
        $input = trim($input);
        $home  = self::parse($homeUrl);

        if ($home === null || $input === '' || strlen($input) > self::MAX_LENGTH || preg_match('/[\x00-\x20\x7F\\\\]/', $input)) {
            return self::fail(self::ERROR_INVALID);
        }

        if (str_starts_with($input, '/') && !str_starts_with($input, '//')) {
            $target = self::parse($home['scheme'] . '://' . $home['authority'] . $input);
        } else {
            // Protocol-relative `//host/…` takes the home scheme, as a browser would.
            $target = self::parse(str_starts_with($input, '//') ? $home['scheme'] . ':' . $input : $input);

            if ($target === null) {
                return self::fail(self::ERROR_INVALID);
            }

            if ($target['has_userinfo'] || $target['host'] !== $home['host'] || $target['port'] !== $home['port']) {
                return self::fail(self::ERROR_FOREIGN_HOST);
            }
        }

        if ($target === null) {
            return self::fail(self::ERROR_INVALID);
        }

        $path     = self::removeDotSegments($target['path']);
        $homePath = rtrim($home['path'], '/');

        if ($homePath !== '' && $path !== $homePath && !str_starts_with($path, $homePath . '/')) {
            return self::fail(self::ERROR_FOREIGN_HOST);
        }

        if (self::isBlocked($path, $target['query'])) {
            return self::fail(self::ERROR_BLOCKED_PATH);
        }

        $url = $home['scheme'] . '://' . $home['authority'] . $path;

        if ($target['query'] !== '') {
            $url .= '?' . $target['query'];
        }

        return ['url' => $url, 'error' => ''];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @return array{scheme: string, host: string, port: int, authority: string, path: string, query: string, has_userinfo: bool}|null
     */
    private static function parse(string $url): ?array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper without WordPress; inputs are validated above.
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            return null;
        }

        $host = rtrim(strtolower($parts['host']), '.');

        if ($host === '') {
            return null;
        }

        // 0 = the scheme's default port, so http://host and https://host match
        // while https://host:80 does not.
        $port = isset($parts['port']) && (int) $parts['port'] !== self::DEFAULT_PORTS[$scheme] ? (int) $parts['port'] : 0;

        return [
            'scheme'       => $scheme,
            'host'         => $host,
            'port'         => $port,
            'authority'    => $host . ($port === 0 ? '' : ':' . $port),
            'path'         => isset($parts['path']) && $parts['path'] !== '' ? $parts['path'] : '/',
            'query'        => $parts['query'] ?? '',
            'has_userinfo' => isset($parts['user']) || isset($parts['pass']),
        ];
    }

    private static function isBlocked(string $path, string $query): bool
    {
        foreach (explode('/', strtolower(rawurldecode($path))) as $segment) {
            if (in_array($segment, self::BLOCKED_SEGMENTS, true)) {
                return true;
            }

            if (str_ends_with($segment, '.php') && $segment !== 'index.php') {
                return true;
            }
        }

        if ($query === '') {
            return false;
        }

        parse_str($query, $params);

        foreach (array_keys($params) as $key) {
            if (in_array(strtolower((string) $key), self::BLOCKED_QUERY_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * RFC 3986 §5.2.4 dot-segment removal, so `/a/../wp-admin/` is checked as `/wp-admin/`.
     * Encoded dots (`%2e`) are decoded first, as servers do.
     */
    private static function removeDotSegments(string $path): string
    {
        $path   = (string) preg_replace('/%2e/i', '.', $path);
        $output = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        $result = implode('/', $output);

        // Keep the trailing slash of `/a/.` and `/a/..`.
        if (preg_match('#/\.\.?$#', $path)) {
            $result .= '/';
        }

        return str_starts_with($result, '/') ? $result : '/' . $result;
    }

    /**
     * @return array{url: string, error: string}
     */
    private static function fail(string $error): array
    {
        return ['url' => '', 'error' => $error];
    }
}
