<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * validate_blocks → POST blocks/validate (cloud validateBlocks.ts). Saves nothing.
 */
final class ValidateBlocksTool extends Tool
{
    /** Markup accepted in one call. */
    public const MAX_BYTES = 524288;

    private const ISSUES_STRUCTURED_BYTES = 8000;

    public function run(\stdClass $args): ToolResult
    {
        if (strlen($args->content) > self::MAX_BYTES) {
            return ToolResult::invalidInput('The content is larger than 512 KB.', 'Validate it in parts, e.g. one top-level block at a time.');
        }

        return self::out(self::format($this->siteId, $this->client->validateBlocks($args->content)['data']));
    }

    /**
     * @param array<string, mixed> $validation
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $validation): array
    {
        $structured = [
            'site_id'       => $siteId,
            'valid'         => $validation['valid'],
            'blocks'        => $validation['blocks'],
            'error_count'   => $validation['error_count'],
            'warning_count' => $validation['warning_count'],
            'errors'        => C::fit($validation['errors'], self::ISSUES_STRUCTURED_BYTES),
            'warnings'      => C::fit($validation['warnings'], self::ISSUES_STRUCTURED_BYTES),
        ];

        $summary = $validation['valid']
            ? 'Valid: ' . C::plural($validation['blocks'], 'block') . ', no errors' . ($validation['warning_count'] > 0 ? ', ' . C::plural($validation['warning_count'], 'warning') : '') . '.'
            : 'Not valid: ' . C::plural($validation['error_count'], 'error') . ' and ' . C::plural($validation['warning_count'], 'warning') . ' in ' . C::plural($validation['blocks'], 'block') . '.';

        $lines = [$summary];
        if ($validation['error_count'] > 0) {
            $lines[] = C::siteData('errors', implode("\n", BlockMarkup::issueLines($validation, 'errors')));
        }
        if ($validation['warning_count'] > 0) {
            $lines[] = C::siteData('warnings', implode("\n", BlockMarkup::issueLines($validation, 'warnings')));
        }

        $next = null;
        if (!$validation['valid']) {
            $next = 'Fix the errors and validate again before writing the markup. list_block_types shows each block’s attributes.';
        } elseif ($validation['warning_count'] > 0) {
            $next = 'The markup can be written. Check whether the warnings are intended.';
        }

        return ['text' => C::finish($lines, $next, 'Validate a smaller part of the content.'), 'structured' => $structured];
    }
}
