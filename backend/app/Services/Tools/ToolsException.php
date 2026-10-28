<?php

namespace McpMate\Services\Tools;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A tool change that cannot be saved (400). Nothing was saved.
 */
final class ToolsException extends \RuntimeException
{
    public const UNKNOWN_TOOL = 'mcpmate_unknown_tool';

    public const UNKNOWN_ITEM = 'mcpmate_unknown_tool_item';

    public const INVALID_VALUE = 'mcpmate_invalid_tool_value';

    public const ALWAYS_ON = 'mcpmate_tool_always_on';

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
