<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * `POST I/probe` (docs/mcp-in-plugin.md §6.1, §10.1): the site checks ask
 * themselves whether an `Authorization` header survives the web server.
 *
 * The check starts a probe (start()), sends the one-time value both as
 * `Authorization: Bearer <value>` and as the form field `probe`, and reads
 * back whether the header arrived. Only the value's SHA-256 is kept, for
 * 60 seconds; without a probe in progress, or with another value, the
 * endpoint is 404. The value is not a credential: it opens nothing.
 */
final class Probe
{
    /** Transient holding the SHA-256 of the value in progress. */
    public const TRANSIENT = 'auth_probe';

    public const PREFIX = 'mcpm_probe_';

    public const TTL = 60;

    public const RECEIVED = 'received';

    public const MISSING = 'missing';

    /** A header arrived, but not the one sent: something in front rewrote it. */
    public const CHANGED = 'changed';

    /**
     * A new one-time value (a second start() replaces the first).
     */
    public static function start(): string
    {
        $value = self::PREFIX . bin2hex(random_bytes(16));

        set_transient(Config::withPrefix(self::TRANSIENT), hash('sha256', $value), self::TTL);

        return $value;
    }

    public static function finish(): void
    {
        delete_transient(Config::withPrefix(self::TRANSIENT));
    }

    public static function handle(OAuthRequest $request): OAuthResponse
    {
        if ($request->method !== 'POST') {
            return OAuthResponse::error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST, OPTIONS']);
        }

        $value    = $request->formValue('probe');
        $expected = get_transient(Config::withPrefix(self::TRANSIENT));

        if (!is_string($expected) || $expected === '' || strpos($value, self::PREFIX) !== 0 || !hash_equals($expected, hash('sha256', $value))) {
            return OAuthResponse::error(404, 'not_found', 'No such endpoint.');
        }

        $header = $request->header('authorization');

        if ($header === '') {
            $authorization = self::MISSING;
        } else {
            $authorization = hash_equals('Bearer ' . $value, $header) ? self::RECEIVED : self::CHANGED;
        }

        return OAuthResponse::json(200, [
            'probe'         => Config::SLUG,
            'authorization' => $authorization,
        ]);
    }
}
