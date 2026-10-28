<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * `GET I/authorize` (docs/mcp-in-plugin.md §4.4).
 *
 * Until the client and its redirect URI are known to be good, errors are an
 * error page and never a redirect (no open redirect). After that, errors go
 * back to the client as `redirect_uri?error=…&state=…&iss=I`. A valid
 * request is stored (AuthorizationRequests) and the browser is sent to the
 * `#/connect` screen in wp-admin, where a site admin approves or denies it
 * (OAuthConsent); wp-login.php comes first when needed.
 *
 * Nothing here creates a grant or a code: only the admin's decision does.
 */
final class Authorize
{
    public const MAX_STATE = 512;

    public static function handle(OAuthRequest $request): OAuthResponse
    {
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            return OAuthResponse::page(405, 'invalid_request', __('Open this address in a browser.', 'mcpmate'));
        }

        $clientId = $request->queryValue('client_id');

        if ($clientId === '') {
            return OAuthResponse::page(400, 'invalid_request', __('The request does not name an AI app (client_id).', 'mcpmate'));
        }

        $resolved = Clients::resolve($clientId);
        $client   = $resolved['client'];

        if ($client === null) {
            return OAuthResponse::page(400, 'invalid_client', $resolved['error']);
        }

        $requested = $request->queryValue('redirect_uri');

        if ($requested === '' && count($client['redirect_uris']) === 1) {
            $requested = $client['redirect_uris'][0];
        }

        $redirectUri = RedirectUris::match($requested, $client['redirect_uris']);

        if ($redirectUri === null) {
            return OAuthResponse::page(400, 'invalid_request', __('The redirect_uri is not one this AI app registered.', 'mcpmate'));
        }

        // From here on the client is told what went wrong, at its own address.
        $state = $request->queryValue('state');
        $error = static fn (string $code, string $description): OAuthResponse => OAuthResponse::redirect(
            RedirectUris::withQuery($redirectUri, ['error' => $code, 'error_description' => $description, 'state' => $state, 'iss' => OAuthUrls::issuer()])
        );

        if (strlen($state) > self::MAX_STATE) {
            return $error('invalid_request', 'state is too long');
        }

        if ($request->queryValue('response_type') !== 'code') {
            return $error('unsupported_response_type', 'response_type must be code');
        }

        $challenge = $request->queryValue('code_challenge');

        if (!Pkce::isChallenge($challenge) || $request->queryValue('code_challenge_method') !== Pkce::METHOD) {
            return $error('invalid_request', 'PKCE is required: code_challenge with code_challenge_method S256');
        }

        if (!OAuthUrls::isResource($request->queryValue('resource'))) {
            return $error('invalid_target', 'resource must be ' . OAuthUrls::resource());
        }

        $scopes = self::scopes($request->queryValue('scope'));

        if ($scopes === null) {
            return $error('invalid_scope', 'scope must be site:read, site:write or both');
        }

        $id = AuthorizationRequests::store((string) $client['client_key'], $redirectUri, $state, $challenge, $scopes, OAuthUrls::resource());

        if ($id === null) {
            return $error('server_error', 'The request could not be saved.');
        }

        return OAuthResponse::redirect(admin_url('admin.php?page=' . Config::SLUG . '&connect=' . $id));
    }

    /**
     * The requested scopes, both when none are named; `offline_access` is
     * accepted and ignored, and making changes includes reading (as every
     * grant's access level does). Null when one is unknown.
     *
     * @return string[]|null
     */
    public static function scopes(string $scope): ?array
    {
        $requested = array_values(array_diff(array_filter(explode(' ', $scope)), [OAuthUrls::SCOPE_OFFLINE]));

        if ($requested === []) {
            return OAuthUrls::SCOPES;
        }

        if (array_diff($requested, OAuthUrls::SCOPES) !== []) {
            return null;
        }

        return in_array(OAuthUrls::SCOPE_WRITE, $requested, true) ? OAuthUrls::SCOPES : [OAuthUrls::SCOPE_READ];
    }
}
