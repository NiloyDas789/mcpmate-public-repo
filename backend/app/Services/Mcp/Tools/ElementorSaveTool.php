<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\InternalClient;
use McpMate\Services\Mcp\ToolResult;

/**
 * elementor_save → POST elementor/{post_id} (change) or POST elementor
 * (create) (cloud elementorSave.ts).
 */
final class ElementorSaveTool extends Tool
{
    private const ISSUES_STRUCTURED_BYTES = 8000;

    public function run(\stdClass $args): ToolResult
    {
        $write = self::writeOf($args);

        if (isset($write['problem'])) {
            return ToolResult::invalidInput($write['problem'], $write['hint']);
        }

        $postId = $write['post_id'];
        $reply  = $this->client->elementorWrite($postId, $write['body'], $args->dry_run);
        $data   = ['dry_run' => $args->dry_run, 'created' => $postId === null] + $reply['data'];

        return self::out(self::format($this->siteId, $this->siteUrl, $data));
    }

    /**
     * The request as the route takes it, or why the arguments do not make one.
     *
     * @return array{post_id: int|null, body: array<string, mixed>}|array{problem: string, hint: string}
     */
    public static function writeOf(\stdClass $args): array
    {
        $has = static fn (string $key): bool => property_exists($args, $key);

        if ($has('elements')) {
            $problem = ElementorTools::treeProblem($args->elements);
            if ($problem !== null) {
                return ['problem' => $problem, 'hint' => 'elementor_widgets lists the element types and widgets this site has.'];
            }
        }

        foreach ($args->patches ?? [] as $index => $patch) {
            if ($patch->op !== 'insert') {
                continue;
            }

            $problem = ElementorTools::treeProblem([$patch->element], 'patches[' . $index . '].element');
            if ($problem !== null) {
                return [
                    'problem' => str_replace('patches[' . $index . '].element[0]', 'patches[' . $index . '].element', $problem),
                    'hint'    => 'The inserted element has the same shape as an element in elements.',
                ];
            }
        }

        if ($has('elements') && $has('patches')) {
            return ['problem' => 'Send either elements or patches, not both.', 'hint' => 'elements replaces the whole tree; patches change parts of it.'];
        }

        $shared = ['elements' => $args->elements ?? null, 'page_settings' => $args->page_settings ?? null, 'dry_run' => $args->dry_run];

        if ($has('post_id')) {
            if ($has('post_type') || $has('copy_from')) {
                return ['problem' => 'post_type and copy_from are only for creating a page.', 'hint' => 'Leave out post_id to create a new draft.'];
            }

            if (!$has('elements') && !$has('patches') && !$has('title') && !$has('page_settings')) {
                return ['problem' => 'Nothing to save.', 'hint' => 'Send elements, patches, title or page_settings.'];
            }

            return [
                'post_id' => (int) $args->post_id,
                'body'    => InternalClient::defined($shared + ['patches' => $args->patches ?? null, 'title' => $args->title ?? null, 'expected_version' => $args->expected_version ?? null]),
            ];
        }

        if (!$has('title')) {
            return ['problem' => 'A new page needs a title.', 'hint' => 'Send title, or post_id to change an existing page.'];
        }

        if ($has('patches') || $has('expected_version')) {
            return ['problem' => 'patches and expected_version are only for changing an existing page.', 'hint' => 'Send post_id with them, or elements to build a new page.'];
        }

        if ($has('elements') && $has('copy_from')) {
            return ['problem' => 'Send either elements or copy_from, not both.', 'hint' => 'Copy the page first, then change the copy with patches.'];
        }

        return [
            'post_id' => null,
            'body'    => InternalClient::defined($shared + ['title' => $args->title, 'post_type' => $args->post_type ?? 'page', 'copy_from' => $args->copy_from ?? null]),
        ];
    }

    /**
     * @param array<string, mixed> $outcome The route's answer, `dry_run` and `created`.
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $siteUrl, array $outcome): array
    {
        if ($outcome['dry_run']) {
            $structured = [
                'site_id'       => $siteId,
                'status'        => 'dry_run',
                'valid'         => $outcome['valid'],
                'post_id'       => null,
                'title'         => null,
                'post_status'   => null,
                'version'       => null,
                'element_count' => $outcome['element_count'],
                'css'           => null,
                'edit_url'      => null,
                'preview_url'   => null,
                'error_count'   => $outcome['error_count'],
                'warning_count' => $outcome['warning_count'],
                'errors'        => C::fit($outcome['errors'], self::ISSUES_STRUCTURED_BYTES),
                'warnings'      => C::fit($outcome['warnings'], self::ISSUES_STRUCTURED_BYTES),
            ];
            $lines      = [
                $outcome['valid']
                    ? 'Valid: ' . C::plural($outcome['element_count'], 'element') . ', no errors'
                        . ($outcome['warning_count'] > 0 ? ', ' . C::plural($outcome['warning_count'], 'warning') : '') . '. Nothing was saved (dry run).'
                    : 'Not valid: ' . C::plural($outcome['error_count'], 'error') . ' and ' . C::plural($outcome['warning_count'], 'warning')
                        . ' in ' . C::plural($outcome['element_count'], 'element') . '. Nothing was saved.',
            ];
            $issues     = array_merge(
                $outcome['error_count'] > 0 ? array_merge(['Errors:'], ElementorTools::issueLines($outcome['errors'], $outcome['error_count'], 'errors')) : [],
                $outcome['warning_count'] > 0 ? array_merge(['Warnings:'], ElementorTools::issueLines($outcome['warnings'], $outcome['warning_count'], 'warnings')) : []
            );

            if ($issues !== []) {
                $lines[] = C::siteData('errors and warnings', implode("\n", $issues));
            }

            $next = $outcome['valid']
                ? 'Call elementor_save again without dry_run to save it.'
                : 'Fix every error (each names the element path, id and setting), then check again. elementor_widgets {name} shows a widget’s controls and allowed values.';

            return ['text' => C::finish($lines, $next, 'Check a smaller tree.'), 'structured' => $structured];
        }

        $editUrl    = ElementorTools::editorLink($siteUrl, $outcome['post_id']);
        $previewUrl = ElementorTools::previewLink($siteUrl, $outcome['post_id'], $outcome['post_type']);
        $structured = [
            'site_id'       => $siteId,
            'status'        => $outcome['created'] ? 'created' : 'saved',
            'valid'         => true,
            'post_id'       => $outcome['post_id'],
            'title'         => C::oneLine($outcome['title'], 200),
            'post_status'   => $outcome['status'],
            'version'       => $outcome['version'],
            'element_count' => $outcome['element_count'],
            'css'           => $outcome['css']['status'],
            'edit_url'      => $editUrl,
            'preview_url'   => $previewUrl,
            'error_count'   => 0,
            'warning_count' => $outcome['warning_count'],
            'errors'        => [],
            'warnings'      => C::fit($outcome['warnings'], self::ISSUES_STRUCTURED_BYTES),
        ];

        $verb   = ($outcome['created'] ? 'Created ' : 'Saved ') . $outcome['status'] . ' ' . $outcome['post_type'];
        $copied = isset($outcome['copied_from']) ? ' (a copy of #' . $outcome['copied_from'] . ')' : '';
        $css    = $outcome['css']['status'] === 'pending' ? 'Elementor will build its CSS on the first view.' : 'CSS regenerated (' . C::oneLine($outcome['css']['status'], 20) . ').';
        $warned = $outcome['warning_count'] > 0;
        $lines  = [
            $verb . ' #' . $outcome['post_id'] . $copied . ': ' . C::plural($outcome['element_count'], 'element') . ', version ' . $outcome['version'] . '. ' . $css,
            'Elementor editor: ' . $editUrl,
            'Preview (for a signed-in user): ' . $previewUrl,
            C::siteData(
                $warned ? 'page title and warnings' : 'page title',
                implode("\n", array_merge(
                    ['title: ' . C::oneLine($outcome['title'], 200)],
                    $warned ? array_merge(['Warnings:'], ElementorTools::issueLines($outcome['warnings'], $outcome['warning_count'], 'warnings')) : []
                ))
            ),
        ];

        $next = 'Give the user the editor link to review the draft; publishing is their step. To change it again, use patches with post_id ' . $outcome['post_id']
            . ' and expected_version "' . $outcome['version'] . '".'
            . ($warned ? ' Check whether the warnings (settings Elementor ignores) are intended.' : '');

        return ['text' => C::finish($lines, $next), 'structured' => $structured];
    }
}
