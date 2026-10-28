<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * write_theme_file → the block markup gate for `.html`, then PUT
 * theme-draft/{id}/file (cloud writeThemeFile.ts).
 */
final class WriteThemeFileTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $problem = ThemeTools::pathProblem($args->path, 'file');
        if ($problem !== null) {
            return ToolResult::invalidInput($problem);
        }

        if (strlen($args->content) > ThemeTools::MAX_FILE_BYTES) {
            return ToolResult::invalidInput('The file is larger than 512 KB.');
        }

        if (str_ends_with($args->path, '.html') && BlockMarkup::has($args->content)) {
            $refused = BlockMarkup::refuseInvalid([['label' => $args->path, 'content' => $args->content]]);

            if ($refused !== null) {
                return $refused;
            }
        }

        $reply = $this->client->writeThemeFile($args->draft_id, $args->path, $args->content);

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
            'size'     => $saved['size'],
            'sha256'   => $saved['sha256'],
            'created'  => $saved['created'] ?? false,
        ];

        return [
            'text'       => C::finish(
                [($structured['created'] ? 'Created' : 'Replaced') . ' ' . $structured['path'] . ' in draft ' . $draftId . ' (' . ThemeTools::kb($saved['size']) . '). The live site is unchanged.'],
                'When the changes are ready, call get_preview_url and give the user the link to review them.'
            ),
            'structured' => $structured,
        ];
    }
}
