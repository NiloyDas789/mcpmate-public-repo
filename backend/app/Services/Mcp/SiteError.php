<?php

namespace McpMate\Services\Mcp;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A plugin route refused or failed a tool's call: the route's own code,
 * message and hint (the envelope's `error`) and its HTTP status. Tools turn
 * it into a tool error with a next step (ToolErrors::fromSite()).
 */
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- The message becomes JSON tool output, never HTML.
final class SiteError extends \RuntimeException
{
    private string $errorCode;

    private string $hint;

    private ?int $status;

    public function __construct(string $code, string $message, string $hint = '', ?int $status = null)
    {
        parent::__construct($message);

        $this->errorCode = $code;
        $this->hint      = $hint;
        $this->status    = $status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function hint(): string
    {
        return $this->hint;
    }

    public function status(): ?int
    {
        return $this->status;
    }
}
// phpcs:enable
