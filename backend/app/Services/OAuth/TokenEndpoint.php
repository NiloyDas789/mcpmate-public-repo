<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\AuditLog\Logger;
use McpMate\Services\Connection\Grants;
use McpMate\Services\Settings\Preferences;

/**
 * `POST I/token` (docs/mcp-in-plugin.md §4.4–4.5), form-encoded.
 *
 * authorization_code: the code is single use and bound to its client,
 * redirect URI, PKCE challenge (S256 only), resource and grant. A code
 * presented a second time revokes the grant and everything issued from it
 * (OAuth 2.1 §4.1.3). The grant becomes active.
 *
 * refresh_token: every refresh rotates the refresh token. A used one
 * presented again revokes the whole grant (a stolen copy, or a client bug)
 * and is audited. The refresh lifetime is absolute from the approval:
 * rotation never extends it.
 *
 * Lifetimes come from Settings (access_token_minutes, refresh_token_days).
 */
final class TokenEndpoint
{
    /** Token requests per IP per minute (T8). */
    public const RATE_LIMIT = 60;

    public static function handle(OAuthRequest $request): OAuthResponse
    {
        if ($request->method !== 'POST') {
            return OAuthResponse::error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST, OPTIONS']);
        }

        $retry = RateLimit::hit('token', $request->ip, self::RATE_LIMIT, MINUTE_IN_SECONDS);
        if ($retry > 0) {
            return OAuthResponse::error(429, 'too_many_requests', 'Too many token requests from this address.', ['Retry-After' => (string) $retry]);
        }

        $auth = ClientAuth::authenticate($request);
        if ($auth['client'] === null) {
            return $auth['response'];
        }

        switch ($request->formValue('grant_type')) {
            case 'authorization_code':
                return self::exchangeCode($request, $auth['client']);
            case 'refresh_token':
                return self::refresh($request, $auth['client']);
            case '':
                return OAuthResponse::error(400, 'invalid_request', 'grant_type is required.');
            default:
                return OAuthResponse::error(400, 'unsupported_grant_type', 'grant_type must be authorization_code or refresh_token.');
        }
    }

    // ──────────────────────────────────────────────
    //  authorization_code
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $client
     */
    private static function exchangeCode(OAuthRequest $request, array $client): OAuthResponse
    {
        $row = Tokens::find($request->formValue('code'), Tokens::KIND_CODE);

        if ($row === null || !hash_equals((string) $row['client_key'], (string) $client['client_key'])) {
            return self::invalidGrant('The code is not valid.');
        }

        if ($row['used_at'] !== null || $row['revoked_at'] !== null) {
            return self::reused($row, Grants::REASON_CODE_REUSE, 'code_reuse');
        }

        if (Tokens::isExpired($row)) {
            return self::invalidGrant('The code expired.');
        }

        if ($request->formValue('redirect_uri') !== (string) $row['redirect_uri']) {
            return self::invalidGrant('redirect_uri does not match the authorization request.');
        }

        if (!Pkce::verify($request->formValue('code_verifier'), (string) $row['code_challenge'])) {
            return self::invalidGrant('code_verifier does not match the code_challenge.');
        }

        if (!OAuthUrls::isResource($request->formValue('resource')) || !OAuthUrls::isResource((string) $row['resource'])) {
            return OAuthResponse::error(400, 'invalid_target', 'resource must be ' . OAuthUrls::resource());
        }

        if (!Tokens::claim((string) $row['token_hash'])) {
            // A parallel exchange of the same code won.
            return self::reused($row, Grants::REASON_CODE_REUSE, 'code_reuse');
        }

        $grant = self::liveGrant((string) $row['grant_id'], true);

        if ($grant === null) {
            return self::invalidGrant('The approval behind this code was revoked.');
        }

        Clients::touch((string) $client['client_key']);

        $approvedAt = strtotime((string) $grant['created_at'] . ' UTC');
        $expiresAt  = $approvedAt + (int) Preferences::get('refresh_token_days') * DAY_IN_SECONDS;

        return self::issue($grant, $client, Tokens::newFamily(), null, Tokens::scopes($row), $expiresAt);
    }

    // ──────────────────────────────────────────────
    //  refresh_token
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $client
     */
    private static function refresh(OAuthRequest $request, array $client): OAuthResponse
    {
        $row = Tokens::find($request->formValue('refresh_token'), Tokens::KIND_REFRESH);

        if ($row === null || !hash_equals((string) $row['client_key'], (string) $client['client_key'])) {
            return self::invalidGrant('The refresh token is not valid.');
        }

        if ($row['revoked_at'] !== null) {
            return self::invalidGrant('The refresh token was revoked.');
        }

        if ($row['used_at'] !== null) {
            return self::reused($row, Grants::REASON_REFRESH_REUSE, 'refresh_reuse');
        }

        if (Tokens::isExpired($row)) {
            return self::invalidGrant('The refresh token expired. Connect the AI app again.');
        }

        $resource = $request->formValue('resource');
        if ($resource !== '' && !OAuthUrls::isResource($resource)) {
            return OAuthResponse::error(400, 'invalid_target', 'resource must be ' . OAuthUrls::resource());
        }

        $grant = self::liveGrant((string) $row['grant_id'], false);

        if ($grant === null) {
            return self::invalidGrant('The AI app\'s access was revoked.');
        }

        // The grant's access level may have narrowed since: never more than it allows now.
        $scopes = array_values(array_intersect(Tokens::scopes($row), Grants::scopes($grant)));

        if ($request->formValue('scope') !== '') {
            $asked = array_values(array_filter(explode(' ', $request->formValue('scope'))));

            if (array_diff($asked, $scopes) !== []) {
                return OAuthResponse::error(400, 'invalid_scope', 'A refresh can only keep or narrow the scope.');
            }

            $scopes = array_values(array_intersect($scopes, $asked));
        }

        if ($scopes === []) {
            return OAuthResponse::error(400, 'invalid_scope', 'No scope is left to grant.');
        }

        if (!Tokens::claim((string) $row['token_hash'])) {
            // A parallel refresh with the same token won: the same rule as a replay.
            return self::reused($row, Grants::REASON_REFRESH_REUSE, 'refresh_reuse');
        }

        return self::issue($grant, $client, (string) $row['family_id'], (string) $row['token_hash'], $scopes, strtotime((string) $row['expires_at'] . ' UTC'));
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<string, mixed> $grant
     * @param array<string, mixed> $client
     * @param string[]             $scopes
     */
    private static function issue(array $grant, array $client, string $familyId, ?string $parentHash, array $scopes, int $refreshExpiresAt): OAuthResponse
    {
        if ($refreshExpiresAt <= time()) {
            return self::invalidGrant('The refresh lifetime of this approval is over. Connect the AI app again.');
        }

        $lifetime = (int) Preferences::get('access_token_minutes') * MINUTE_IN_SECONDS;
        $grantId  = (string) $grant['grant_id'];
        $resource = OAuthUrls::resource();
        $access   = Tokens::issueAccess($grantId, (string) $client['client_key'], $familyId, $scopes, $resource, time() + $lifetime);
        $refresh  = Tokens::issueRefresh($grantId, (string) $client['client_key'], $familyId, $parentHash, $scopes, $resource, $refreshExpiresAt);

        if ($access === null || $refresh === null) {
            return OAuthResponse::error(500, 'server_error', 'The tokens could not be saved.');
        }

        return OAuthResponse::json(200, [
            'access_token'  => $access,
            'token_type'    => 'Bearer',
            'expires_in'    => $lifetime,
            'refresh_token' => $refresh,
            'scope'         => implode(' ', $scopes),
        ]);
    }

    /**
     * The grant if it can still receive tokens: not revoked, its user
     * exists. With $activate, pending becomes active (the code exchange).
     *
     * @return array<string, mixed>|null
     */
    private static function liveGrant(string $grantId, bool $activate): ?array
    {
        if ($activate) {
            Grants::activate($grantId);
        }

        $grant = Grants::find($grantId);

        if ($grant === null || $grant['status'] !== Grants::STATUS_ACTIVE || get_userdata((int) $grant['wp_user_id']) === false) {
            return null;
        }

        return $grant;
    }

    /**
     * A single-use value came back: revoke the grant and every token from
     * it, audit, and refuse.
     *
     * @param array<string, mixed> $row
     */
    private static function reused(array $row, string $reason, string $action): OAuthResponse
    {
        $grantId = (string) $row['grant_id'];

        if (Grants::revoke($grantId, 0, $reason)) {
            Logger::log([
                'tool'     => 'connection',
                'action'   => $action,
                'target'   => 'grant:' . $grantId,
                'args'     => ['kind' => (string) $row['kind']],
                'result'   => Logger::RESULT_ERROR,
                'message'  => $action === 'code_reuse'
                    ? __('An authorization code was used twice. The AI app\'s access was revoked.', 'mcpmate')
                    : __('A refresh token was used twice. The AI app\'s access was revoked.', 'mcpmate'),
                'grant_id' => $grantId,
                'user_id'  => 0,
            ]);
        }

        Tokens::revokeGrant($grantId);

        return self::invalidGrant('This token was already used. The AI app\'s access was revoked; connect it again.');
    }

    private static function invalidGrant(string $description): OAuthResponse
    {
        return OAuthResponse::error(400, 'invalid_grant', $description);
    }
}
