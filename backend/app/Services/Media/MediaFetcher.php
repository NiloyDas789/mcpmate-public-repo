<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;

/**
 * Downloads one remote file for POST /media, without letting the URL reach
 * this server's own network (SSRF).
 *
 * - Every URL, including each redirect target, passes RemoteUrl first:
 *   http(s), ports 80/443, and only public addresses.
 * - The connection is pinned to the addresses RemoteUrl checked
 *   (CURLOPT_RESOLVE), so DNS cannot answer with a private address between
 *   the check and the connection. A request that did not go through cURL
 *   (where nothing can be pinned) is discarded.
 * - Redirects are followed by hand, at most MAX_REDIRECTS.
 * - wp_safe_remote_get() checks the address again, no cookies or
 *   credentials are sent, TLS certificates are verified, and the body is
 *   streamed to a file and capped at MediaUploader::MAX_BYTES.
 */
final class MediaFetcher
{
    public const TIMEOUT = 20;

    public const MAX_REDIRECTS = 3;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /** RemoteUrl error → response code. */
    private const URL_ERRORS = [
        RemoteUrl::ERROR_INVALID    => MediaException::INVALID_URL,
        RemoteUrl::ERROR_SCHEME     => MediaException::INVALID_URL,
        RemoteUrl::ERROR_PORT       => MediaException::URL_BLOCKED,
        RemoteUrl::ERROR_UNRESOLVED => MediaException::FETCH_FAILED,
        RemoteUrl::ERROR_BLOCKED    => MediaException::URL_BLOCKED,
    ];

    /**
     * Download $url into $destination (a temporary file the caller owns).
     *
     * @param callable|null $resolver Passed to RemoteUrl::resolve(); tests only.
     * @return array{content_type: string, bytes: int, redirects: int}
     * @throws MediaException
     */
    public static function download(string $url, string $destination, ?callable $resolver = null): array
    {
        $current = $url;

        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $target = RemoteUrl::resolve($current, $resolver);

            if ($target['error'] !== '') {
                throw self::urlError(esc_html($target['error']), (bool) $redirects);
            }

            $response = self::request($target, $destination);
            $status   = (int) wp_remote_retrieve_response_code($response);
            $location = (string) wp_remote_retrieve_header($response, 'location');

            if (in_array($status, self::REDIRECT_STATUSES, true) && $location !== '') {
                $current = \WP_Http::make_absolute_url($location, $target['url']);
                continue;
            }

            if ($status !== 200) {
                throw new MediaException(
                    esc_html__('The file could not be downloaded.', 'mcpmate'),
                    esc_html(MediaException::FETCH_FAILED),
                    502,
                    /* translators: %d: HTTP status code. */
                    sprintf(esc_html__('The URL answered HTTP %d. Check that it points directly at an image or PDF.', 'mcpmate'), (int) $status)
                );
            }

            clearstatcache(true, $destination);
            $bytes = (int) filesize($destination);

            if ($bytes > MediaUploader::MAX_BYTES) {
                throw MediaUploader::tooLarge();
            }

            return [
                'content_type' => (string) wp_remote_retrieve_header($response, 'content-type'),
                'bytes'        => $bytes,
                'redirects'    => $redirects,
            ];
        }

        throw new MediaException(
            esc_html__('The URL redirects too many times.', 'mcpmate'),
            esc_html(MediaException::FETCH_FAILED),
            502,
            esc_html__('Use the final address of the file.', 'mcpmate')
        );
    }

    /**
     * Arguments for wp_safe_remote_get(): no automatic redirects, anonymous,
     * streamed to $destination, capped one byte past the limit so an
     * oversized file is detected.
     *
     * @return array<string, mixed>
     */
    public static function requestArgs(string $destination): array
    {
        return [
            'timeout'             => self::TIMEOUT,
            'redirection'         => 0,
            'reject_unsafe_urls'  => true,
            'stream'              => true,
            'filename'            => $destination,
            'limit_response_size' => MediaUploader::MAX_BYTES + 1,
            'cookies'             => [],
            'headers'             => ['Accept' => 'image/*,application/pdf;q=0.9'],
            'user-agent'          => 'McpMate/' . Config::VERSION . '; ' . home_url('/'),
        ];
    }

    /**
     * @param array{url: string, host: string, port: int, addresses: string[], error: string} $target
     * @return array<string, mixed> The wp_remote_* response.
     */
    private static function request(array $target, string $destination): array
    {
        return self::pinnedGet($target, self::requestArgs($destination));
    }

    /**
     * GET $target's URL with the connection pinned to the addresses
     * RemoteUrl checked. Also used for client metadata documents (OAuth).
     *
     * @param array{url: string, host: string, port: int, addresses: string[], error: string} $target A RemoteUrl::resolve() result without error.
     * @param array<string, mixed>                                                               $args   wp_safe_remote_get() arguments.
     * @return array<string, mixed> The wp_remote_* response.
     * @throws MediaException When the request failed, or did not go through cURL.
     */
    public static function pinnedGet(array $target, array $args): array
    {
        $pinned = false;
        $pin    = static function ($handle) use ($target, &$pinned): void {
            // phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Pins the connection to the checked address; WP_Http has no option for it.
            curl_setopt($handle, CURLOPT_RESOLVE, [self::resolveEntry($target)]);
            curl_setopt($handle, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            // phpcs:enable
            $pinned = true;
        };

        // Core hook, fired by WP_Http's cURL transport just before sending.
        add_action('http_api_curl', $pin, PHP_INT_MAX, 1);

        try {
            $response = wp_safe_remote_get($target['url'], $args);
        } finally {
            remove_action('http_api_curl', $pin, PHP_INT_MAX);
        }

        if (is_wp_error($response)) {
            // The message can name resolved addresses or TLS details; it stays out of the response.
            throw new MediaException(
                esc_html__('The file could not be downloaded.', 'mcpmate'),
                esc_html(MediaException::FETCH_FAILED),
                502,
                esc_html__('Check that the URL is public and reachable, and points directly at an image or PDF.', 'mcpmate')
            );
        }

        if (!$pinned) {
            throw new MediaException(
                esc_html__('Remote downloads need the PHP cURL extension on this site.', 'mcpmate'),
                esc_html(MediaException::FETCH_UNAVAILABLE),
                501,
                esc_html__('Send the file as base64 "data" instead of a URL.', 'mcpmate')
            );
        }

        return $response;
    }

    /**
     * CURLOPT_RESOLVE entry `host:port:address`, preferring IPv4 (core's
     * own URL check only resolves IPv4).
     *
     * @param array{host: string, port: int, addresses: string[]} $target
     */
    private static function resolveEntry(array $target): string
    {
        $address = $target['addresses'][0];

        foreach ($target['addresses'] as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $address = $candidate;
                break;
            }
        }

        $host = strpos($target['host'], ':') !== false ? '[' . $target['host'] . ']' : $target['host'];

        return $host . ':' . $target['port'] . ':' . (strpos($address, ':') !== false ? '[' . $address . ']' : $address);
    }

    private static function urlError(string $error, bool $afterRedirect): MediaException
    {
        $code = self::URL_ERRORS[$error] ?? MediaException::INVALID_URL;

        if ($error === RemoteUrl::ERROR_BLOCKED || $error === RemoteUrl::ERROR_PORT) {
            return new MediaException(
                $afterRedirect
                    ? esc_html__('The URL redirects to an address that is not allowed.', 'mcpmate')
                    : esc_html__('That address is not allowed.', 'mcpmate'),
                esc_html($code),
                400,
                esc_html__('Only public http(s) URLs on ports 80 and 443 can be downloaded. Private, local and internal network addresses are blocked.', 'mcpmate')
            );
        }

        if ($error === RemoteUrl::ERROR_UNRESOLVED) {
            return new MediaException(
                esc_html__('The URL\'s host name does not resolve.', 'mcpmate'),
                esc_html($code),
                400,
                esc_html__('Check the URL.', 'mcpmate')
            );
        }

        return new MediaException(
            esc_html__('The URL is not valid.', 'mcpmate'),
            esc_html($code),
            400,
            esc_html__('Use an absolute http(s) URL with a public host name, without spaces or credentials.', 'mcpmate')
        );
    }
}
