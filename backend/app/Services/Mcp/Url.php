<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The parts of WHATWG URL parsing the tools rely on (`new URL()` in the
 * cloud): scheme, host with a non-default port, and the normalized href.
 * Pure.
 */
final class Url
{
    private const DEFAULT_PORTS = ['http' => 80, 'https' => 443];

    /**
     * @return array{protocol: string, host: string, href: string}|null
     */
    public static function parse(string $url): ?array
    {
        if (!preg_match('/^([A-Za-z][A-Za-z0-9+.-]*):(.*)$/s', trim($url), $match)) {
            return null;
        }

        $scheme = strtolower($match[1]);

        if (!isset(self::DEFAULT_PORTS[$scheme])) {
            // Opaque URLs (javascript:, mailto:) have no host.
            return ['protocol' => $scheme . ':', 'host' => '', 'href' => $scheme . ':' . $match[2]];
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure helper, unit-tested without WordPress.
        $parts = parse_url($url);

        if (!is_array($parts) || !isset($parts['host']) || $parts['host'] === '') {
            return null;
        }

        $host = strtolower($parts['host']);
        if (isset($parts['port']) && $parts['port'] !== self::DEFAULT_PORTS[$scheme]) {
            $host .= ':' . $parts['port'];
        }

        $auth = isset($parts['user']) ? $parts['user'] . (isset($parts['pass']) ? ':' . $parts['pass'] : '') . '@' : '';
        $href = $scheme . '://' . $auth . $host . ($parts['path'] ?? '/')
            . (isset($parts['query']) ? '?' . $parts['query'] : '')
            . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');

        return ['protocol' => $scheme . ':', 'host' => $host, 'href' => $href];
    }

    /**
     * $base with these query parameters set (URLSearchParams.set(), form-encoded).
     *
     * @param array<string, string> $params
     */
    public static function withQuery(string $base, array $params): string
    {
        $parsed = self::parse($base);
        $href   = $parsed === null ? $base : $parsed['href'];

        return $href . (str_contains($href, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC1738);
    }

    /**
     * The approval page to show the user. The route's `approval_url` is used
     * only when it points at the site's own host; otherwise the link is
     * built from the site URL.
     */
    public static function approvalLink(string $siteUrl, ?string $given, int $approvalId): string
    {
        $site = self::parse($siteUrl);
        $link = $given === null ? null : self::parse($given);

        if ($site !== null && $link !== null && $link['host'] === $site['host'] && ($link['protocol'] === 'https:' || $link['protocol'] === $site['protocol'])) {
            return $link['href'];
        }

        return self::withQuery($siteUrl . '/wp-admin/admin.php', ['page' => 'mcpmate', 'approval' => (string) $approvalId]);
    }

    public static function pendingNextStep(int $approvalId): string
    {
        return 'Show the approval link to the user and ask a site admin to approve or reject it in wp-admin. '
            . 'Wait until the user says they decided, then call check_approval with approval_id ' . $approvalId . '. '
            . 'Do not repeat the original request, and do not poll in a loop.';
    }
}
