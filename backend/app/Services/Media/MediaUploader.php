<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Utils\Capabilities;

/**
 * Turns a remote URL or base64 data into a Media Library attachment.
 *
 * 1. The bytes land in a temporary file: downloaded by MediaFetcher (SSRF
 *    checks, size cap) or decoded from strict base64.
 * 2. The type comes from the file's first bytes (MediaType), never from the
 *    client. It must be allowlisted there and allowed by the site
 *    (get_allowed_mime_types()); SVG is the exception, see below.
 * 3. SVG is accepted only while a site admin allowed it (MediaSettings::
 *    svgEnabled()), only from users with manage_options, and only after
 *    SvgSanitizer rewrote it. WordPress itself never allows SVG, so it is
 *    allowed for this one sideload only.
 * 4. media_handle_sideload() stores it, with only the detected type allowed
 *    for the duration of the call, and the saved file name gets the
 *    detected type's extension.
 */
final class MediaUploader
{
    /** Largest file accepted, from a URL or as data (10 MiB). */
    public const MAX_BYTES = 10485760;

    /** Longest `data` accepted: MAX_BYTES as base64, plus room for a `data:…;base64,` prefix. */
    public const MAX_DATA_LENGTH = 13981116;

    public const MAX_FILENAME_LENGTH = 80;

    /** HTML a caption may keep (e.g. a photographer credit link). */
    public const CAPTION_HTML = [
        'a'      => ['href' => true, 'rel' => true],
        'em'     => [],
        'strong' => [],
    ];

    /**
     * Download $url into a new temporary file.
     *
     * @param callable|null $resolver Passed to RemoteUrl::resolve(); tests only.
     * @return string Path of the temporary file; the caller deletes it.
     * @throws MediaException
     */
    public static function fromUrl(string $url, ?callable $resolver = null): string
    {
        $path = self::tempFile();

        try {
            MediaFetcher::download($url, $path, $resolver);
        } catch (MediaException $e) {
            self::discard($path);

            throw $e;
        }

        return $path;
    }

    /**
     * Decode base64 $data (optionally a `data:` URI) into a new temporary file.
     *
     * @return string Path of the temporary file; the caller deletes it.
     * @throws MediaException
     */
    public static function fromData(string $data): string
    {
        $data = trim($data);

        if (preg_match('#^data:[a-z0-9.+/-]*(?:;[a-z0-9=.+-]+)*;base64,#i', $data, $prefix)) {
            $data = (string) substr($data, strlen($prefix[0]));
        }

        $data = (string) preg_replace('/\s+/', '', $data);

        if (strlen($data) > self::MAX_DATA_LENGTH) {
            throw self::tooLarge();
        }

        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decodes an uploaded file, as the endpoint documents.
        $bytes = base64_decode($data, true);

        if ($bytes === false || $bytes === '') {
            throw new MediaException(
                esc_html__('The data is not valid base64.', 'mcpmate'),
                esc_html(MediaException::INVALID_DATA),
                400,
                esc_html__('Send the file\'s bytes as standard base64, optionally as a data: URI.', 'mcpmate')
            );
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            throw self::tooLarge();
        }

        $path = self::tempFile();

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Temporary file for media_handle_sideload(), as core's download_url() writes it.
        if (file_put_contents($path, $bytes) !== strlen($bytes)) {
            self::discard($path);

            throw self::uploadFailed();
        }

        return $path;
    }

    /**
     * The file's allowlisted type. SVG files are checked against the current
     * user's capability and rewritten in place by SvgSanitizer.
     *
     * @return array{mime: string, svg: array{removed_nodes: int, removed_attributes: int}|null}
     * @throws MediaException
     */
    public static function inspect(string $path): array
    {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary file.
        $head = (string) file_get_contents($path, false, null, 0, MediaType::HEAD_BYTES);
        $mime = MediaType::detect($head);

        if ($mime === null) {
            throw new MediaException(
                esc_html__('This file type is not allowed.', 'mcpmate'),
                esc_html(MediaException::TYPE_NOT_ALLOWED),
                415,
                esc_html__('Upload a JPEG, PNG, GIF, WebP, AVIF or PDF file (SVG for administrators). The type is read from the file itself, not its name.', 'mcpmate')
            );
        }

        if ($mime !== MediaType::SVG) {
            if (!in_array($mime, get_allowed_mime_types(), true)) {
                throw new MediaException(
                    /* translators: %s: MIME type, e.g. image/avif. */
                    sprintf(esc_html__('This site does not accept %s uploads.', 'mcpmate'), esc_html($mime)),
                    esc_html(MediaException::TYPE_NOT_ALLOWED),
                    415,
                    esc_html__('Convert the file to JPEG or PNG.', 'mcpmate')
                );
            }

            return ['mime' => $mime, 'svg' => null];
        }

        // The earliest point the type is known: it is read from the file.
        if (!MediaSettings::svgEnabled()) {
            throw new MediaException(
                esc_html__('SVG uploads are turned off on this site.', 'mcpmate'),
                esc_html(MediaException::SVG_DISABLED),
                403,
                esc_html__('A site admin can allow SVG under Tools → Core (Media).', 'mcpmate')
            );
        }

        if (!Capabilities::check('manage_options')) {
            throw new MediaException(
                esc_html__('Only administrators can upload SVG files.', 'mcpmate'),
                esc_html(MediaException::SVG_FORBIDDEN),
                403,
                esc_html__('Upload a PNG or JPEG instead, or use an administrator account.', 'mcpmate')
            );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local temporary file.
        $clean = SvgSanitizer::sanitize((string) file_get_contents($path));

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Rewrites the temporary file before it is sideloaded.
        if (file_put_contents($path, $clean['svg']) !== strlen($clean['svg'])) {
            throw self::uploadFailed();
        }

        return [
            'mime' => $mime,
            'svg'  => ['removed_nodes' => $clean['removed_nodes'], 'removed_attributes' => $clean['removed_attributes']],
        ];
    }

    /**
     * Store the temporary file as an attachment (the file is moved).
     *
     * @param array{title: string, alt: string, caption: string} $fields Already sanitized.
     * @param int $parentId Post the attachment belongs to, or 0.
     * @throws MediaException
     */
    public static function store(string $path, string $mime, string $filename, array $fields, int $parentId): int
    {
        self::loadAdminIncludes();

        $ext  = MediaType::extension($mime);
        $file = ['name' => self::filename($filename, $ext), 'tmp_name' => $path];

        // Only the detected type may be stored during this call.
        $mimes = static function () use ($ext, $mime): array {
            return [$ext => $mime];
        };
        // finfo reports SVG inconsistently (text/xml, image/svg); the file was detected and rewritten above.
        $svgType = static function ($check, $file) use ($path, $ext, $mime) {
            return $mime === MediaType::SVG && $file === $path
                ? ['ext' => $ext, 'type' => $mime, 'proper_filename' => false]
                : $check;
        };

        add_filter('upload_mimes', $mimes, PHP_INT_MAX);
        add_filter('wp_check_filetype_and_ext', $svgType, PHP_INT_MAX, 2);

        try {
            $id = media_handle_sideload(
                $file,
                $parentId,
                $fields['title'] !== '' ? $fields['title'] : null,
                $fields['caption'] !== '' ? ['post_excerpt' => $fields['caption']] : []
            );
        } finally {
            remove_filter('upload_mimes', $mimes, PHP_INT_MAX);
            remove_filter('wp_check_filetype_and_ext', $svgType, PHP_INT_MAX);
        }

        if (is_wp_error($id)) {
            throw new MediaException(
                esc_html__('WordPress could not store the file.', 'mcpmate'),
                esc_html(MediaException::UPLOAD_FAILED),
                500,
                esc_html(wp_strip_all_tags($id->get_error_message()))
            );
        }

        if ($fields['alt'] !== '' && MediaType::isImage($mime)) {
            update_post_meta($id, '_wp_attachment_image_alt', $fields['alt']);
        }

        return (int) $id;
    }

    /**
     * What the client needs to use the attachment: ID, URLs and sizes.
     *
     * @return array<string, mixed>
     */
    public static function describe(int $id): array
    {
        $meta  = wp_get_attachment_metadata($id);
        $meta  = is_array($meta) ? $meta : [];
        $sizes = [];

        foreach ((array) ($meta['sizes'] ?? []) as $name => $size) {
            $url = wp_get_attachment_image_url($id, (string) $name);

            // The file's own dimensions: image_src() shrinks them to the theme's content width.
            if (is_string($url) && $url !== '') {
                $sizes[(string) $name] = [
                    'url'       => $url,
                    'width'     => (int) $size['width'],
                    'height'    => (int) $size['height'],
                    'mime_type' => $size['mime-type'],
                ];
            }
        }

        $file = get_attached_file($id);

        return [
            'id'        => $id,
            'url'       => (string) wp_get_attachment_url($id),
            'title'     => get_the_title($id),
            'alt'       => (string) get_post_meta($id, '_wp_attachment_image_alt', true),
            'caption'   => (string) wp_get_attachment_caption($id),
            'mime_type' => (string) get_post_mime_type($id),
            'filesize'  => isset($meta['filesize']) ? (int) $meta['filesize'] : ($file && file_exists($file) ? (int) filesize($file) : null),
            'width'     => isset($meta['width']) ? (int) $meta['width'] : null,
            'height'    => isset($meta['height']) ? (int) $meta['height'] : null,
            'sizes'     => $sizes,
            'edit_url'  => (string) get_edit_post_link($id, 'raw'),
        ];
    }

    /**
     * Saved file name: the client's name (or "media") without its
     * extension, sanitized and shortened, plus the detected type's extension.
     */
    public static function filename(string $name, string $ext): string
    {
        $base = (string) preg_replace('/\.[^.]*$/', '', wp_basename($name));
        $base = (string) preg_replace('/[^A-Za-z0-9._-]+/', '-', sanitize_file_name($base));
        $base = trim(substr($base, 0, self::MAX_FILENAME_LENGTH), '.-_');

        return ($base !== '' ? $base : 'media') . '.' . $ext;
    }

    public static function discard(string $path): void
    {
        if ($path !== '' && file_exists($path)) {
            wp_delete_file($path);
        }
    }

    public static function tooLarge(): MediaException
    {
        return new MediaException(
            esc_html__('The file is larger than 10 MB.', 'mcpmate'),
            esc_html(MediaException::TOO_LARGE),
            413,
            esc_html__('Use a smaller or more compressed version of the file.', 'mcpmate')
        );
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    private static function tempFile(): string
    {
        self::loadAdminIncludes();

        $path = wp_tempnam('mcpmate-media');

        if ($path === '') {
            throw self::uploadFailed();
        }

        return $path;
    }

    private static function uploadFailed(): MediaException
    {
        return new MediaException(
            esc_html__('The file could not be saved on the site.', 'mcpmate'),
            esc_html(MediaException::UPLOAD_FAILED),
            500,
            esc_html__('The site\'s temporary or uploads folder may not be writable. Ask a site admin to check it.', 'mcpmate')
        );
    }

    /** media_handle_sideload() and wp_tempnam() live in wp-admin, which REST requests do not load. */
    private static function loadAdminIncludes(): void
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
    }
}
