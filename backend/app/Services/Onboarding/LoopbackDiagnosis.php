<?php

namespace McpMate\Services\Onboarding;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * What the MCP URL's answer to a request without a token says (pure;
 * docs/mcp-in-plugin.md §10, check 4).
 *
 * A healthy site answers 401 with the plugin's `WWW-Authenticate: Bearer
 * resource_metadata="…"`, which is what sends an AI app to sign in. Anything
 * else is named: a redirect, a password prompt, a firewall page, REST API
 * blocking, PHP output in the body, plain HTTP refused, a web server that
 * doesn't pass the address to WordPress.
 */
final class LoopbackDiagnosis
{
    public const OK = 'ok';

    public const UNREACHABLE = 'unreachable';

    public const REDIRECT = 'redirect';

    public const PASSWORD = 'password';

    public const CLOUDFLARE = 'cloudflare';

    public const PHP_OUTPUT = 'php_output';

    public const NO_ROUTE = 'no_route';

    /** A 401 or 403 that is not the plugin's: REST API blocking or a firewall. */
    public const BLOCKED = 'blocked';

    /** The plugin refused plain HTTP (rule 8). */
    public const INSECURE = 'insecure';

    public const NOT_WORDPRESS = 'not_wordpress';

    /** The web server's own "not found" page: its rewrites don't send the address to WordPress. */
    public const NOT_ROUTED = 'not_routed';

    /** Codes WordPress and REST-blocking plugins answer for anonymous REST requests. */
    private const REST_BLOCKED_CODES = ['rest_not_logged_in', 'rest_cannot_access', 'rest_forbidden', 'rest_login_required', 'rest_authentication_required'];

    /**
     * @param array{error: string, status: int, headers: array<string, string>, body: string} $response
     *        `error` is non-empty when the request failed (timeout, refused); header names lowercase.
     * @return array{status: string, key: string, location: string, http_status: int}
     */
    public static function diagnose(array $response): array
    {
        $status  = $response['status'];
        $headers = $response['headers'];

        if ($response['error'] !== '') {
            return self::verdict('warn', self::UNREACHABLE, $status);
        }

        if ($status >= 300 && $status < 400) {
            return self::verdict('warn', self::REDIRECT, $status, (string) ($headers['location'] ?? ''));
        }

        if ($status === 401 && self::isOurChallenge((string) ($headers['www-authenticate'] ?? ''))) {
            return self::decode($response['body']) !== null
                ? self::verdict('pass', self::OK, $status)
                : self::verdict('fail', self::PHP_OUTPUT, $status);
        }

        $json = self::decode($response['body']);

        if ($json === null) {
            $stripped = self::decodeAfterOutput($response['body']);

            if ($stripped !== null && self::code($stripped) !== '') {
                return self::verdict('fail', self::PHP_OUTPUT, $status);
            }

            if ($status === 401 && stripos((string) ($headers['www-authenticate'] ?? ''), 'basic') === 0) {
                return self::verdict('fail', self::PASSWORD, $status);
            }

            if (in_array($status, [403, 503], true) && self::isCloudflare($headers)) {
                return self::verdict('fail', self::CLOUDFLARE, $status);
            }

            if ($status === 404) {
                return self::verdict('fail', self::NOT_ROUTED, $status);
            }

            return self::verdict('fail', in_array($status, [401, 403, 406, 429], true) ? self::BLOCKED : self::NOT_WORDPRESS, $status);
        }

        $code = self::code($json);

        if ($code === 'insecure_transport') {
            return self::verdict('fail', self::INSECURE, $status);
        }

        if ($code === 'rest_no_route') {
            return self::verdict('fail', self::NO_ROUTE, $status);
        }

        if (in_array($code, self::REST_BLOCKED_CODES, true) || in_array($status, [401, 403], true)) {
            return self::verdict('fail', self::BLOCKED, $status);
        }

        return self::verdict('fail', self::NOT_WORDPRESS, $status);
    }

    /**
     * Whether Cloudflare is in front of the site (from a loopback's headers).
     *
     * @param array<string, string> $headers
     */
    public static function isCloudflare(array $headers): bool
    {
        return isset($headers['cf-ray']) || isset($headers['cf-mitigated']) || isset($headers['cf-cache-status'])
            || stripos((string) ($headers['server'] ?? ''), 'cloudflare') !== false;
    }

    /**
     * The plugin's 401 challenge (BearerAuth::challenge()).
     */
    public static function isOurChallenge(string $header): bool
    {
        return stripos($header, 'bearer') === 0 && stripos($header, 'resource_metadata="') !== false;
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @return array{status: string, key: string, location: string, http_status: int}
     */
    private static function verdict(string $status, string $key, int $httpStatus, string $location = ''): array
    {
        return ['status' => $status, 'key' => $key, 'location' => $location, 'http_status' => $httpStatus];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decode(string $body): ?array
    {
        $decoded = json_decode(trim($body), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The JSON object at the end of $body when something (PHP notices,
     * another plugin) printed before it.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeAfterOutput(string $body): ?array
    {
        $start = strpos($body, '{"');

        return $start === false || $start === 0 ? null : self::decode((string) substr($body, $start));
    }

    /**
     * The error code of an OAuth error (`error`), an envelope (`error.code`)
     * or a WP_Error body (`code`).
     *
     * @param array<string, mixed> $json
     */
    private static function code(array $json): string
    {
        if (isset($json['error']) && is_string($json['error'])) {
            return $json['error'];
        }

        if (isset($json['error']) && is_array($json['error']) && is_string($json['error']['code'] ?? null)) {
            return $json['error']['code'];
        }

        return is_string($json['code'] ?? null) ? $json['code'] : '';
    }
}
