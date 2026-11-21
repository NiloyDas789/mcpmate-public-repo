<?php

namespace MCPMate\Console;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A `wp mcpmate` command was refused; nothing changed. The message is
 * escaped where it is thrown (HTTP\ErrorPresenter::plain() decodes it).
 */
final class ConsoleException extends \RuntimeException
{
    private string $errorCode;

    public function __construct(string $message, string $errorCode)
    {
        parent::__construct($message);

        $this->errorCode = $errorCode;
    }

    /**
     * Stable `mcpmate_*` error code.
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
