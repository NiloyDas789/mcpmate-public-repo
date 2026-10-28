<?php

namespace McpMate\Operations\Cli;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A command was rejected: it could not be parsed, is not registered, has
 * invalid arguments, or its handler refused it. CliController turns it into
 * an error response with the same status, code and hint.
 *
 * Messages and hints are shown to the MCP client, so they never contain secrets.
 */
final class CommandException extends \RuntimeException
{
    public const PARSE_ERROR = 'mcpmate_cli_parse_error';

    public const UNKNOWN_COMMAND = 'mcpmate_cli_unknown_command';

    public const INVALID_ARGS = 'mcpmate_cli_invalid_args';

    public const NOT_ALLOWED = 'mcpmate_cli_not_allowed';

    public const NOT_FOUND = 'mcpmate_cli_not_found';

    public const FORBIDDEN = 'mcpmate_forbidden';

    public const FAILED = 'mcpmate_cli_failed';

    private string $errorCode;

    private int $status;

    private string $hint;

    public function __construct(string $message, string $errorCode = self::INVALID_ARGS, int $status = 400, string $hint = '')
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
