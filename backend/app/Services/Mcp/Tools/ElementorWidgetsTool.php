<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * elementor_widgets → GET elementor/widgets (with name, one of them)
 * (cloud elementorWidgets.ts).
 */
final class ElementorWidgetsTool extends Tool
{
    /** Widgets per page; each lists up to 40 control keys. */
    public const PER_PAGE = 20;

    /** Values shown per control in a widget's detail. */
    private const OPTIONS_SHOWN = 12;

    private const STRUCTURED_BYTES = 16000;

    public function run(\stdClass $args): ToolResult
    {
        if (isset($args->name)) {
            $reply = $this->client->elementorWidget($args->name, $args->tab ?? null);

            return self::out(self::formatWidget($this->siteId, $reply['data'], $args->tab ?? null));
        }

        $filters = ['search' => $args->search ?? null, 'category' => $args->category ?? null];
        $reply   = $this->client->elementorWidgets($filters + ['page' => $args->page, 'per_page' => self::PER_PAGE]);

        return self::out(self::format($this->siteId, $reply['data'], $filters));
    }

    /**
     * @param array<string, mixed>       $page
     * @param array<string, string|null> $filters
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $page, array $filters = []): array
    {
        $widgets    = array_slice($page['widgets'], 0, self::PER_PAGE);
        $structured = [
            'site_id'     => $siteId,
            'total'       => $page['total'],
            'page'        => $page['page'],
            'total_pages' => $page['total_pages'],
            'containers'  => $page['containers'],
            'widgets'     => C::fit($widgets, self::STRUCTURED_BYTES),
            'widget'      => null,
        ];
        $filtered   = ($filters['search'] ?? null) !== null || ($filters['category'] ?? null) !== null;

        if ($widgets === []) {
            return [
                'text'       => C::finish(
                    [$page['page'] > 1 ? 'Page ' . $page['page'] . ' is past the last page (' . $page['total_pages'] . ').' : 'No widgets match these filters.'],
                    $filtered ? 'Try a broader search, or no filters.' : null
                ),
                'structured' => $structured,
            ];
        }

        $lines = array_map(static function (array $widget): string {
            $controls   = array_map(static fn (array $entry): string => C::oneLine($entry[0], 80) . ' (' . C::oneLine($entry[1], 30) . ')', Js::entries($widget['content_controls']));
            $categories = $widget['categories'] !== [] ? ' [' . implode(', ', array_map(static fn (string $category): string => C::oneLine($category, 40), $widget['categories'])) . ']' : '';

            return '- ' . C::oneLine($widget['name'], 100) . ' — ' . C::oneLine($widget['title'], 80) . ($widget['kind'] === 'element' ? ' (element type)' : '')
                . $categories . ': ' . implode(', ', $controls);
        }, $widgets);

        $layout = $page['containers']
            ? 'Lay pages out with containers (container in container for rows and columns).'
            : 'Flexbox containers are off on this site: lay pages out with sections and columns.';
        $next   = $page['page'] < $page['total_pages']
            ? 'There are more: call elementor_widgets with page ' . ($page['page'] + 1) . ($filtered ? ' and the same filters' : '') . '.'
            : 'Call elementor_widgets with name for one widget’s full controls (style keys, options, units).';

        $text = C::finish(
            [
                C::plural($page['total'], 'element and widget type') . ' (page ' . $page['page'] . ' of ' . max($page['total_pages'], 1) . '). ' . $layout,
                C::siteData('widgets and their main controls', implode("\n", $lines)),
            ],
            $next,
            'Narrow the list with search or category.'
        );

        return ['text' => $text, 'structured' => $structured];
    }

    /**
     * `key: type [tab] responsive = default (a|b|c) units px/em`.
     *
     * @param array<string, mixed> $control
     * @return string[]
     */
    public static function controlLine(array $control, string $indent = ''): array
    {
        $parts = [$indent . '- ' . C::oneLine($control['key'], 100) . ': ' . C::oneLine($control['type'], 40)];

        if ($control['tab'] !== '') {
            $parts[] = '[' . C::oneLine($control['tab'], 20) . ']';
        }
        if (($control['responsive'] ?? null) === true) {
            $parts[] = 'responsive';
        }
        if (($control['writable'] ?? null) === false) {
            $parts[] = '(not writable)';
        }
        if (array_key_exists('default', $control)) {
            $parts[] = '= ' . C::oneLine(Js::stringify($control['default']), 80);
        }

        $options = $control['options'] ?? [];
        if ($options !== []) {
            $shown   = array_map(static fn (string $option): string => $option === '' ? '""' : C::oneLine($option, 30), array_slice($options, 0, self::OPTIONS_SHOWN));
            $parts[] = '(' . implode('|', $shown) . (count($options) > self::OPTIONS_SHOWN ? '|…+' . (count($options) - self::OPTIONS_SHOWN) : '') . ')';
        }

        $units = $control['size_units'] ?? [];
        if ($units !== []) {
            $parts[] = 'units ' . implode('/', array_map(static fn (string $unit): string => C::oneLine($unit, 10), $units));
        }
        if (array_key_exists('return_value', $control)) {
            $parts[] = 'on="' . C::oneLine($control['return_value'], 30) . '"';
        }
        if (($control['multiple'] ?? null) === true) {
            $parts[] = 'multiple';
        }

        $lines = [implode(' ', $parts)];
        foreach ($control['fields'] ?? [] as $field) {
            array_push($lines, ...self::controlLine($field, $indent . '    '));
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $widget
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function formatWidget(string $siteId, array $widget, ?string $tab = null): array
    {
        $structured = [
            'site_id'     => $siteId,
            'total'       => null,
            'page'        => null,
            'total_pages' => null,
            'containers'  => null,
            'widgets'     => null,
            'widget'      => C::fit($widget, self::STRUCTURED_BYTES),
        ];

        $about = implode(' ', array_filter([
            C::oneLine($widget['name'], 100) . ' — ' . C::oneLine($widget['title'], 80) . ' (' . ($widget['kind'] === 'element' ? 'element type' : 'widget') . ')',
            $widget['accepts_children'] ? 'Holds one container per item (nested widget).' : '',
            $widget['breakpoints'] !== []
                ? 'Responsive keys: <key>_' . implode(', <key>_', array_map(static fn (string $breakpoint): string => C::oneLine($breakpoint, 20), $widget['breakpoints'])) . '.'
                : '',
        ], static fn (string $line): bool => $line !== ''));
        $lines = [];
        foreach ($widget['controls'] as $control) {
            array_push($lines, ...self::controlLine($control));
        }

        $text = C::finish(
            [
                C::plural(count($widget['controls']), 'control') . ($tab === null ? '' : ' on the ' . $tab . ' tab')
                    . '. Repeater fields are listed under their repeater; give each item its fields (an _id is added).',
                C::siteData('element and its controls', implode("\n", array_merge([$about], $lines))),
            ],
            $widget['kind'] === 'widget' ? 'Use it as {"elType": "widget", "widgetType": <its name>, "settings": {…}} inside a container or column.' : null,
            'Ask for one tab at a time (tab: content, style, advanced or layout).'
        );

        return ['text' => $text, 'structured' => $structured];
    }
}
