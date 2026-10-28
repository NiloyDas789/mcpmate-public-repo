<?php

namespace McpMate\Services\DbInspect;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A database inspection request was rejected or failed. DbController turns
 * it into an error response with the same status, code and hint.
 *
 * Messages and hints are shown to the MCP client, so they never contain
 * secrets or raw database errors.
 */
final class DbInspectException extends \RuntimeException
{
    public const INVALID = 'mcpmate_db_invalid_query';

    public const UNKNOWN_COLUMN = 'mcpmate_db_unknown_column';

    public const PROTECTED_COLUMN = 'mcpmate_db_column_protected';

    public const TIMEOUT = 'mcpmate_db_query_timeout';

    public const FAILED = 'mcpmate_db_query_failed';

    private string $errorCode;

    private int $status;

    private string $hint;

    public function __construct(string $message, string $errorCode = self::INVALID, int $status = 400, string $hint = '')
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
