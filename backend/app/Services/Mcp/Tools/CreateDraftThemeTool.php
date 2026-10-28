<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * create_draft_theme → POST theme-draft (cloud createDraftTheme.ts).
 */
final class CreateDraftThemeTool extends Tool
{
    public function run(\stdClass $args): ToolResult // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This tool takes no arguments.
    {
        return self::out(self::format($this->siteId, $this->client->createThemeDraft()['data']));
    }

    /**
     * @param array<string, mixed> $draft
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, array $draft): array
    {
        $structured = [
            'site_id'       => $siteId,
            'draft_id'      => $draft['id'],
            'slug'          => C::oneLine($draft['slug'], 200),
            'folder'        => C::oneLine($draft['folder'], 300),
            'source_theme'  => C::oneLine($draft['source']['stylesheet'], 200),
            'parent_theme'  => $draft['parent'] === null ? null : C::oneLine($draft['parent'], 200),
            'files_copied'  => $draft['copied']['files'],
            'links_skipped' => $draft['copied']['skipped'],
        ];
        $details    = [
            'Folder: ' . $structured['folder'],
            'Copied from: ' . $structured['source_theme'] . ' (' . C::oneLine($draft['source']['name'], 200) . '), '
                . $draft['copied']['files'] . ' files, ' . ThemeTools::kb($draft['copied']['bytes']),
        ];

        if ($structured['parent_theme'] !== null) {
            $details[] = 'Child theme of: ' . $structured['parent_theme'] . ' (shared with the live site, not copied)';
        }

        if ($draft['copied']['skipped'] > 0) {
            $details[] = 'Symbolic links skipped: ' . $draft['copied']['skipped'];
        }

        return [
            'text'       => C::finish(
                ['Created draft theme ' . $draft['id'] . '. The live site is unchanged.', C::siteData('new draft theme', implode("\n", $details))],
                'Use draft_id "' . $draft['id'] . '": list_theme_files to see its files, read_theme_file before changing one, '
                    . 'then edit_theme_file or write_theme_file, and get_preview_url for the user to review.'
            ),
            'structured' => $structured,
        ];
    }
}
