<?php

namespace McpMate\Services\Abilities;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An ability request was refused or failed: the Abilities API is missing
 * (WordPress < 6.9), the ability is unknown, hidden from REST or not
 * enabled, its input does not match its schema, its permission check
 * refused, or it returned an error. AbilitiesController turns it into an
 * error response with the same status, code and hint.
 */
final class AbilityException extends \RuntimeException
{
    public const UNSUPPORTED = 'mcpmate_abilities_unsupported';

    public const NOT_FOUND = 'mcpmate_ability_not_found';

    public const DISABLED = 'mcpmate_ability_disabled';

    public const INVALID_INPUT = 'mcpmate_ability_invalid_input';

    public const INPUT_TOO_LARGE = 'mcpmate_ability_input_too_large';

    public const FORBIDDEN = 'mcpmate_ability_forbidden';

    public const FAILED = 'mcpmate_ability_failed';

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
