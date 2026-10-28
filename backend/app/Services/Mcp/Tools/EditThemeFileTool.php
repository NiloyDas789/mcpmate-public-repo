<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * edit_theme_file → POST theme-draft/{id}/file/replace (cloud editThemeFile.ts).
 */
final class EditThemeFileTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $problem = ThemeTools::pathProblem($args->path, 'file');
        if ($problem !== null) {
            return ToolResult::invalidInput($problem);
        }

        if ($args->find === $args->replace) {
            return ToolResult::invalidInput('find and replace are the same; nothing would change.');
        }

        $reply = $this->client->editThemeFile($args->draft_id, $args->path, $args->find, $args->replace);

        return self::out(self::format($this->siteId, $args->draft_id, $reply['data']));
    }

    /**
     * @param array<string, mixed> $saved
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $draftId, array $saved): array
    {
        $structured = [
            'site_id'  => $siteId,
            'draft_id' => $draftId,
            'path'     => C::oneLine($saved['path'], 300),
            'line'     => $saved['line'] ?? null,
            'size'     => $saved['size'],
            'sha256'   => $saved['sha256'],
        ];
        $where      = $structured['line'] === null ? '' : ' at line ' . $structured['line'];

        return [
            'text'       => C::finish(
                ['Edited ' . $structured['path'] . $where . ' in draft ' . $draftId . ' (now ' . ThemeTools::kb($saved['size']) . '). The live site is unchanged.'],
                'When the changes are ready, call get_preview_url and give the user the link to review them.'
            ),
            'structured' => $structured,
        ];
    }
}
