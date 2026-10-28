<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Settings\Preferences;

/**
 * The discovery documents (docs/mcp-in-plugin.md §4.2): Protected Resource
 * Metadata (RFC 9728) for R, and Authorization Server Metadata (RFC 8414)
 * for I. The same AS fields are also served at the OIDC discovery paths,
 * for clients that try those (no ID tokens are issued: Q5).
 */
final class Discovery
{
    /**
     * @return array<string, mixed>
     */
    public static function protectedResource(): array
    {
        return [
            'resource'                 => OAuthUrls::resource(),
            'authorization_servers'    => [OAuthUrls::issuer()],
            'scopes_supported'         => OAuthUrls::SCOPES,
            'bearer_methods_supported' => ['header'],
            'resource_name'            => 'McpMate — ' . wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function authorizationServer(): array
    {
        $metadata = [
            'issuer'                                         => OAuthUrls::issuer(),
            'authorization_endpoint'                         => OAuthUrls::endpoint('authorize'),
            'token_endpoint'                                 => OAuthUrls::endpoint('token'),
            'revocation_endpoint'                            => OAuthUrls::endpoint('revoke'),
            'registration_endpoint'                          => OAuthUrls::endpoint('register'),
            'response_types_supported'                       => ['code'],
            'grant_types_supported'                          => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported'               => [Pkce::METHOD],
            'token_endpoint_auth_methods_supported'          => Clients::AUTH_METHODS,
            'revocation_endpoint_auth_methods_supported'     => Clients::AUTH_METHODS,
            'scopes_supported'                               => OAuthUrls::SCOPES,
            'client_id_metadata_document_supported'          => true,
            'authorization_response_iss_parameter_supported' => true,
        ];

        if (!Preferences::openRegistration()) {
            unset($metadata['registration_endpoint']);
        }

        return $metadata;
    }
}
