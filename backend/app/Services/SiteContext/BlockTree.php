<?php

namespace McpMate\Services\SiteContext;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\AuditLog\Logger;

/**
 * Turns parse_blocks() output into a compact tree of block names and attributes.
 *
 * Markup (innerHTML / innerContent) is dropped. Whitespace between blocks is
 * skipped and other loose HTML becomes `core/freeform`. Long attribute
 * strings are cut to MAX_STRING_LENGTH, attributes whose key looks secret
 * (Logger::SENSITIVE_KEY, e.g. `apiKey`) are redacted, and the tree stops at
 * MAX_DEPTH levels and MAX_NODES blocks (`truncated` is then true).
 */
final class BlockTree
{
    public const MAX_DEPTH = 20;

    public const MAX_NODES = 1000;

    public const MAX_STRING_LENGTH = 200;

    private int $nodes = 0;

    private bool $truncated = false;

    /** @var string[] */
    private array $names = [];

    /**
     * @param array<int, array<string, mixed>> $parsed parse_blocks() output.
     * @return array{blocks: array<int, array<string, mixed>>, truncated: bool, names: string[]}
     *               `names` lists every distinct block name, including ones past the limits.
     */
    public static function build(array $parsed): array
    {
        $tree   = new self();
        $blocks = $tree->walk($parsed, 1);

        return ['blocks' => $blocks, 'truncated' => $tree->truncated, 'names' => array_keys(array_flip($tree->names))];
    }

    /**
     * Cut a string to $max characters, marking the cut with an ellipsis.
     */
    public static function clip(string $value, int $max = self::MAX_STRING_LENGTH): string
    {
        if (mb_strlen($value, 'UTF-8') <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max, 'UTF-8') . '…';
    }

    // ──────────────────────────────────────────────
    //  Internals
    // ──────────────────────────────────────────────

    /**
     * @param array<int, mixed> $parsed
     * @return array<int, array<string, mixed>>
     */
    private function walk(array $parsed, int $depth): array
    {
        $out = [];

        foreach ($parsed as $block) {
            if (!is_array($block)) {
                continue;
            }

            $name = isset($block['blockName']) && is_string($block['blockName']) ? $block['blockName'] : null;

            if ($name === null) {
                if (trim((string) ($block['innerHTML'] ?? '')) === '') {
                    continue;
                }

                $name = 'core/freeform';
            }

            $this->names[] = $name;
            $inner         = isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : [];

            if ($this->nodes >= self::MAX_NODES || $depth > self::MAX_DEPTH) {
                $this->truncated = true;
                $this->collectNames($inner);
                continue;
            }

            $this->nodes++;

            $node = [
                'name'  => $name,
                'attrs' => (object) $this->attrs(isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : []),
            ];

            $children = $this->walk($inner, $depth + 1);

            if ($children !== []) {
                $node['innerBlocks'] = $children;
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * Names of blocks past the limits, so builder detection still sees them.
     *
     * @param array<int, mixed> $parsed
     */
    private function collectNames(array $parsed): void
    {
        foreach ($parsed as $block) {
            if (is_array($block) && isset($block['blockName']) && is_string($block['blockName'])) {
                $this->names[] = $block['blockName'];
                $this->collectNames(isset($block['innerBlocks']) && is_array($block['innerBlocks']) ? $block['innerBlocks'] : []);
            }
        }
    }

    /**
     * @param array<array-key, mixed> $attrs
     * @return array<array-key, mixed>
     */
    private function attrs(array $attrs): array
    {
        foreach ($attrs as $key => $value) {
            if (is_string($key) && preg_match(Logger::SENSITIVE_KEY, $key)) {
                $attrs[$key] = Logger::REDACTED;
            } elseif (is_string($value)) {
                $attrs[$key] = self::clip($value);
            } elseif (is_array($value)) {
                $attrs[$key] = $this->attrs($value);
            }
        }

        return $attrs;
    }
}
