<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Connection\Grants;

/**
 * The site admin's decision on an authorization request: the `#/connect`
 * screen (docs/ui.md §5.6) with `?connect=<request id>`, and admin-ajax
 * `connection/approve {request, read_only}` / `connection/reject {request}`
 * (nonce + manage_options; docs/mcp-in-plugin.md §4.4).
 *
 * Approve uses the request once, creates the grant (pending until its code
 * is exchanged), issues the code and returns
 * `redirect_uri?code=…&state=…&iss=I`. Deny returns
 * `redirect_uri?error=access_denied&state=…&iss=I`. Both redirect only to
 * the URI the request was checked against at `/authorize`.
 *
 * The approval is never skipped and never remembered (security rule 9).
 */
final class OAuthConsent
{
    public const INVALID = 'mcpmate_approval_request_invalid';

    public const EXPIRED = 'mcpmate_approval_request_expired';

    public const USED = 'mcpmate_approval_request_used';

    public const GRANT_LIMIT = 'mcpmate_grant_limit';

    /**
     * Check a request without using it.
     *
     * @return array{ok: true, request: array<string, mixed>, client: array<string, mixed>}|array{ok: false, code: string, message: string}
     */
    public static function inspect(string $id): array
    {
        $request = AuthorizationRequests::find($id);

        if ($request === null) {
            return self::fail(self::INVALID);
        }

        if ($request['used_at'] !== null) {
            return self::fail(self::USED);
        }

        if (strtotime((string) $request['expires_at'] . ' UTC') <= time()) {
            return self::fail(self::EXPIRED);
        }

        $client = Clients::findByKey((string) $request['client_key']);

        if ($client === null || Clients::isRevoked($client)) {
            return self::fail(self::INVALID);
        }

        return ['ok' => true, 'request' => $request, 'client' => $client];
    }

    /**
     * @return array{redirect_url: string, grant: array<string, mixed>}|\WP_Error
     */
    public static function approve(string $id, int $userId, bool $readOnly)
    {
        $checked = self::inspect($id);

        if (!$checked['ok']) {
            return self::error($checked);
        }

        $request = $checked['request'];
        $client  = $checked['client'];
        $scopes  = array_values(array_filter(explode(' ', (string) $request['scopes'])));
        // Read only can only narrow what was asked for.
        $scopes = $readOnly ? array_values(array_intersect($scopes, [OAuthUrls::SCOPE_READ])) : $scopes;

        if ($scopes === []) {
            return self::error(self::fail(self::INVALID));
        }

        if (Grants::countLive() >= Grants::limit()) {
            return self::error(self::fail(self::GRANT_LIMIT));
        }

        if (!AuthorizationRequests::claim($id)) {
            return self::error(self::fail(self::USED));
        }

        $grant = Grants::create($userId, [
            'id'            => (string) $client['client_id'],
            'name'          => (string) $client['client_name'],
            'vouched_by'    => Clients::vouchedBy($client),
            'redirect_host' => RedirectUris::host((string) $request['redirect_uri']),
            'client_key'    => (string) $client['client_key'],
        ], $scopes);

        $code = $grant === null ? null : Tokens::issueCode(
            (string) $grant['grant_id'],
            (string) $client['client_key'],
            $scopes,
            (string) $request['resource'],
            (string) $request['code_challenge'],
            (string) $request['redirect_uri']
        );

        if ($grant === null || $code === null) {
            return new \WP_Error('mcpmate_grant_not_saved', __('The approval could not be saved.', 'mcpmate'), ['status' => 500]);
        }

        Clients::touch((string) $client['client_key']);
        self::audit('approve', (string) $grant['grant_id'], $client, $request, $scopes);

        return [
            'redirect_url' => RedirectUris::withQuery((string) $request['redirect_uri'], ['code' => $code, 'state' => (string) $request['state'], 'iss' => OAuthUrls::issuer()]),
            'grant'        => Grants::format($grant),
        ];
    }

    /**
     * @return array{redirect_url: string}|\WP_Error
     */
    public static function deny(string $id)
    {
        $checked = self::inspect($id);

        if (!$checked['ok']) {
            return self::error($checked);
        }

        if (!AuthorizationRequests::claim($id)) {
            return self::error(self::fail(self::USED));
        }

        $request = $checked['request'];

        self::audit('deny', '', $checked['client'], $request, []);

        return [
            'redirect_url' => RedirectUris::withQuery((string) $request['redirect_uri'], [
                'error'             => 'access_denied',
                'error_description' => 'The site admin denied the request.',
                'state'             => (string) $request['state'],
                'iss'               => OAuthUrls::issuer(),
            ]),
        ];
    }

    /**
     * What the approval screen shows about a checked request, and where
     * the browser may go afterwards.
     *
     * @param array{request: array<string, mixed>, client: array<string, mixed>} $checked
     * @return array<string, mixed>
     */
    public static function describe(array $checked): array
    {
        $request = $checked['request'];
        $client  = $checked['client'];
        $user    = wp_get_current_user();
        $host    = (string) wp_parse_url((string) $request['redirect_uri'], PHP_URL_HOST);

        return [
            'client'          => [
                'name'               => (string) $client['client_name'],
                'uri'                => (string) $client['client_uri'],
                'vouched_by'         => Clients::vouchedBy($client),
                'redirect_host'      => RedirectUris::host((string) $request['redirect_uri']),
                'localhost_redirect' => RedirectUris::isLoopback($host),
            ],
            'scopes'          => array_values(array_filter(explode(' ', (string) $request['scopes']))),
            'expires_at'      => str_replace(' ', 'T', (string) $request['expires_at']) . 'Z',
            'site'            => untrailingslashit(home_url()),
            'user'            => [
                'login'        => $user->user_login,
                'display_name' => $user->display_name,
                'roles'        => array_values($user->roles),
            ],
            'redirect_origin' => self::origin((string) $request['redirect_uri']),
        ];
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private static function origin(string $uri): string
    {
        $parts = wp_parse_url($uri);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        return strtolower($parts['scheme']) . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * @param array<string, mixed> $client
     * @param array<string, mixed> $request
     * @param string[]             $scopes
     */
    private static function audit(string $action, string $grantId, array $client, array $request, array $scopes): void
    {
        Logger::log([
            'tool'     => 'connection',
            'action'   => $action,
            'target'   => $grantId !== '' ? 'grant:' . $grantId : '',
            'args'     => [
                'client'        => (string) $client['client_name'],
                'vouched_by'    => Clients::vouchedBy($client),
                'redirect_host' => RedirectUris::host((string) $request['redirect_uri']),
                'scopes'        => $scopes,
            ],
            'result'   => Logger::RESULT_OK,
            'grant_id' => $grantId,
        ]);
    }

    /**
     * @return array{ok: false, code: string, message: string}
     */
    private static function fail(string $code): array
    {
        $messages = [
            self::INVALID     => __('This approval link is not valid for this site.', 'mcpmate'),
            self::EXPIRED     => __('This approval link expired. Start connecting from the AI app again.', 'mcpmate'),
            self::USED        => __('This approval link was already used. Start connecting from the AI app again.', 'mcpmate'),
            self::GRANT_LIMIT     => __('This site has reached its limit of connected AI apps. Revoke one under Connections first.', 'mcpmate'),
        ];

        return ['ok' => false, 'code' => $code, 'message' => $messages[$code] ?? $messages[self::INVALID]];
    }

    /**
     * @param array{ok: false, code: string, message: string} $failure
     */
    private static function error(array $failure): \WP_Error
    {
        $status = in_array($failure['code'], [self::USED, self::GRANT_LIMIT], true) ? 409 : 400;

        return new \WP_Error($failure['code'], $failure['message'], ['status' => $status]);
    }
}
