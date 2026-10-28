<?php

namespace McpMate\Services\Mcp\Stock;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Media\MediaException;
use McpMate\Services\Media\MediaFetcher;
use McpMate\Services\Media\RemoteUrl;

/**
 * Stock photos from the Unsplash API with the site owner's own key (the
 * cloud's src/stock/unsplash.ts; docs/mcp-in-plugin.md §8).
 *
 * Requests go only to API_ORIGIN, through RemoteUrl and MediaFetcher's
 * pinned connection (public addresses, TLS verified, no redirects, 5 s,
 * 256 KiB). Answers are third-party content: every field is bounded, links
 * shown to the user are built here from ids and usernames, image URLs must
 * be free photos on images.unsplash.com over HTTPS, and the download ping is
 * built from the photo id on the API origin.
 *
 * Unsplash's API guidelines ask for three things, all handled here:
 * hotlinked image URLs, a call to the photo's download endpoint when a user
 * picks it (trackDownload()), and attribution naming the photographer and
 * Unsplash with utm_source / utm_medium links.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception messages become JSON tool output or admin-ajax JSON, never HTML.
final class Unsplash
{
    public const API_ORIGIN = 'https://api.unsplash.com';

    public const ORIENTATIONS = ['landscape', 'portrait', 'squarish'];

    public const PHOTO_ID = '/^[A-Za-z0-9_-]{1,64}$/D';

    public const DOWNLOAD_WIDTH = 2400;

    public const MAX_PER_PAGE = 30;

    private const IMAGE_HOST = 'images.unsplash.com';

    private const MAX_RESPONSE_BYTES = 262144;

    private const TIMEOUT = 5;

    /** @var callable|null fn(string $url, array $headers): array{status: int, body: string} — tests. */
    private static $transport = null;

    private string $accessKey;

    private string $appName;

    public function __construct(string $accessKey, string $appName)
    {
        $this->accessKey = $accessKey;
        $this->appName   = $appName;
    }

    /**
     * The client for the site owner's key, or null when none is set.
     */
    public static function fromSettings(): ?self
    {
        $key = StockPhotoSettings::accessKey();

        return $key === '' ? null : new self($key, StockPhotoSettings::appName());
    }

    /**
     * Replace the HTTP call (tests); null restores it.
     */
    public static function useTransport(?callable $transport): void
    {
        self::$transport = $transport;
    }

    /**
     * @return array{total: int, totalPages: int, results: array<int, array<string, mixed>>}
     * @throws StockPhotoException
     */
    public function search(string $query, int $page, int $perPage, ?string $orientation): array
    {
        $params = ['query' => $query, 'page' => (string) $page, 'per_page' => (string) min($perPage, self::MAX_PER_PAGE), 'content_filter' => 'high'];
        if ($orientation !== null) {
            $params['orientation'] = $orientation;
        }

        [$status, $body] = $this->request('/search/photos?' . http_build_query($params, '', '&', PHP_QUERY_RFC1738));

        if ($status !== 200) {
            throw self::failed($status);
        }

        $valid = is_array($body) && self::isCount($body['total'] ?? null) && self::isCount($body['total_pages'] ?? null)
            && is_array($body['results'] ?? null) && count($body['results']) <= self::MAX_PER_PAGE;

        if (!$valid) {
            throw new StockPhotoException(StockPhotoException::FAILED, 'Unsplash answered in an unexpected format.');
        }

        $results = [];
        foreach ($body['results'] as $item) {
            $photo = $this->photoFrom($item);

            if ($photo !== null) {
                $results[] = $photo;
            }
        }

        return ['total' => $body['total'], 'totalPages' => $body['total_pages'], 'results' => $results];
    }

    /**
     * One free photo by id.
     *
     * @return array<string, mixed>
     * @throws StockPhotoException
     */
    public function photo(string $id): array
    {
        $notFound = new StockPhotoException(StockPhotoException::NOT_FOUND, 'There is no free Unsplash photo with that id.', 'Use a stock_photo_id from search_stock_photos.');

        if (!preg_match(self::PHOTO_ID, $id)) {
            throw $notFound;
        }

        [$status, $body] = $this->request('/photos/' . rawurlencode($id));

        if ($status === 404) {
            throw $notFound;
        }

        if ($status !== 200) {
            throw self::failed($status);
        }

        $photo = is_array($body) && ($body['id'] ?? null) === $id ? $this->photoFrom($body) : null;

        if ($photo === null) {
            throw $notFound;
        }

        return $photo;
    }

    /**
     * Tell Unsplash the photo was used, as its guidelines require.
     *
     * @throws StockPhotoException
     */
    public function trackDownload(string $id): void
    {
        if (!preg_match(self::PHOTO_ID, $id)) {
            return;
        }

        // What links.download_location points at, built from the id so the request stays on the API origin.
        [$status] = $this->request('/photos/' . rawurlencode($id) . '/download');

        if ($status !== 200) {
            throw self::failed($status);
        }
    }

    // ──────────────────────────────────────────────
    //  HTTP
    // ──────────────────────────────────────────────

    /**
     * @return array{0: int, 1: mixed} Status and decoded body (null when it is not JSON).
     * @throws StockPhotoException
     */
    private function request(string $pathAndQuery): array
    {
        $url     = self::API_ORIGIN . $pathAndQuery;
        $headers = ['Authorization' => 'Client-ID ' . $this->accessKey, 'Accept-Version' => 'v1', 'Accept' => 'application/json'];

        if (self::$transport !== null) {
            $answer = (self::$transport)($url, $headers);
        } else {
            $answer = self::send($url, $headers);
        }

        return [(int) $answer['status'], json_decode((string) $answer['body'], true)];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, body: string}
     * @throws StockPhotoException
     */
    private static function send(string $url, array $headers): array
    {
        $unreachable = new StockPhotoException(StockPhotoException::FAILED, 'Unsplash could not be reached.', 'Try again in a minute.');
        $target      = RemoteUrl::resolve($url);

        if ($target['error'] !== '' || strpos($target['url'], self::API_ORIGIN . '/') !== 0) {
            throw $unreachable;
        }

        try {
            $response = MediaFetcher::pinnedGet($target, [
                'timeout'             => self::TIMEOUT,
                'redirection'         => 0,
                'sslverify'           => true,
                'limit_response_size' => self::MAX_RESPONSE_BYTES,
                'headers'             => $headers,
                'user-agent'          => 'McpMate',
            ]);
        } catch (MediaException $e) {
            throw $unreachable;
        }

        return ['status' => (int) wp_remote_retrieve_response_code($response), 'body' => (string) wp_remote_retrieve_body($response)];
    }

    private static function failed(int $status): StockPhotoException
    {
        $keyProblem = $status === 401 || $status === 403;

        return new StockPhotoException(
            $keyProblem ? StockPhotoException::UNAVAILABLE : StockPhotoException::FAILED,
            $keyProblem ? 'Stock photo search is not available right now.' : 'Unsplash answered HTTP ' . $status . '.',
            $status === 403 || $status === 429 ? 'The hourly Unsplash request limit may be used up. Try again later.' : null
        );
    }

    // ──────────────────────────────────────────────
    //  Photos
    // ──────────────────────────────────────────────

    /**
     * A free photo from an API payload, or null when it does not parse or
     * its images are not on images.unsplash.com over HTTPS.
     *
     * @param mixed $payload
     * @return array<string, mixed>|null
     */
    private function photoFrom($payload): ?array
    {
        if (!is_array($payload) || !is_string($payload['id'] ?? null) || !preg_match(self::PHOTO_ID, $payload['id'])) {
            return null;
        }

        $size = static fn ($value): bool => is_int($value) && $value > 0 && $value <= 1000000;
        $user = $payload['user'] ?? null;
        $urls = $payload['urls'] ?? null;

        if (
            !$size($payload['width'] ?? null) || !$size($payload['height'] ?? null)
            || !is_array($urls) || !is_array($user) || !is_string($user['username'] ?? null) || !preg_match('/^[A-Za-z0-9_.-]{1,64}$/D', $user['username'])
            || ($payload['premium'] ?? null) === true || ($payload['plus'] ?? null) === true
        ) {
            return null;
        }

        $raw     = self::imageUrl($urls['raw'] ?? null);
        $regular = self::imageUrl($urls['regular'] ?? null);
        $small   = self::imageUrl($urls['small'] ?? null);

        if ($raw === null || $regular === null || $small === null) {
            return null;
        }

        $color    = is_string($payload['color'] ?? null) && preg_match('/^#[0-9a-fA-F]{6}$/D', $payload['color']) ? $payload['color'] : '';
        $username = $user['username'];
        $name     = self::text($user['name'] ?? null, 100);
        $name     = $name === '' ? $username : $name;
        $utm      = 'utm_source=' . rawurlencode($this->appName) . '&utm_medium=referral';
        $profile  = 'https://unsplash.com/@' . rawurlencode($username) . '?' . $utm;
        $home     = 'https://unsplash.com/?' . $utm;
        $download = add_query_arg(['w' => (string) self::DOWNLOAD_WIDTH, 'fit' => 'max', 'fm' => 'jpg', 'q' => '80'], $raw);

        return [
            'id'           => $payload['id'],
            'description'  => self::text($payload['description'] ?? null, 300),
            'alt'          => self::text($payload['alt_description'] ?? null, 300),
            'width'        => $payload['width'],
            'height'       => $payload['height'],
            'color'        => $color,
            'previewUrl'   => $small,
            'imageUrl'     => $regular,
            'downloadUrl'  => $download,
            'photographer' => ['name' => $name, 'username' => $username, 'profileUrl' => $profile],
            'unsplashUrl'  => 'https://unsplash.com/photos/' . rawurlencode($payload['id']) . '?' . $utm,
            'attribution'  => [
                'text' => 'Photo by ' . $name . ' on Unsplash',
                'html' => 'Photo by <a href="' . esc_attr($profile) . '">' . esc_html($name) . '</a> on <a href="' . esc_attr($home) . '">Unsplash</a>',
            ],
        ];
    }

    /**
     * @param mixed $value
     */
    private static function imageUrl($value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }

        $parts = wp_parse_url($value);

        $ok = is_array($parts) && ($parts['scheme'] ?? '') === 'https' && ($parts['host'] ?? '') === self::IMAGE_HOST
            && !isset($parts['user']) && !isset($parts['port']);

        return $ok ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private static function text($value, int $max): string
    {
        $flat = is_string($value) ? trim((string) preg_replace('/\s+/u', ' ', $value)) : '';

        return mb_substr($flat, 0, $max, 'UTF-8');
    }

    /**
     * @param mixed $value
     */
    private static function isCount($value): bool
    {
        return is_int($value) && $value >= 0;
    }
}
// phpcs:enable
