<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * `POST I/revoke` (RFC 7009; docs/mcp-in-plugin.md §4.5). The client
 * authenticates as at the token endpoint and names one of its tokens: an
 * access token is revoked alone, a refresh token takes its whole family
 * (every refresh and access token of the grant) with it.
 *
 * The answer is 200 whether or not the token was known, valid or the
 * client's own, so it tells nobody anything about other tokens.
 */
final class Revocation
{
    public static function handle(OAuthRequest $request): OAuthResponse
    {
        if ($request->method !== 'POST') {
            return OAuthResponse::error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST, OPTIONS']);
        }

        $retry = RateLimit::hit('token', $request->ip, TokenEndpoint::RATE_LIMIT, MINUTE_IN_SECONDS);
        if ($retry > 0) {
            return OAuthResponse::error(429, 'too_many_requests', 'Too many requests from this address.', ['Retry-After' => (string) $retry]);
        }

        $auth = ClientAuth::authenticate($request);
        if ($auth['client'] === null) {
            return $auth['response'];
        }

        $token = $request->formValue('token');
        if ($token === '') {
            return OAuthResponse::error(400, 'invalid_request', 'token is required.');
        }

        // The hint only says where to look first; both kinds are checked.
        $kinds = $request->formValue('token_type_hint') === 'refresh_token'
            ? [Tokens::KIND_REFRESH, Tokens::KIND_ACCESS]
            : [Tokens::KIND_ACCESS, Tokens::KIND_REFRESH];

        foreach ($kinds as $kind) {
            $row = Tokens::find($token, $kind);

            if ($row === null) {
                continue;
            }

            if (hash_equals((string) $row['client_key'], (string) $auth['client']['client_key'])) {
                if ($kind === Tokens::KIND_REFRESH && (string) $row['family_id'] !== '') {
                    Tokens::revokeFamily((string) $row['family_id']);
                } else {
                    Tokens::revoke((string) $row['token_hash']);
                }
            }

            break;
        }

        return OAuthResponse::empty(200);
    }
}
