<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Blocks\BlockValidator;
use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\Shape;
use McpMate\Services\Mcp\SiteSchemas;
use McpMate\Services\Mcp\ToolResult;

/**
 * The block markup gate (cloud blocks.ts; docs/mcp-in-plugin.md §7.1,
 * item 8): writes that carry Gutenberg markup are checked with
 * BlockValidator first, and refused with every error when any block is
 * invalid. Nothing is written then. Warnings do not block. Covers
 * write_theme_file on an `.html` file and run_ability with markup in any
 * string of its input, so known gap 25 closes for those paths.
 */
final class BlockMarkup
{
    /** A comment that starts like a block delimiter, readable or not. */
    private const MARKUP = '/<!--\s*\/?\s*wp:/i';

    /** Strings of block markup checked in one call. */
    public const MAX_FIELDS = 10;

    /** Issues listed per kind in text. */
    public const ISSUES_SHOWN = 30;

    public static function has(string $text): bool
    {
        return preg_match(self::MARKUP, $text) === 1;
    }

    /**
     * Every string in $value (an ability's input) that holds block markup, labelled with its path.
     *
     * @param mixed                                          $value
     * @param array<int, array{label: string, content: string}> $found
     * @return array<int, array{label: string, content: string}>
     */
    public static function in($value, string $path = 'input', array $found = []): array
    {
        if (is_string($value)) {
            if (self::has($value)) {
                $found[] = ['label' => C::oneLine($path, 200), 'content' => $value];
            }
        } elseif (Js::isObject($value)) {
            foreach (Js::entries($value) as [$key, $item]) {
                $found = self::in($item, $path . '.' . $key, $found);
            }
        } elseif (is_array($value)) {
            foreach ($value as $index => $item) {
                $found = self::in($item, $path . '[' . $index . ']', $found);
            }
        }

        return $found;
    }

    /**
     * The refusal for the first piece with errors, or null when every piece is valid.
     *
     * @param array<int, array{label: string, content: string}> $pieces
     */
    public static function refuseInvalid(array $pieces): ?ToolResult
    {
        foreach ($pieces as $piece) {
            $validation = Shape::parse(SiteSchemas::blockValidation(), Js::fromPhp(BlockValidator::validate($piece['content'])));

            if (!$validation['valid']) {
                return self::refusal($piece['label'], $validation);
            }
        }

        return null;
    }

    /**
     * The error first (as every tool error), then the errors, fenced as site data.
     *
     * @param array<string, mixed> $validation
     */
    public static function refusal(string $label, array $validation): ToolResult
    {
        return ToolResult::error(
            'invalid_block_markup',
            'The block markup in ' . $label . ' is not valid (' . C::plural($validation['error_count'], 'error') . '), so the call was not run and nothing was written.',
            null,
            'Fix every error listed (each names its line and block path), then call again with the corrected markup. '
                . 'list_block_types shows the registered blocks and their attributes; validate_blocks checks markup without writing it.'
        )->withText(C::siteData('block markup errors', implode("\n", self::issueLines($validation, 'errors'))));
    }

    /**
     * One line per issue, the first ISSUES_SHOWN, plus how many more there are.
     *
     * @param array<string, mixed> $validation
     * @return string[]
     */
    public static function issueLines(array $validation, string $kind): array
    {
        $total = $kind === 'errors' ? $validation['error_count'] : $validation['warning_count'];
        $lines = array_map([self::class, 'issueLine'], array_slice($validation[$kind], 0, self::ISSUES_SHOWN));

        if ($total > count($lines)) {
            $lines[] = '… ' . ($total - count($lines)) . ' more ' . $kind;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $issue
     */
    public static function issueLine(array $issue): string
    {
        $where = implode(', ', array_filter([
            $issue['line'] === null ? null : 'line ' . $issue['line'],
            $issue['path'] === '' ? null : C::oneLine($issue['path'], 200),
        ], static fn (?string $part): bool => $part !== null));
        $hint  = $issue['hint'] === '' ? '' : ' Fix: ' . C::oneLine($issue['hint'], 400);

        return '- ' . $issue['code'] . ($where === '' ? '' : ' (' . $where . ')') . ': ' . C::oneLine($issue['message'], 300) . $hint;
    }
}
