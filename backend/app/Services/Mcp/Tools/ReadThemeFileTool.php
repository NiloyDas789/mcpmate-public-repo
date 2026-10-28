<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;

/**
 * read_theme_file → GET theme-draft/{id}/file (cloud readThemeFile.ts).
 */
final class ReadThemeFileTool extends Tool
{
    /**
     * Content per response: whole lines up to the budget, once in the text
     * and once in structured content; the next page starts where it stopped.
     */
    private const CONTENT_BYTES = 14000;

    public function run(\stdClass $args): ToolResult
    {
        $problem = ThemeTools::pathProblem($args->path, 'file');
        if ($problem !== null) {
            return ToolResult::invalidInput($problem);
        }

        $reply = $this->client->readThemeFile($args->draft_id, $args->path, $args->line);

        return self::out(self::format($this->siteId, $args->draft_id, $reply['data']));
    }

    /**
     * @param array<string, mixed> $file
     * @return array{text: string, structured: array<string, mixed>}
     */
    public static function format(string $siteId, string $draftId, array $file): array
    {
        $lines   = $file['content'] === '' ? [] : (array) preg_split('/(?<=\n)/', $file['content'], -1, PREG_SPLIT_NO_EMPTY);
        $content = '';
        $taken   = 0;
        $clipped = $file['clipped'];

        foreach ($lines as $line) {
            if (strlen($content) + strlen((string) $line) > self::CONTENT_BYTES) {
                if ($taken === 0) {
                    $content = C::clipBytes((string) $line, self::CONTENT_BYTES);
                    $taken   = 1;
                    $clipped = true;
                }
                break;
            }

            $content .= $line;
            $taken++;
        }

        $endLine    = $taken === 0 ? $file['start_line'] - 1 : $file['start_line'] + $taken - 1;
        $nextLine   = $endLine < $file['total_lines'] ? $endLine + 1 : null;
        $path       = C::oneLine($file['path'], 300);
        $structured = [
            'site_id'     => $siteId,
            'draft_id'    => $draftId,
            'path'        => $path,
            'size'        => $file['size'],
            'sha256'      => $file['sha256'],
            'total_lines' => $file['total_lines'],
            'start_line'  => $file['start_line'],
            'end_line'    => $endLine,
            'next_line'   => $nextLine,
            'clipped'     => $clipped,
            'content'     => $content,
        ];

        $range = $file['total_lines'] === 0 ? 'empty file' : 'lines ' . $file['start_line'] . '–' . $endLine . ' of ' . $file['total_lines'];

        if ($nextLine !== null) {
            $next = 'The file continues: call read_theme_file with line=' . $nextLine . '.';
        } elseif ($clipped) {
            $next = 'A line was too long to show whole. To change it, replace the whole file with write_theme_file.';
        } else {
            $next = 'To change it, use edit_theme_file with text copied exactly from here, or write_theme_file with the whole new file.';
        }

        return [
            'text'       => C::finish(
                [$path . ' (' . ThemeTools::kb($file['size']) . ', ' . $range . ($clipped ? ', a long line was cut' : '') . '):', C::siteData('theme file ' . $path, $content)],
                $next
            ),
            'structured' => $structured,
        ];
    }
}
