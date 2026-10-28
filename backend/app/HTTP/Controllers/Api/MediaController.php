<?php

namespace McpMate\HTTP\Controllers\Api;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\ErrorPresenter;
use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Services\Media\DraftPlacement;
use McpMate\Services\Media\MediaException;
use McpMate\Services\Media\MediaSettings;
use McpMate\Services\Media\MediaUploader;
use McpMate\Services\Media\RemoteUrl;

/**
 * POST /media { url | data, filename, title, alt, caption, post_id, featured, insert }
 * — add one image or PDF to the Media Library.
 *
 * Order of checks:
 *
 * 1. The route needs upload_files.
 * 2. A site admin must have switched media uploads on (MediaSettings) → 403.
 * 3. Exactly one of url / data → 400.
 * 4. With post_id: edit_post on it → 403; it must be a draft or pending
 *    post of an allowed type (DraftPlacement) → 400.
 * 5. url: downloaded with the SSRF checks of MediaFetcher (public http(s)
 *    addresses only, pinned, ≤ 10 MB). data: strict base64, ≤ 10 MB.
 * 6. The type is read from the file: JPEG, PNG, GIF, WebP, AVIF, PDF → else
 *    415. SVG needs manage_options → 403, and is sanitized before saving.
 * 7. media_handle_sideload() stores it, with title, alt text and caption.
 *    featured / insert then set the draft's featured image or append an
 *    image block; the post's status never changes.
 *
 * 201 with the attachment ID, URL and sizes. Audited (POST) with target
 * `attachment:<id>`; the audit row keeps neither the file data nor the URL's
 * query string.
 */
final class MediaController extends RestController
{
    protected const CAPABILITIES = [
        'upload' => 'upload_files',
    ];

    public function upload(Request $request): Response
    {
        if (!MediaSettings::isEnabled()) {
            return $this->error(
                __('Media uploads are not enabled on this site.', 'mcpmate'),
                403,
                'mcpmate_media_disabled',
                __('A site admin can enable them under McpMate → Tools → Core (Media).', 'mcpmate')
            );
        }

        if ($request->has('url') === $request->has('data')) {
            return $this->error(
                __('Pass either url or data.', 'mcpmate'),
                400,
                'mcpmate_invalid_source',
                __('Send exactly one of "url" (a public http(s) URL of the file) or "data" (the file as base64).', 'mcpmate')
            );
        }

        $featured = (bool) $request->get('featured', false);
        $insert   = (bool) $request->get('insert', false);
        $post     = null;

        if ($request->has('post_id')) {
            $post = get_post((int) $request->get('post_id'));

            if (!$post instanceof \WP_Post) {
                return $this->error(__('Post not found.', 'mcpmate'), 404, 'mcpmate_post_not_found');
            }

            $this->auditTarget('post', $post->ID);

            $denied = $this->authorize('edit_post', $post->ID);
            if ($denied) {
                return $denied;
            }
        } elseif ($featured || $insert) {
            return $this->error(
                __('featured and insert need a post_id.', 'mcpmate'),
                400,
                'mcpmate_invalid_target',
                __('Pass the ID of a draft or pending post, or leave out featured and insert.', 'mcpmate')
            );
        }

        $fields = [
            'title'   => sanitize_text_field((string) $request->get('title', '')),
            'alt'     => sanitize_text_field((string) $request->get('alt', '')),
            'caption' => trim(wp_kses((string) $request->get('caption', ''), MediaUploader::CAPTION_HTML)),
        ];
        $path   = '';

        try {
            if ($post !== null) {
                DraftPlacement::check($post, $featured, $insert);
            }

            $path = $request->has('url')
                ? MediaUploader::fromUrl((string) $request->get('url'))
                : MediaUploader::fromData((string) $request->get('data'));

            $file = MediaUploader::inspect($path);

            DraftPlacement::checkType($file['mime'], $featured, $insert);

            $id = MediaUploader::store($path, $file['mime'], $this->filename($request), $fields, $post !== null ? $post->ID : 0);
        } catch (MediaException $e) {
            return $this->error(ErrorPresenter::plain($e), $e->status(), $e->errorCode(), ErrorPresenter::text($e->hint()));
        } finally {
            MediaUploader::discard($path);
        }

        $this->auditTarget('attachment', $id);

        return $this->ok(
            MediaUploader::describe($id) + [
                'source'        => $request->has('url') ? 'url' : 'data',
                'svg_sanitized' => $file['svg'],
                'post'          => $post !== null
                    ? DraftPlacement::apply($post, $id, $featured, $insert, $fields['alt'], $fields['caption'])
                    : null,
            ],
            201
        );
    }

    /**
     * REST `args` schema for upload().
     *
     * @return array<string, array<string, mixed>>
     */
    public static function uploadArgs(): array
    {
        return [
            'url'      => [
                'type'        => 'string',
                'maxLength'   => RemoteUrl::MAX_LENGTH,
                'description' => 'Public http(s) URL of the file, on port 80 or 443. Private and local addresses are rejected.',
            ],
            'data'     => [
                'type'        => 'string',
                'maxLength'   => MediaUploader::MAX_DATA_LENGTH,
                'description' => 'The file as standard base64, or a data: URI. At most 10 MB decoded.',
            ],
            'filename' => [
                'type'        => 'string',
                'maxLength'   => 200,
                'description' => 'File name to save under. The extension is always set from the file\'s real type.',
            ],
            'title'    => [
                'type'      => 'string',
                'maxLength' => 200,
            ],
            'alt'      => [
                'type'      => 'string',
                'maxLength' => 500,
            ],
            'caption'  => [
                'type'        => 'string',
                'maxLength'   => 1000,
                'description' => 'Plain text, or links (<a href>) and <em>/<strong> for credits.',
            ],
            'post_id'  => [
                'type'        => 'integer',
                'minimum'     => 1,
                'description' => 'Draft or pending post to attach the file to.',
            ],
            'featured' => [
                'type'        => 'boolean',
                'default'     => false,
                'description' => 'Set the image as the post\'s featured image (needs post_id).',
            ],
            'insert'   => [
                'type'        => 'boolean',
                'default'     => false,
                'description' => 'Append an image block to the post\'s content (needs post_id).',
            ],
        ];
    }

    /**
     * The file data is replaced by its length, and the URL loses its query
     * string (signed URLs carry credentials there).
     *
     * @param array<array-key, mixed> $args
     * @return array<array-key, mixed>
     */
    protected static function auditArgs(array $args): array
    {
        if (isset($args['data']) && is_string($args['data'])) {
            $args['data'] = sprintf('[base64, %d characters]', strlen($args['data']));
        }

        if (isset($args['url']) && is_string($args['url'])) {
            $args['url'] = RemoteUrl::redactQuery($args['url']);
        }

        return $args;
    }

    /**
     * The client's file name, else the URL's last path segment.
     */
    private function filename(Request $request): string
    {
        if ($request->has('filename')) {
            return (string) $request->get('filename');
        }

        $path = (string) wp_parse_url((string) $request->get('url', ''), PHP_URL_PATH);

        return $path !== '' ? rawurldecode(wp_basename($path)) : '';
    }
}
