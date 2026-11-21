<?php

namespace MCPMate\Services\Approvals;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An approval could not be created, found or decided. Controllers turn it
 * into an error response with the same status, code and hint.
 *
 * Messages and hints never contain tokens or other secrets.
 */
final class ApprovalException extends \RuntimeException
{
    public const NOT_FOUND = 'mcpmate_approval_not_found';

    public const EXPIRED = 'mcpmate_approval_expired';

    public const ALREADY_DECIDED = 'mcpmate_approval_already_decided';

    public const STORE_FAILED = 'mcpmate_approval_store_failed';

    public const UNKNOWN_TOOL = 'mcpmate_approval_unknown_tool';

    /** Read mode is on: nothing an AI app asked for runs, so the request stays pending. */
    public const READ_MODE = 'mcpmate_read_mode';

    private string $errorCode;

    private int $status;

    private string $hint;

    public function __construct(string $message, string $errorCode, int $status, string $hint = '')
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
