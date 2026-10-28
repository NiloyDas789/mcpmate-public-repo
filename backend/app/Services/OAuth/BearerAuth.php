<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Connection\Grants;

/**
 * The access token check for the MCP endpoint R (docs/mcp-in-plugin.md
 * §4.5): one indexed read, hash → row; not expired, not revoked, issued for
 * R, its grant active and the grant's user still there. The grant's
 * last_used_at moves at most once a minute.
 *
 * A bearer token sets a user only on the MCP route (security rule 9); this
 * class only answers whether a token is good and for whom.
 */
final class BearerAuth
{
    public const INVALID_TOKEN = 'invalid_token';

    /**
     * @return array{ok: true, grant: array<string, mixed>, user_id: int, scopes: string[]}|array{ok: false, error: string}
     */
    public static function authenticate(string $authorization): array
    {
        if (!preg_match('/^Bearer\s+(' . preg_quote(Tokens::ACCESS_PREFIX, '/') . '[A-Za-z0-9_-]{43})\s*$/Di', $authorization, $match)) {
            return ['ok' => false, 'error' => self::INVALID_TOKEN];
        }

        $row = Tokens::find($match[1], Tokens::KIND_ACCESS);

        if ($row === null || $row['revoked_at'] !== null || Tokens::isExpired($row) || !OAuthUrls::isResource((string) $row['resource'])) {
            return ['ok' => false, 'error' => self::INVALID_TOKEN];
        }

        $grant = Grants::find((string) $row['grant_id']);

        if ($grant === null || $grant['status'] !== Grants::STATUS_ACTIVE || get_userdata((int) $grant['wp_user_id']) === false) {
            return ['ok' => false, 'error' => self::INVALID_TOKEN];
        }

        Grants::markUsed((string) $grant['grant_id']);

        return ['ok' => true, 'grant' => $grant, 'user_id' => (int) $grant['wp_user_id'], 'scopes' => Tokens::scopes($row)];
    }

    /**
     * The `WWW-Authenticate` value of a 401 from R (§4.2): `error` only
     * when a token was sent (RFC 6750 §3.1).
     */
    public static function challenge(bool $tokenSent): string
    {
        $challenge = 'Bearer resource_metadata="' . OAuthUrls::endpoint('resource-metadata') . '", scope="' . implode(' ', OAuthUrls::SCOPES) . '"';

        return $tokenSent ? $challenge . ', error="' . self::INVALID_TOKEN . '"' : $challenge;
    }

    /**
     * The request's Authorization header, wherever the web server put it
     * (§10.1: CGI and FastCGI setups move or drop it). Never from the
     * query string.
     */
    public static function authorizationHeader(): string
    {
        foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- A credential, compared byte for byte against strict patterns.
                return trim((string) wp_unslash($_SERVER[$key]));
            }
        }

        if (function_exists('getallheaders')) {
            foreach ((array) getallheaders() as $name => $value) {
                if (is_string($name) && strcasecmp($name, 'Authorization') === 0 && is_string($value)) {
                    return trim($value);
                }
            }
        }

        return '';
    }
}
