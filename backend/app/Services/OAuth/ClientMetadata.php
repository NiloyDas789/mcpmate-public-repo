<?php

namespace McpMate\Services\OAuth;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Media\MediaException;
use McpMate\Services\Media\MediaFetcher;
use McpMate\Services\Media\RemoteUrl;

/**
 * Client ID Metadata Documents (docs/mcp-in-plugin.md §4.3): a `client_id`
 * that is an `https://` URL with a path names a JSON document the client
 * publishes about itself.
 *
 * The document is fetched through the media SSRF guard (RemoteUrl + the
 * pinned connection of MediaFetcher): HTTPS, ports 80/443, every resolved
 * address public, no redirects, TIMEOUT seconds, MAX_BYTES. Its
 * `client_id` must equal the URL, and its redirect URIs follow the same
 * rules as registered ones. Clients::resolve() caches it for its
 * Cache-Control max-age, kept between MIN_CACHE and MAX_CACHE.
 */
final class ClientMetadata
{
    public const MAX_BYTES = 5120;

    public const TIMEOUT = 5;

    public const MIN_CACHE = 300;

    public const MAX_CACHE = DAY_IN_SECONDS;

    /** @var callable|null fn(string $url): array{status: int, content_type: string, cache_control: string, body: string} */
    private static $fetcher = null;

    /**
     * Whether $clientId names a metadata document: an https URL with a
     * path, without credentials or a fragment.
     */
    public static function isDocumentUrl(string $clientId): bool
    {
        if (strlen($clientId) > RedirectUris::MAX_LENGTH || preg_match('/[\x00-\x20\x7F]/', $clientId)) {
            return false;
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Checked with the same parser RemoteUrl uses.
        $parts = parse_url($clientId);

        return is_array($parts)
            && strtolower($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/'
            && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass']);
    }

    /**
     * Fetch and check the document at $url.
     *
     * @return array{metadata: array{client_id: string, client_name: string, client_uri: string, redirect_uris: string[]}, cache_seconds: int, error: string}
     */
    public static function fetch(string $url): array
    {
        $failed = static fn (string $error): array => ['metadata' => ['client_id' => $url, 'client_name' => '', 'client_uri' => '', 'redirect_uris' => []], 'cache_seconds' => 0, 'error' => $error];
        $answer = self::$fetcher !== null ? (self::$fetcher)($url) : self::download($url);

        if (is_string($answer)) {
            return $failed($answer);
        }

        if ($answer['status'] !== 200) {
            /* translators: %d: HTTP status code. */
            return $failed(sprintf(__('The AI app\'s details could not be read (HTTP %d).', 'mcpmate'), $answer['status']));
        }

        if (stripos($answer['content_type'], 'application/json') !== 0 || strlen($answer['body']) > self::MAX_BYTES) {
            return $failed(__('The AI app\'s details are not a JSON document of at most 5 KB.', 'mcpmate'));
        }

        $checked = self::validate($url, json_decode($answer['body'], true));

        return $checked['error'] !== ''
            ? $failed($checked['error'])
            : ['metadata' => $checked['metadata'], 'cache_seconds' => self::cacheSeconds($answer['cache_control']), 'error' => ''];
    }

    /**
     * Check a decoded document against its URL.
     *
     * @param mixed $document
     * @return array{metadata: array{client_id: string, client_name: string, client_uri: string, redirect_uris: string[]}, error: string}
     */
    public static function validate(string $url, $document): array
    {
        $metadata = ['client_id' => $url, 'client_name' => '', 'client_uri' => '', 'redirect_uris' => []];
        $fail     = static fn (string $error): array => ['metadata' => $metadata, 'error' => $error];

        if (!is_array($document) || ($document['client_id'] ?? null) !== $url) {
            return $fail(__('The AI app\'s details do not name this client ID.', 'mcpmate'));
        }

        // A client that prefers another method may also list `none` among the
        // ones it supports (ChatGPT prefers private_key_jwt); our metadata
        // offers no key-based method, so it authenticates as a public client.
        $method    = $document['token_endpoint_auth_method'] ?? Clients::AUTH_NONE;
        $supported = $document['token_endpoint_auth_methods_supported'] ?? [];
        if ($method !== Clients::AUTH_NONE && !(is_array($supported) && in_array(Clients::AUTH_NONE, $supported, true))) {
            return $fail(__('Only public clients (token_endpoint_auth_method "none") can use a client ID metadata document here.', 'mcpmate'));
        }

        $uris = $document['redirect_uris'] ?? null;
        if (!is_array($uris) || $uris === [] || count($uris) > RedirectUris::MAX) {
            return $fail(__('The AI app\'s details list no redirect URIs.', 'mcpmate'));
        }

        foreach ($uris as $uri) {
            $problem = is_string($uri) ? RedirectUris::problem($uri) : 'redirect_uris must be absolute URLs';

            if ($problem !== null) {
                return $fail($problem);
            }
        }

        $metadata['redirect_uris'] = array_values($uris);
        $metadata['client_name']   = self::name($document['client_name'] ?? null, (string) wp_parse_url($url, PHP_URL_HOST));
        $metadata['client_uri']    = isset($document['client_uri']) && is_string($document['client_uri']) && strpos($document['client_uri'], 'https://') === 0
            ? substr($document['client_uri'], 0, RedirectUris::MAX_LENGTH)
            : '';

        return ['metadata' => $metadata, 'error' => ''];
    }

    /**
     * Seconds to cache a document: its `max-age`, kept between MIN_CACHE
     * and MAX_CACHE; MIN_CACHE without one or with no-store/no-cache.
     */
    public static function cacheSeconds(string $cacheControl): int
    {
        if (preg_match('/(?:^|,)\s*(?:no-store|no-cache)\s*(?:,|$)/i', $cacheControl) || !preg_match('/max-age\s*=\s*(\d+)/i', $cacheControl, $match)) {
            return self::MIN_CACHE;
        }

        return max(self::MIN_CACHE, min(self::MAX_CACHE, (int) $match[1]));
    }

    /**
     * A display name: trimmed, without control characters, at most 100
     * characters; $fallback (the host) when empty.
     *
     * @param mixed $name
     */
    public static function name($name, string $fallback): string
    {
        $name = is_string($name) ? trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name)) : '';

        return $name !== '' ? mb_substr($name, 0, 100, 'UTF-8') : mb_substr($fallback, 0, 100, 'UTF-8');
    }

    /**
     * Tests only: answer document fetches with $fetcher instead of the network.
     *
     * @param callable|null $fetcher fn(string $url): array{status: int, content_type: string, cache_control: string, body: string}|string
     */
    public static function useFetcher(?callable $fetcher): void
    {
        self::$fetcher = $fetcher;
    }

    /**
     * @return array{status: int, content_type: string, cache_control: string, body: string}|string An error message on failure.
     */
    private static function download(string $url)
    {
        $target = RemoteUrl::resolve($url);

        if ($target['error'] !== '') {
            return __('The AI app\'s client ID is not a public HTTPS address.', 'mcpmate');
        }

        try {
            $response = MediaFetcher::pinnedGet($target, [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 0,
                'reject_unsafe_urls'  => true,
                'limit_response_size' => self::MAX_BYTES + 1,
                'cookies'             => [],
                'headers'             => ['Accept' => 'application/json'],
                'user-agent'          => 'McpMate/' . Config::VERSION . '; ' . home_url('/'),
            ]);
        } catch (MediaException $e) {
            return __('The AI app\'s details could not be fetched.', 'mcpmate');
        }

        return [
            'status'        => (int) wp_remote_retrieve_response_code($response),
            'content_type'  => (string) wp_remote_retrieve_header($response, 'content-type'),
            'cache_control' => (string) wp_remote_retrieve_header($response, 'cache-control'),
            'body'          => (string) wp_remote_retrieve_body($response),
        ];
    }
}
