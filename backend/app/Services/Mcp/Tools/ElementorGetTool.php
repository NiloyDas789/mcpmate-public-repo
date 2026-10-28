<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * elementor_get → GET elementor/{post_id} (cloud elementorGet.ts).
 */
final class ElementorGetTool extends Tool
{
    private const OUTLINE_STRUCTURED_BYTES = 8000;

    private const ELEMENT_TEXT_BYTES = 14000;

    private const ELEMENT_STRUCTURED_BYTES = 8000;

    public function run(\stdClass $args): ToolResult
    {
        $elementId = $args->element_id ?? null;
        $reply     = $this->client->elementorPage($args->post_id, $elementId);

        return self::out(self::format($this->siteId, $this->siteUrl, $reply['data'], $elementId, $reply['truncated']));
    }

    /**
     * @param array<string, mixed> $page
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $siteUrl, array $page, ?string $elementId = null, bool $truncated = false): array
    {
        $outline    = ElementorTools::outline($page['elements']);
        $editUrl    = ElementorTools::editorLink($siteUrl, $page['post_id']);
        $previewUrl = ElementorTools::previewLink($siteUrl, $page['post_id'], $page['post_type']);
        $element    = $elementId === null ? null : ($page['elements'][0] ?? null);

        $structured = [
            'site_id'            => $siteId,
            'post_id'            => $page['post_id'],
            'title'              => C::oneLine($page['title'], 200),
            'post_type'          => $page['post_type'],
            'status'             => $page['status'],
            'editable'           => $page['editable'],
            'template'           => $page['template'],
            'version'            => $page['version'],
            'element_count'      => $page['element_count'],
            'edit_url'           => $editUrl,
            'preview_url'        => $previewUrl,
            'page_settings'      => C::fit($page['page_settings'], 1000),
            'truncated_settings' => C::fit($page['truncated_settings'], 1000),
            'outline'            => C::fit($outline['entries'], self::OUTLINE_STRUCTURED_BYTES),
            'element'            => $element === null ? null : C::fit($element, self::ELEMENT_STRUCTURED_BYTES),
        ];

        $head  = [
            '#' . $page['post_id'] . ' ' . $page['post_type'] . ', ' . $page['status'] . ($page['editable'] ? '' : ' (not editable: copy it into a draft with elementor_save {copy_from})') . ', '
                . C::plural($page['element_count'], 'element') . ', template ' . $page['template'] . ', version ' . $page['version'] . ' (Elementor ' . $page['elementor_version'] . ').',
            'Editor: ' . $editUrl,
        ];
        $title = 'title: ' . C::oneLine($page['title'], 200);

        if ($element !== null) {
            $text = C::finish(
                array_merge($head, [C::siteData('element ' . $elementId, implode("\n", array_merge([$title], C::render(C::fit($element, self::ELEMENT_TEXT_BYTES)))))]),
                'Change it with elementor_save {post_id: ' . $page['post_id'] . ', expected_version: "' . $page['version'] . '", patches: [{op: "update", id, settings}]}.',
                'Read one of its children with element_id instead.'
            );

            return ['text' => $text, 'structured' => $structured];
        }

        $content = [$title];
        if (Js::size($page['page_settings']) > 0) {
            $content[] = 'page settings: ' . implode(' ', C::render(C::fit($page['page_settings'], 1000)));
        }
        $content = array_merge($content, $page['elements'] === [] ? ['(no elements yet)'] : $outline['lines']);

        $lines = array_merge($head, [C::siteData('page title, settings and element outline', implode("\n", $content))]);
        if ($page['truncated_settings'] !== []) {
            $lines[] = C::plural(count($page['truncated_settings']), 'long setting') . ' shortened ([long text, …]); read the element with element_id to see it in full.';
        }

        if ($truncated) {
            $next = 'The site cut this page to fit its response limit: read parts of it with element_id.';
        } elseif ($page['editable']) {
            $next = 'Edit with elementor_save {post_id: ' . $page['post_id'] . ', expected_version: "' . $page['version'] . '", patches: […]}; read one element in full with element_id.';
        } else {
            $next = 'Published pages are not changed: elementor_save {title, copy_from: ' . $page['post_id'] . '} copies it into a new draft to edit.';
        }

        return ['text' => C::finish($lines, $next, 'Read parts of the page with element_id.'), 'structured' => $structured];
    }
}
