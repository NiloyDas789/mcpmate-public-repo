<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Media\RemoteUrl;
use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\Stock\StockPhotoException;
use McpMate\Services\Mcp\Stock\Unsplash;
use McpMate\Services\Mcp\ToolResult;

/**
 * upload_media → POST media; with stock_photo_id, the Unsplash lookup
 * first and the download ping after (cloud uploadMedia.ts, §7.3). A failed
 * ping never undoes the upload.
 */
final class UploadMediaTool extends Tool
{
    /** WordPress registers about ten sizes; themes and plugins can add many more. */
    private const MAX_SIZES = 20;

    public function run(\stdClass $args): ToolResult
    {
        $sources = count(array_filter([$args->url ?? null, $args->data ?? null, $args->stock_photo_id ?? null], static fn ($source): bool => $source !== null));

        if ($sources !== 1) {
            return ToolResult::invalidInput('Give exactly one of stock_photo_id, url or data.');
        }

        if (($args->featured || $args->insert) && !isset($args->post_id)) {
            return ToolResult::invalidInput('featured and insert need a post_id.', 'Pass the id of a draft or pending post, or leave out featured and insert.');
        }

        if (isset($args->url)) {
            $problem = self::urlProblem($args->url);

            if ($problem !== null) {
                return ToolResult::invalidInput($problem, 'Use a public http(s) URL on port 80 or 443, without credentials. Private and local network addresses are never downloaded.');
            }
        }

        $stockClient = null;
        $stock       = null;

        if (isset($args->stock_photo_id)) {
            $stockClient = Unsplash::fromSettings();

            if ($stockClient === null) {
                return SearchStockPhotosTool::unavailable();
            }

            try {
                $stock = $stockClient->photo($args->stock_photo_id);
            } catch (StockPhotoException $e) {
                return SearchStockPhotosTool::stockError($e);
            }
        }

        $source = $stock !== null ? ['url' => $stock['downloadUrl']] : (isset($args->url) ? ['url' => $args->url] : ['data' => $args->data]);
        $title  = $args->title ?? ($stock === null ? null : C::clipBytes($stock['description'] !== '' ? $stock['description'] : $stock['attribution']['text'], 200));

        $reply = $this->client->uploadMedia($source + [
            'filename' => $args->filename ?? ($stock === null ? null : 'unsplash-' . $stock['id']),
            'title'    => $title,
            'alt'      => $args->alt ?? ($stock['alt'] ?? null),
            'caption'  => $args->caption ?? ($stock['attribution']['html'] ?? null),
            'post_id'  => $args->post_id ?? null,
            'featured' => $args->featured,
            'insert'   => $args->insert,
        ]);

        if ($stock !== null) {
            try {
                // Unsplash's guidelines: count the download once the photo is used.
                $stockClient->trackDownload($stock['id']);
            } catch (StockPhotoException $e) {
                // The upload stands; the ping is best effort.
                unset($e);
            }
        }

        return self::out(self::format($this->siteId, $reply['data'], $stock));
    }

    /**
     * Why POST /media would refuse this URL, when that is clear without DNS.
     * The route is the real check (it resolves the host and pins the connection).
     */
    public static function urlProblem(string $value): ?string
    {
        $parts = wp_parse_url($value);

        if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || ($parts['host'] ?? '') === '') {
            return 'The url must be an absolute http(s) URL.';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The url must not contain credentials.';
        }

        if (isset($parts['port']) && !in_array((int) $parts['port'], [80, 443], true)) {
            return 'The url must use port 80 or 443.';
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return RemoteUrl::isPublicAddress($host) ? null : 'The url points at a private or local network address.';
        }

        if (!str_contains($host, '.') || str_ends_with($host, '.localhost') || preg_match('/^[\d.]+$|^0x/i', $host)) {
            return 'The url must use a public host name.';
        }

        return null;
    }

    /**
     * @param array<string, mixed>      $media
     * @param array<string, mixed>|null $stock
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $media, ?array $stock): array
    {
        $sizes = array_map(
            static fn (array $entry): array => ['name' => C::oneLine($entry[0], 50), 'url' => $entry[1]['url'], 'width' => $entry[1]['width'], 'height' => $entry[1]['height']],
            array_slice(Js::entries($media['sizes']), 0, self::MAX_SIZES)
        );

        $structured = [
            'site_id'       => $siteId,
            'attachment_id' => $media['id'],
            'url'           => $media['url'],
            'mime_type'     => $media['mime_type'],
            'title'         => $media['title'],
            'alt'           => $media['alt'],
            'caption'       => $media['caption'],
            'width'         => $media['width'],
            'height'        => $media['height'],
            'filesize'      => $media['filesize'],
            'sizes'         => $sizes,
            'edit_url'      => $media['edit_url'],
            'svg_sanitized' => $media['svg_sanitized'],
            'post'          => $media['post'],
            'stock_photo'   => $stock === null ? null : ['stock_photo_id' => $stock['id'], 'attribution' => $stock['attribution']['text'], 'unsplash_url' => $stock['unsplashUrl']],
        ];

        $dimensions = $media['width'] !== null && $media['height'] !== null ? ', ' . $media['width'] . '×' . $media['height'] : '';
        $bytes      = $media['filesize'] === null ? '' : ', ' . max(1, (int) self::jsRound($media['filesize'] / 1024)) . ' KB';
        $details    = [
            'URL: ' . $media['url'],
            'Title: ' . C::oneLine($media['title'], 200),
            'Alt text: ' . ($media['alt'] === '' ? '(none)' : C::oneLine($media['alt'], 500)),
            'Caption: ' . ($media['caption'] === '' ? '(none)' : C::oneLine($media['caption'], 1000)),
        ];

        if ($sizes !== []) {
            $details[] = 'Sizes: ' . implode(', ', array_map(static fn (array $size): string => $size['name'] . ' ' . $size['width'] . '×' . $size['height'], $sizes));
        }

        if ($media['svg_sanitized'] !== null) {
            $details[] = 'SVG cleaned: ' . C::plural($media['svg_sanitized']['removed_nodes'], 'node') . ' and ' . C::plural($media['svg_sanitized']['removed_attributes'], 'attribute') . ' removed.';
        }

        $post = $media['post'];
        if ($post !== null) {
            $done      = implode('; ', array_filter([$post['featured'] ? 'set as featured image' : '', $post['inserted'] ? 'image block added at the end of the content' : '']));
            $details[] = 'Post #' . $post['id'] . ' (' . C::oneLine($post['status'], 20) . '): attached' . ($done === '' ? '' : '; ' . $done) . '. Edit: ' . $post['edit_url'];

            if ($post['error'] !== '') {
                $details[] = 'Not done: ' . C::oneLine($post['error'], 500);
            }
        }

        $lines = [
            'Uploaded attachment #' . $media['id'] . ' (' . C::oneLine($media['mime_type'], 100) . $dimensions . $bytes . ') to the Media Library.',
            C::siteData('uploaded media', implode("\n", $details)),
        ];

        if ($stock !== null) {
            $lines[] = 'Credit (keep it with the photo): ' . $stock['attribution']['text'] . ', ' . $stock['unsplashUrl'];
        }

        $next = $post !== null
            ? 'The post is still a draft. Tell the user, and ask them to review and publish it in wp-admin; do not upload the same file again.'
            : 'Done; do not upload the same file again. To place an image in a draft directly, give post_id with insert or featured on the upload.';

        return ['text' => C::finish($lines, $next), 'structured' => $structured];
    }

    /**
     * Math.round(): halves round up.
     */
    private static function jsRound(float $value): float
    {
        return floor($value + 0.5);
    }
}
