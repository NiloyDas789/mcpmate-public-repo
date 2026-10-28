<?php

namespace McpMate\Services\Settings;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Preferences that cannot be saved (400). Nothing was saved.
 */
final class PreferencesException extends \RuntimeException
{
    public const CODE = 'mcpmate_invalid_setting';

    /** @var array<string, string> */
    private array $errors;

    /**
     * @param array<string, string> $errors Key => why its value is invalid.
     */
    public function __construct(array $errors)
    {
        parent::__construct(__('Some settings are invalid. Nothing was saved.', 'mcpmate'));

        $this->errors = $errors;
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
