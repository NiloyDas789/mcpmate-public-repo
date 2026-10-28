<?php

namespace McpMate\Services\Media;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A media upload was rejected: the URL is not allowed, the download failed,
 * the file is too large or of a type that is not allowlisted, or an SVG
 * could not be sanitized. MediaController turns it into an error response
 * with the same status, code and hint.
 *
 * Messages and hints are shown to the MCP client, so they never contain
 * secrets (no full URLs with query strings, no resolved addresses).
 */
final class MediaException extends \RuntimeException
{
    public const INVALID_URL = 'mcpmate_media_invalid_url';

    public const URL_BLOCKED = 'mcpmate_media_url_blocked';

    public const FETCH_FAILED = 'mcpmate_media_fetch_failed';

    public const FETCH_UNAVAILABLE = 'mcpmate_media_fetch_unavailable';

    public const INVALID_DATA = 'mcpmate_media_invalid_data';

    public const TOO_LARGE = 'mcpmate_media_too_large';

    public const TYPE_NOT_ALLOWED = 'mcpmate_media_type_not_allowed';

    public const SVG_FORBIDDEN = 'mcpmate_media_svg_forbidden';

    public const SVG_DISABLED = 'mcpmate_svg_disabled';

    public const SVG_INVALID = 'mcpmate_media_svg_invalid';

    public const UPLOAD_FAILED = 'mcpmate_media_upload_failed';

    private string $errorCode;

    private int $status;

    private string $hint;

    public function __construct(string $message, string $errorCode, int $status = 400, string $hint = '')
    {
        parent::__construct($message);

        $this->errorCode = $errorCode;
        $this->status    = $status;
        $this->hint      = $hint;
    }

    /**
     * Stable `mcpmate_*` error code.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function hint(): string
    {
        return $this->hint;
    }
}
