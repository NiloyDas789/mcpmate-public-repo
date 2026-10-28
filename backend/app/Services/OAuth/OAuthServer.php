<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Connection\Connection;

/**
 * Dispatches one request to an OAuth endpoint (OAuthRoutes names it) and
 * adds the headers every endpoint shares (docs/mcp-in-plugin.md §4.8):
 * no-cache everywhere, and CORS (`*`, never credentials) on everything but
 * `authorize`, which is a browser navigation.
 *
 * Plain HTTP is refused (rule 8), unless WP_ENVIRONMENT_TYPE is `local`
 * and the site's host is loopback or a local name.
 */
final class OAuthServer
{
    public const RESOURCE_METADATA = 'resource-metadata';

    public const SERVER_METADATA = 'authorization-server';

    public const AUTHORIZE = 'authorize';

    public const TOKEN = 'token';

    public const REGISTER = 'register';

    public const REVOKE = 'revoke';

    /** The site checks' Authorization header probe (Probe). */
    public const PROBE = 'probe';

    public const ENDPOINTS = [self::RESOURCE_METADATA, self::SERVER_METADATA, self::AUTHORIZE, self::TOKEN, self::REGISTER, self::REVOKE, self::PROBE];

    public static function handle(string $endpoint, OAuthRequest $request): OAuthResponse
    {
        if (!in_array($endpoint, self::ENDPOINTS, true)) {
            return OAuthResponse::error(404, 'not_found', 'No such endpoint.');
        }

        $browser = $endpoint === self::AUTHORIZE;

        if ($request->method === 'OPTIONS' && !$browser) {
            return OAuthResponse::empty(204)->withHeaders(OAuthResponse::CORS_HEADERS);
        }

        $response = self::transportAllowed($request) ? self::dispatch($endpoint, $request) : self::insecure($browser);

        return $browser ? $response : $response->withHeaders(OAuthResponse::CORS_HEADERS);
    }

    /**
     * HTTPS, or a local environment whose WordPress address is a local host.
     * The site's own host, not the public base: a tunnel
     * (MCPMATE_PUBLIC_URL) has a public name and reaches a local site
     * over plain HTTP.
     */
    public static function transportAllowed(OAuthRequest $request): bool
    {
        if ($request->secure) {
            return true;
        }

        return Connection::isLocal() && OAuthUrls::isLocalHost((string) wp_parse_url(home_url(), PHP_URL_HOST));
    }

    private static function dispatch(string $endpoint, OAuthRequest $request): OAuthResponse
    {
        switch ($endpoint) {
            case self::RESOURCE_METADATA:
                return self::document($request, Discovery::protectedResource());
            case self::SERVER_METADATA:
                return self::document($request, Discovery::authorizationServer());
            case self::AUTHORIZE:
                return Authorize::handle($request);
            case self::TOKEN:
                return TokenEndpoint::handle($request);
            case self::REGISTER:
                return ClientRegistration::handle($request);
            case self::PROBE:
                return Probe::handle($request);
            default:
                return Revocation::handle($request);
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private static function document(OAuthRequest $request, array $document): OAuthResponse
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return OAuthResponse::error(405, 'invalid_request', 'Use GET.', ['Allow' => 'GET, OPTIONS']);
        }

        return OAuthResponse::json(200, $document);
    }

    private static function insecure(bool $browser): OAuthResponse
    {
        $message = __('McpMate accepts connections only over HTTPS.', 'mcpmate');

        return $browser
            ? OAuthResponse::page(400, 'insecure_transport', $message)
            : OAuthResponse::error(400, 'insecure_transport', $message);
    }
}
