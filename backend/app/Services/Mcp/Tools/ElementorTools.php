<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\SiteSchemas;
use McpMate\Services\Mcp\Url;

/**
 * Shared by elementor_get, elementor_widgets and elementor_save (cloud
 * elementor.ts): what is plainly wrong with a tree before the route is
 * called (the route's TreeValidator is the real check), the outline, and
 * the links people open.
 */
final class ElementorTools
{
    /** TreeValidator::ELEMENT_TYPES. */
    public const ELEMENT_TYPES = ['container', 'section', 'column', 'widget'];

    /** TreeValidator::MAX_ELEMENTS and MAX_DEPTH. */
    public const MAX_ELEMENTS = 2000;

    public const MAX_ELEMENT_DEPTH = 20;

    /** A tree sent in one call, as JSON. */
    public const MAX_TREE_BYTES = 900000;

    /** Nesting inside one element's settings (repeaters, responsive objects). */
    public const MAX_SETTINGS_DEPTH = 12;

    /** ElementTree::TRUNCATED_KEY: what the route puts in place of a long string when it reads a page. */
    public const TRUNCATED_KEY = '__mcpmate_truncated';

    /**
     * What is plainly wrong with a tree, or null.
     *
     * @param mixed[] $elements
     */
    public static function treeProblem(array $elements, string $path = 'elements'): ?string
    {
        if (strlen(Js::stringify($elements)) > self::MAX_TREE_BYTES) {
            return $path . ' must be at most ' . (int) round(self::MAX_TREE_BYTES / 1000) . ' KB as JSON; change large pages with patches.';
        }

        $count = 0;

        return self::walk($elements, $path, 1, $count);
    }

    /**
     * @param mixed $list
     */
    private static function walk($list, string $at, int $depth, int &$count): ?string
    {
        if (!is_array($list) || !Js::isList($list)) {
            return $at . ' must be a list of elements.';
        }

        foreach ($list as $index => $element) {
            $here = $at . '[' . $index . ']';

            if (!$element instanceof \stdClass) {
                return $here . ' must be an element object.';
            }

            if ($depth > self::MAX_ELEMENT_DEPTH) {
                return $here . ' is nested deeper than ' . self::MAX_ELEMENT_DEPTH . ' levels.';
            }

            if (++$count > self::MAX_ELEMENTS) {
                return 'A page can have at most ' . self::MAX_ELEMENTS . ' elements.';
            }

            $id       = $element->id ?? null;
            $elType   = $element->elType ?? null;
            $widget   = $element->widgetType ?? null;
            $settings = property_exists($element, 'settings') ? $element->settings : null;

            if (property_exists($element, 'id') && (!is_string($id) || !preg_match(SiteSchemas::ELEMENTOR_ELEMENT_ID, $id))) {
                return $here . '.id must be 1–32 letters or digits (or left out to be generated).';
            }

            if (!is_string($elType) || !in_array($elType, self::ELEMENT_TYPES, true)) {
                return $here . '.elType must be one of ' . implode(', ', self::ELEMENT_TYPES) . '.';
            }

            if ($elType === 'widget' && (!is_string($widget) || !preg_match(SiteSchemas::ELEMENTOR_WIDGET_NAME, $widget))) {
                return $here . ' is a widget without a valid widgetType (e.g. "heading").';
            }

            if (property_exists($element, 'settings') && !$settings instanceof \stdClass && $settings !== []) {
                return $here . '.settings must be an object.';
            }

            if (RunAbilityTool::depthOf($settings) > self::MAX_SETTINGS_DEPTH) {
                return $here . '.settings are nested deeper than ' . self::MAX_SETTINGS_DEPTH . ' levels.';
            }

            if (property_exists($element, 'elements')) {
                $problem = self::walk($element->elements, $here . '.elements', $depth + 1, $count);

                if ($problem !== null) {
                    return $problem;
                }
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────
    //  Rendering
    // ──────────────────────────────────────────────

    /**
     * "heading" for widgets, the elType for the rest.
     */
    public static function elementLabel(\stdClass $element): string
    {
        $elType = is_string($element->elType ?? null) ? C::oneLine($element->elType, 40) : '?';

        if ($elType !== 'widget') {
            return $elType;
        }

        return is_string($element->widgetType ?? null) ? C::oneLine($element->widgetType, 100) : 'widget';
    }

    /**
     * A setting value on one short line: sizes as "56px", boxes as "96 24 96 24 px", links by URL.
     *
     * @param mixed $value
     */
    public static function settingValue($value): string
    {
        if (is_string($value)) {
            return Js::stringify(C::oneLine($value, 60));
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return C::scalar($value);
        }

        if (Js::isObject($value)) {
            $get = static fn (string $key) => Js::get($value, $key);
            $has = static fn (string $key): bool => Js::get($value, $key, "\0") !== "\0";

            if ($get(self::TRUNCATED_KEY) === true) {
                $length = $get('length');

                return '[long text, ' . (is_int($length) || is_float($length) ? Js::number($length) : '?') . ' chars]';
            }

            $unit = is_string($get('unit')) ? $get('unit') : '';
            $size = $get('size');

            if ($has('size') && (is_int($size) || is_float($size) || is_string($size))) {
                return (is_string($size) ? $size : Js::number($size)) . $unit;
            }

            if ($has('top') && $has('right') && $has('bottom') && $has('left')) {
                $side = static function (string $name) use ($get): string {
                    $item = $get($name);

                    return is_string($item) ? $item : (is_int($item) || is_float($item) ? Js::number($item) : '');
                };

                return implode(' ', array_map($side, ['top', 'right', 'bottom', 'left'])) . ($unit === '' ? '' : ' ' . $unit);
            }

            if (is_string($get('url'))) {
                return Js::stringify(C::oneLine($get('url'), 80));
            }

            if (is_string($get('value')) && is_string($get('library'))) {
                return C::oneLine($get('value'), 60);
            }
        }

        if (is_array($value) && Js::isList($value)) {
            return '[' . count($value) . ' items]';
        }

        return C::oneLine(Js::stringify($value), 80);
    }

    /**
     * The first $max settings as `key=value`, and how many more there are.
     *
     * @param mixed $settings
     */
    public static function settingsSummary($settings, int $max = 6): string
    {
        if (!Js::isObject($settings)) {
            return '';
        }

        $entries = Js::entries($settings);
        $shown   = array_map(static fn (array $entry): string => C::oneLine($entry[0], 60) . '=' . self::settingValue($entry[1]), array_slice($entries, 0, $max));

        if (count($entries) > $max) {
            $shown[] = '+' . (count($entries) - $max) . ' more';
        }

        return implode(', ', $shown);
    }

    /**
     * The tree as indented lines (`- <id> <type>: settings…`) and as entries for structured output.
     *
     * @param mixed[] $elements
     * @param string[] $lines
     * @param array<int, array{id: string, type: string, depth: int, settings: int}> $entries
     * @return array{lines: string[], entries: array<int, array{id: string, type: string, depth: int, settings: int}>}
     */
    public static function outline(array $elements, int $depth = 0, array $lines = [], array $entries = []): array
    {
        foreach ($elements as $element) {
            if (!$element instanceof \stdClass) {
                continue;
            }

            $id      = is_string($element->id ?? null) && preg_match(SiteSchemas::ELEMENTOR_ELEMENT_ID, $element->id) ? $element->id : '?';
            $type    = self::elementLabel($element);
            $summary = self::settingsSummary($element->settings ?? null);

            $lines[]   = str_repeat('  ', $depth) . '- ' . $id . ' ' . $type . ($summary === '' ? '' : ': ' . $summary);
            $entries[] = ['id' => $id, 'type' => $type, 'depth' => $depth, 'settings' => Js::isObject($element->settings ?? null) ? Js::size($element->settings) : 0];

            if (is_array($element->elements ?? null)) {
                ['lines' => $lines, 'entries' => $entries] = self::outline($element->elements, $depth + 1, $lines, $entries);
            }
        }

        return ['lines' => $lines, 'entries' => $entries];
    }

    /**
     * @param array<string, mixed> $issue
     */
    public static function issueLine(array $issue): string
    {
        $where = implode(' ', array_filter([
            $issue['path'] === '' ? null : C::oneLine($issue['path'], 200),
            $issue['element_id'] === '' ? null : '#' . C::oneLine($issue['element_id'], 40),
            $issue['setting'] === '' ? null : C::oneLine($issue['setting'], 200),
        ], static fn (?string $part): bool => $part !== null));
        $hint  = $issue['hint'] === '' ? '' : ' Fix: ' . C::oneLine($issue['hint'], 300);

        return '- ' . C::oneLine($issue['code'], 60) . ($where === '' ? '' : ' (' . $where . ')') . ': ' . C::oneLine($issue['message'], 300) . $hint;
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return string[]
     */
    public static function issueLines(array $issues, int $total, string $kind, int $shown = 30): array
    {
        $lines = array_map([self::class, 'issueLine'], array_slice($issues, 0, $shown));

        if ($total > count($lines)) {
            $lines[] = '… ' . ($total - count($lines)) . ' more ' . $kind;
        }

        return $lines;
    }

    // ──────────────────────────────────────────────
    //  Links
    // ──────────────────────────────────────────────

    /**
     * The Elementor editor for a post, built from the site URL (never the route's own answer).
     */
    public static function editorLink(string $siteUrl, int $postId): string
    {
        return Url::withQuery($siteUrl . '/wp-admin/post.php', ['post' => (string) $postId, 'action' => 'elementor']);
    }

    /**
     * WordPress's preview of a draft, for a signed-in user.
     */
    public static function previewLink(string $siteUrl, int $postId, string $postType): string
    {
        return Url::withQuery($siteUrl . '/', [$postType === 'page' ? 'page_id' : 'p' => (string) $postId, 'preview' => 'true']);
    }
}
