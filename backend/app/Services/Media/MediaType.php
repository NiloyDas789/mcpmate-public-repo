<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * The file types POST /media accepts, recognised from the file's first bytes.
 *
 * The client's Content-Type header, the URL and the file name are never
 * trusted for the type: the extension of the saved file always comes from
 * TYPES for the detected type, so a `.php` name or a PNG that is really
 * HTML cannot get through. Types not listed here are rejected.
 */
final class MediaType
{
    public const SVG = 'image/svg+xml';

    public const PDF = 'application/pdf';

    /** Allowlisted MIME type → extension of the saved file. */
    public const TYPES = [
        'image/jpeg'    => 'jpg',
        'image/png'     => 'png',
        'image/gif'     => 'gif',
        'image/webp'    => 'webp',
        'image/avif'    => 'avif',
        self::SVG       => 'svg',
        self::PDF       => 'pdf',
    ];

    /** Bytes of the file detect() needs to see. */
    public const HEAD_BYTES = 4096;

    /**
     * The allowlisted MIME type of a file starting with $head, or null.
     */
    public static function detect(string $head): ?string
    {
        if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) {
            return 'image/jpeg';
        }

        if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) {
            return 'image/png';
        }

        if (strncmp($head, 'GIF87a', 6) === 0 || strncmp($head, 'GIF89a', 6) === 0) {
            return 'image/gif';
        }

        if (strncmp($head, 'RIFF', 4) === 0 && (string) substr($head, 8, 4) === 'WEBP') {
            return 'image/webp';
        }

        if ((string) substr($head, 4, 4) === 'ftyp' && in_array(substr($head, 8, 4), ['avif', 'avis'], true)) {
            return 'image/avif';
        }

        if (strncmp($head, '%PDF-', 5) === 0) {
            return self::PDF;
        }

        return self::looksLikeSvg($head) ? self::SVG : null;
    }

    public static function extension(string $mime): string
    {
        return self::TYPES[$mime] ?? '';
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    /**
     * An `<svg` root element, after an optional UTF-8 BOM, XML declaration and
     * comments. A DOCTYPE is not accepted here (SvgSanitizer rejects it too).
     */
    private static function looksLikeSvg(string $head): bool
    {
        if (strncmp($head, "\xEF\xBB\xBF", 3) === 0) {
            $head = (string) substr($head, 3);
        }

        return (bool) preg_match('/^\s*(?:<\?xml\s[^>]*\?>\s*)?(?:<!--.*?-->\s*)*<svg[\s>\/]/s', $head);
    }
}
