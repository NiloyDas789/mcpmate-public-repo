<?php

namespace McpMate\Services\ThemeDraft;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * A theme draft operation was refused: a path outside the draft folder, a
 * file type that is not allowlisted, code that does not parse, a draft or
 * backup that does not exist, or a filesystem failure. ThemeDraftController
 * turns it into an error response with the same status, code and hint.
 *
 * Messages and hints are shown to the MCP client, so they name files by
 * their path inside the draft, never by absolute server paths.
 */
final class ThemeDraftException extends \RuntimeException
{
    public const INVALID_PATH = 'mcpmate_theme_invalid_path';

    public const OUTSIDE_DRAFT = 'mcpmate_theme_path_outside_draft';

    public const FILE_TYPE = 'mcpmate_theme_file_type_not_allowed';

    public const NOT_FOUND = 'mcpmate_theme_file_not_found';

    public const NOT_A_FILE = 'mcpmate_theme_not_a_file';

    public const TOO_LARGE = 'mcpmate_theme_file_too_large';

    public const PROTECTED_FILE = 'mcpmate_theme_file_protected';

    public const PHP_SYNTAX = 'mcpmate_theme_php_syntax_error';

    public const LINT_FAILED = 'mcpmate_theme_lint_failed';

    public const JSON_INVALID = 'mcpmate_theme_json_invalid';

    public const FIND_NOT_FOUND = 'mcpmate_theme_find_not_found';

    public const FIND_AMBIGUOUS = 'mcpmate_theme_find_ambiguous';

    public const DRAFT_NOT_FOUND = 'mcpmate_theme_draft_not_found';

    public const DRAFT_LIMIT = 'mcpmate_theme_draft_limit';

    public const DRAFT_INVALID = 'mcpmate_theme_draft_invalid';

    public const DRAFT_PUBLISHED = 'mcpmate_theme_draft_published';

    public const THEME_INVALID = 'mcpmate_theme_invalid';

    public const THEME_TOO_LARGE = 'mcpmate_theme_too_large';

    public const BACKUP_NOT_FOUND = 'mcpmate_theme_backup_not_found';

    public const BACKUP_FAILED = 'mcpmate_theme_backup_failed';

    public const ROLLBACK_STALE = 'mcpmate_theme_rollback_stale';

    public const FILESYSTEM = 'mcpmate_theme_filesystem_error';

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
