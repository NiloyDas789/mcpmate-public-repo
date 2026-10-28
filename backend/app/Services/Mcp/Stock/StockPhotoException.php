<?php

namespace McpMate\Services\Mcp\Stock;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An Unsplash call failed. Messages may be shown to the model: no keys, no full URLs.
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message becomes JSON tool output, never HTML.
final class StockPhotoException extends \RuntimeException
{
    public const UNAVAILABLE = 'stock_photos_unavailable';

    public const NOT_FOUND = 'stock_photo_not_found';

    public const FAILED = 'stock_photos_failed';

    private string $errorCode;

    private ?string $hint;

    public function __construct(string $code, string $message, ?string $hint = null)
    {
        parent::__construct($message);

        $this->errorCode = $code;
        $this->hint      = $hint;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function hint(): ?string
    {
        return $this->hint;
    }
}
// phpcs:enable
