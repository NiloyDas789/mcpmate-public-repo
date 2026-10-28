<?php

namespace McpMate\Services\Elementor;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * An Elementor request was rejected: the post cannot be edited, a patch does
 * not apply, the element tree is invalid, or Elementor failed to save.
 * ElementorController turns it into an error response with the same status,
 * code and hint.
 */
final class ElementorException extends \RuntimeException
{
    public const NOT_ELEMENTOR = 'mcpmate_elementor_not_elementor';

    public const POST_TYPE_NOT_ALLOWED = 'mcpmate_elementor_post_type_not_allowed';

    public const NOT_DRAFT = 'mcpmate_elementor_not_draft';

    public const LOCKED = 'mcpmate_elementor_locked';

    public const VERSION_CONFLICT = 'mcpmate_elementor_version_conflict';

    public const ELEMENT_NOT_FOUND = 'mcpmate_elementor_element_not_found';

    public const INVALID_PATCH = 'mcpmate_elementor_invalid_patch';

    public const INVALID_TREE = 'mcpmate_elementor_invalid_tree';

    public const TOO_LARGE = 'mcpmate_elementor_too_large';

    public const INVALID_SETTINGS = 'mcpmate_elementor_invalid_page_settings';

    public const FORBIDDEN = 'mcpmate_elementor_forbidden';

    public const SAVE_FAILED = 'mcpmate_elementor_save_failed';

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
