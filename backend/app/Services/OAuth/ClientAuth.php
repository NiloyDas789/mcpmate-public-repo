<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Client authentication at the token and revocation endpoints (RFC 6749
 * §2.3, OAuth 2.1 §2.4): a public client names itself with `client_id`; a
 * confidential one also proves its secret, in the form
 * (client_secret_post) or in HTTP Basic (client_secret_basic), not both.
 *
 * Only stored clients authenticate here: a metadata document was fetched
 * and stored at `/authorize`, before any code existed.
 */
final class ClientAuth
{
    /**
     * @return array{client: array<string, mixed>|null, response: OAuthResponse|null} The client, or the 400/401 to answer.
     */
    public static function authenticate(OAuthRequest $request): array
    {
        $basic    = self::basic($request->header('authorization'));
        $formId   = $request->formValue('client_id');
        $formPass = $request->formValue('client_secret');

        if ($basic === false) {
            return self::refuse(self::invalidClient(true));
        }

        if ($basic !== null && $formPass !== '') {
            return self::refuse(OAuthResponse::error(400, 'invalid_request', 'Send the client secret once: in the form or in the Authorization header.'));
        }

        if ($basic !== null && $formId !== '' && $formId !== $basic[0]) {
            return self::refuse(OAuthResponse::error(400, 'invalid_request', 'The client_id in the form and in the Authorization header differ.'));
        }

        $clientId = $basic !== null ? $basic[0] : $formId;
        $secret   = $basic !== null ? $basic[1] : $formPass;

        if ($clientId === '') {
            return self::refuse(OAuthResponse::error(400, 'invalid_request', 'client_id is required.'));
        }

        $client = Clients::find($clientId);

        if ($client === null || Clients::isRevoked($client)) {
            return self::refuse(self::invalidClient($basic !== null));
        }

        $public = $client['auth_method'] === Clients::AUTH_NONE;

        if ($public ? $secret !== '' : !Clients::checkSecret($client, $secret)) {
            return self::refuse(self::invalidClient($basic !== null));
        }

        return ['client' => $client, 'response' => null];
    }

    /**
     * The id and secret from `Authorization: Basic …`, null without that
     * header, false when it is malformed.
     *
     * @return array{0: string, 1: string}|false|null
     */
    private static function basic(string $header)
    {
        if (stripos($header, 'basic ') !== 0) {
            return null;
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- HTTP Basic credentials (RFC 7617).
        $decoded = base64_decode(trim((string) substr($header, 6)), true);

        if (!is_string($decoded) || strpos($decoded, ':') === false) {
            return false;
        }

        [$id, $secret] = explode(':', $decoded, 2);

        // RFC 6749 §2.3.1: both are form-urlencoded before they are joined.
        return [urldecode($id), urldecode($secret)];
    }

    private static function invalidClient(bool $basic): OAuthResponse
    {
        return OAuthResponse::error(401, 'invalid_client', 'Client authentication failed.', $basic ? ['WWW-Authenticate' => 'Basic realm="McpMate"'] : []);
    }

    /**
     * @return array{client: null, response: OAuthResponse}
     */
    private static function refuse(OAuthResponse $response): array
    {
        return ['client' => null, 'response' => $response];
    }
}
