<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * list_theme_files → GET theme-draft/{id}/files (cloud listThemeFiles.ts).
 */
final class ListThemeFilesTool extends Tool
{
    /** Entries per page; the route sends up to 2000 at once. */
    private const ENTRIES_PER_PAGE = 200;

    /** A page also stops at this much structured JSON, so long paths cannot push it past the cap. */
    private const PAGE_BYTES = 15000;

    public function run(\stdClass $args): ToolResult
    {
        $problem = ThemeTools::pathProblem($args->path, 'folder');
        if ($problem !== null) {
            return ToolResult::invalidInput($problem);
        }

        $reply = $this->client->themeFiles($args->draft_id, $args->path, $args->recursive);

        return self::out(self::format($this->siteId, $args->draft_id, $reply['data'], $args->offset));
    }

    /**
     * @param array<string, mixed> $files
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $draftId, array $files, int $offset): array
    {
        $page  = [];
        $bytes = 0;

        foreach (array_slice($files['entries'], $offset, self::ENTRIES_PER_PAGE) as $entry) {
            $item   = ['path' => C::oneLine($entry['path'], 300), 'type' => $entry['type'], 'size' => $entry['size'], 'editable' => $entry['editable']];
            $bytes += strlen(Js::stringify($item)) + 1;

            if ($bytes > self::PAGE_BYTES && $page !== []) {
                break;
            }

            $page[] = $item;
        }

        $total      = count($files['entries']);
        $nextOffset = $offset + count($page) < $total ? $offset + count($page) : null;
        $structured = [
            'site_id'     => $siteId,
            'draft_id'    => $draftId,
            'path'        => C::oneLine($files['path'], 300),
            'total'       => $total,
            'entries'     => $page,
            'next_offset' => $nextOffset,
            'truncated'   => $files['truncated'],
        ];

        $where  = $structured['path'] === '' ? 'the draft' : '"' . $structured['path'] . '"';
        $header = $total === 0
            ? 'No files in ' . $where . '.'
            : $total . ($files['truncated'] ? '+' : '') . ' entries in ' . $where . ($total > count($page) ? ', showing ' . ($offset + 1) . '–' . ($offset + count($page)) : '') . ':';
        $lines  = array_map(
            static fn (array $entry): string => $entry['type'] === 'dir'
                ? $entry['path'] . '/'
                : $entry['path'] . '  ' . ThemeTools::kb($entry['size'] ?? 0) . ($entry['editable'] ? '' : '  (not editable)'),
            $page
        );

        if ($nextOffset !== null) {
            $next = 'More files: call again with offset=' . $nextOffset . ', or give path to list one folder.';
        } elseif ($files['truncated']) {
            $next = 'The plugin lists at most 2000 entries: give path to list one folder at a time.';
        } else {
            $next = 'Read a file with read_theme_file before changing it.';
        }

        return [
            'text'       => C::finish(array_merge([$header], $page === [] ? [] : [C::siteData('draft theme files', implode("\n", $lines))]), $next, 'Give path to list one folder.'),
            'structured' => $structured,
        ];
    }
}
