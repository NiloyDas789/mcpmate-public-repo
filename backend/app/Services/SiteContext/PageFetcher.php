<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Fetches a front-end page of this site the way an anonymous visitor sees it.
 *
 * The request carries no cookies and no Authorization header, so it is never
 * logged in. Redirects are followed by hand (at most MAX_REDIRECTS), and each
 * Location must pass PageUrl again; an off-site redirect is returned, not
 * followed. Nonces are redacted from the HTML, and scripts are removed unless
 * the caller keeps them.
 */
final class PageFetcher
{
    public const DEFAULT_MAX_BYTES = 100000;

    public const MIN_BYTES = 1024;

    /** Upper bound for max_bytes; the JSON-escaped HTML must still fit RestEnvelope::MAX_BYTES. */
    public const MAX_BYTES = 200000;

    /** Most bytes downloaded per response, before scripts are stripped (2 MiB). */
    public const FETCH_LIMIT = 2097152;

    public const MAX_REDIRECTS = 3;

    public const TIMEOUT = 15;

    public const REDACTED = '[redacted]';

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * @param string $url A URL already resolved by PageUrl::resolve().
     * @return array<string, mixed>|\WP_Error
     */
    public static function fetch(string $url, bool $stripScripts, int $maxBytes)
    {
        $home      = home_url('/');
        $current   = $url;
        $redirects = [];

        while (true) {
            $response = wp_remote_get($current, self::requestArgs($home));

            if (is_wp_error($response)) {
                return new \WP_Error(
                    'mcpmate_fetch_failed',
                    __('The page could not be fetched.', 'mcpmate'),
                    ['status' => 502, 'hint' => $response->get_error_message()]
                );
            }

            $status   = (int) wp_remote_retrieve_response_code($response);
            $location = (string) wp_remote_retrieve_header($response, 'location');

            if (!in_array($status, self::REDIRECT_STATUSES, true) || $location === '' || count($redirects) >= self::MAX_REDIRECTS) {
                break;
            }

            $next = PageUrl::resolve(\WP_Http::make_absolute_url($location, $current), $home);

            if ($next['error'] !== '') {
                break;
            }

            $redirects[] = ['url' => $current, 'status_code' => $status, 'location' => $next['url']];
            $current     = $next['url'];
        }

        $contentType = (string) wp_remote_retrieve_header($response, 'content-type');
        $body        = (string) wp_remote_retrieve_body($response);

        if ($body !== '' && $contentType !== '' && stripos($contentType, 'html') === false) {
            return new \WP_Error(
                'mcpmate_not_html',
                __('The URL did not return an HTML page.', 'mcpmate'),
                [
                    'status' => 422,
                    /* translators: %s: Content-Type header value, e.g. application/json. */
                    'hint'   => sprintf(__('The response Content-Type was "%s".', 'mcpmate'), sanitize_text_field($contentType)),
                ]
            );
        }

        $cleaned = self::clean($body, $stripScripts);
        $html    = self::cut($cleaned['html'], $maxBytes);

        return [
            'url'              => $url,
            'final_url'        => $current,
            'status_code'      => $status,
            'content_type'     => $contentType,
            'location'         => in_array($status, self::REDIRECT_STATUSES, true) ? $location : '',
            'redirects'        => $redirects,
            'html'             => $html,
            'bytes'            => strlen($cleaned['html']),
            'max_bytes'        => $maxBytes,
            'html_truncated'   => strlen($html) < strlen($cleaned['html']) || strlen($body) >= self::FETCH_LIMIT,
            'scripts_stripped' => $cleaned['scripts_stripped'],
            'nonces_redacted'  => $cleaned['nonces_redacted'],
        ];
    }

    /**
     * Arguments for wp_remote_get(): anonymous (no cookies, no auth), no
     * automatic redirects, unsafe URLs rejected, download size capped.
     *
     * @return array<string, mixed>
     */
    public static function requestArgs(string $home): array
    {
        return [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'limit_response_size' => self::FETCH_LIMIT,
            'cookies'             => [],
            'headers'             => ['Accept' => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.1'],
            'user-agent'          => 'McpMate/' . Config::VERSION . '; ' . $home,
            // Same default core uses for its own loopback requests (Site Health, cron).
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
            'sslverify'           => apply_filters('https_local_ssl_verify', false),
        ];
    }

    // ──────────────────────────────────────────────
    //  HTML clean-up
    // ──────────────────────────────────────────────

    /**
     * Remove <script> elements (when $stripScripts) and redact nonces.
     *
     * Nonces are redacted in form inputs named *nonce*, in `key: "value"` /
     * `key="value"` pairs whose key contains "nonce" (JSON, JS, data-*
     * attributes) and in `*nonce*=` query parameters.
     *
     * @return array{html: string, scripts_stripped: int, nonces_redacted: int}
     */
    public static function clean(string $html, bool $stripScripts): array
    {
        $scripts = 0;

        if ($stripScripts) {
            $html = (string) preg_replace('#<script\b[^>]*>.*?</script\s*>#is', '', $html, -1, $closed);
            // A script cut off by FETCH_LIMIT has no closing tag.
            $html    = (string) preg_replace('#<script\b.*$#is', '', $html, -1, $open);
            $scripts = $closed + $open;
        }

        $nonces = 0;

        $html = (string) preg_replace_callback(
            '#<input\b[^>]*>#i',
            static function (array $m) use (&$nonces): string {
                if (!preg_match('#\bname\s*=\s*["\']?[^"\'\s>]*nonce#i', $m[0])) {
                    return $m[0];
                }

                $input   = (string) preg_replace('#(\bvalue\s*=\s*)(["\'])[^"\']*\2#i', '$1$2' . self::REDACTED . '$2', $m[0], -1, $count);
                $nonces += $count;

                return $input;
            },
            $html
        );

        $html    = (string) preg_replace(
            '#([\w-]*nonce[\w-]*\\\\?["\']?\s*[:=]\s*\\\\?["\'])[A-Za-z0-9_-]{4,64}(?=\\\\?["\'])#i',
            '$1' . self::REDACTED,
            $html,
            -1,
            $pairs
        );
        $html    = (string) preg_replace(
            '#([?&](?:amp;|\#038;)?[\w-]*nonce[\w-]*=)[A-Za-z0-9]+#i',
            '$1' . self::REDACTED,
            $html,
            -1,
            $params
        );
        $nonces += $pairs + $params;

        return ['html' => $html, 'scripts_stripped' => $scripts, 'nonces_redacted' => $nonces];
    }

    /**
     * At most $maxBytes of $html, without splitting a UTF-8 character.
     */
    public static function cut(string $html, int $maxBytes): string
    {
        if (strlen($html) <= $maxBytes) {
            return $html;
        }

        // Drop the last (possibly partial) multi-byte character at the cut.
        return (string) preg_replace('/[\xC0-\xFF][\x80-\xBF]*$/', '', (string) substr($html, 0, $maxBytes));
    }
}
