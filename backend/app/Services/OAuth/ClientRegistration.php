<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Settings\Preferences;

/**
 * Dynamic Client Registration, `POST I/register` (RFC 7591;
 * docs/mcp-in-plugin.md §4.3).
 *
 * Public clients (`token_endpoint_auth_method` none) and confidential ones
 * (client_secret_post / client_secret_basic: the secret is returned once
 * and stored as a SHA-256). Redirect URIs follow RedirectUris. Limited to
 * RATE_LIMIT registrations an hour per IP and Clients::MAX_DCR clients;
 * a client nobody approves is deleted after a day. A site admin can turn
 * self-registration off (Settings → Access and safety); clients with a
 * metadata document and clients already registered keep working.
 *
 * The approval screen marks every client registered here "not verified".
 */
final class ClientRegistration
{
    public const MAX_BODY_BYTES = 16384;

    public const RATE_LIMIT = 10;

    public const RATE_WINDOW = HOUR_IN_SECONDS;

    public const CLIENT_ID_PREFIX = 'mcpm_c_';

    public const SECRET_PREFIX = 'mcpm_cs_';

    private const GRANT_TYPES = ['authorization_code', 'refresh_token'];

    public static function handle(OAuthRequest $request): OAuthResponse
    {
        if ($request->method !== 'POST') {
            return OAuthResponse::error(405, 'invalid_request', 'Use POST.', ['Allow' => 'POST, OPTIONS']);
        }

        if (!Preferences::openRegistration()) {
            return OAuthResponse::error(
                403,
                'registration_disabled',
                'This site does not let AI apps register themselves. Use a client ID metadata document, or ask a site admin to allow registration.'
            );
        }

        $retry = RateLimit::hit('register', $request->ip, self::RATE_LIMIT, self::RATE_WINDOW);
        if ($retry > 0) {
            return OAuthResponse::error(429, 'too_many_requests', 'Too many registrations from this address. Try again later.', ['Retry-After' => (string) $retry]);
        }

        $metadata = strlen($request->body) <= self::MAX_BODY_BYTES ? $request->json() : null;
        if ($metadata === null) {
            return OAuthResponse::error(400, 'invalid_client_metadata', 'Send the client metadata as a JSON object.');
        }

        $checked = self::validate($metadata);
        if ($checked['error'] !== '') {
            return OAuthResponse::error(400, $checked['error'], $checked['description']);
        }

        if (Clients::countDcr() >= Clients::MAX_DCR) {
            return OAuthResponse::error(403, 'registration_limit_reached', 'This site has too many registered AI apps waiting. Try again tomorrow, or use a client ID metadata document.');
        }

        $client                = $checked['metadata'];
        $client['client_id']   = self::CLIENT_ID_PREFIX . Pkce::b64url(random_bytes(16));
        $secret                = $client['token_endpoint_auth_method'] === Clients::AUTH_NONE ? null : self::SECRET_PREFIX . Tokens::random();
        $stored                = Clients::createDcr($client, $secret);

        if ($stored === null) {
            return OAuthResponse::error(500, 'server_error', 'The registration could not be saved.');
        }

        $body = [
            'client_id'                  => $client['client_id'],
            'client_id_issued_at'        => strtotime((string) $stored['created_at'] . ' UTC'),
            'client_name'                => $client['client_name'],
            'redirect_uris'              => $client['redirect_uris'],
            'grant_types'                => $client['grant_types'],
            'response_types'             => ['code'],
            'token_endpoint_auth_method' => $client['token_endpoint_auth_method'],
        ];

        if ($client['client_uri'] !== '') {
            $body['client_uri'] = $client['client_uri'];
        }

        if ($secret !== null) {
            $body['client_secret']            = $secret;
            $body['client_secret_expires_at'] = 0;
        }

        return OAuthResponse::json(201, $body);
    }

    /**
     * Check registration metadata. Fields this server does not use are
     * ignored; the ones it uses must be valid.
     *
     * @param array<string, mixed> $metadata
     * @return array{
     *     metadata: array{client_id: string, client_name: string, client_uri: string, redirect_uris: string[], token_endpoint_auth_method: string, grant_types: string[]},
     *     error: string,
     *     description: string
     * }
     */
    public static function validate(array $metadata): array
    {
        $clean = [
            'client_id'                  => '',
            'client_name'                => '',
            'client_uri'                 => '',
            'redirect_uris'              => [],
            'token_endpoint_auth_method' => Clients::AUTH_BASIC,
            'grant_types'                => ['authorization_code'],
        ];
        $fail  = static fn (string $error, string $description): array => ['metadata' => $clean, 'error' => $error, 'description' => $description];

        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || array_values($uris) !== $uris || count($uris) > RedirectUris::MAX) {
            return $fail('invalid_redirect_uri', sprintf('redirect_uris must be a list of 1 to %d URLs', RedirectUris::MAX));
        }

        foreach ($uris as $uri) {
            $problem = is_string($uri) ? RedirectUris::problem($uri) : 'redirect_uris must be absolute URLs';

            if ($problem !== null) {
                return $fail('invalid_redirect_uri', $problem);
            }
        }

        $clean['redirect_uris'] = array_values(array_unique($uris));

        // RFC 7591 §2: client_secret_basic when not given.
        $method = $metadata['token_endpoint_auth_method'] ?? Clients::AUTH_BASIC;
        if (!is_string($method) || !in_array($method, Clients::AUTH_METHODS, true)) {
            return $fail('invalid_client_metadata', 'token_endpoint_auth_method must be none, client_secret_post or client_secret_basic');
        }

        $clean['token_endpoint_auth_method'] = $method;

        $grantTypes = $metadata['grant_types'] ?? ['authorization_code'];
        if (!is_array($grantTypes) || $grantTypes === [] || array_diff($grantTypes, self::GRANT_TYPES) !== [] || !in_array('authorization_code', $grantTypes, true)) {
            return $fail('invalid_client_metadata', 'grant_types must include authorization_code and may include refresh_token');
        }

        $clean['grant_types'] = array_values(array_unique($grantTypes));

        $responseTypes = $metadata['response_types'] ?? ['code'];
        if ($responseTypes !== ['code']) {
            return $fail('invalid_client_metadata', 'response_types must be ["code"]');
        }

        $name = $metadata['client_name'] ?? '';
        if (!is_string($name)) {
            return $fail('invalid_client_metadata', 'client_name must be a string');
        }

        $clean['client_name'] = ClientMetadata::name($name, RedirectUris::host($clean['redirect_uris'][0]));

        $uri = $metadata['client_uri'] ?? '';
        if (!is_string($uri) || ($uri !== '' && (strpos($uri, 'https://') !== 0 || strlen($uri) > RedirectUris::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $uri)))) {
            return $fail('invalid_client_metadata', 'client_uri must be an https URL');
        }

        $clean['client_uri'] = $uri;

        return ['metadata' => $clean, 'error' => '', 'description' => ''];
    }
}
