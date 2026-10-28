<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\Compact as C;
use McpMate\Services\Mcp\ToolResult;
use McpMate\Services\Mcp\Url;

/**
 * get_preview_url → POST theme-draft/{id}/preview (cloud getPreviewUrl.ts).
 */
final class GetPreviewUrlTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $reply     = $this->client->themePreview($args->draft_id, $args->path, $args->expires_in);
        $formatted = self::format($this->siteId, $this->siteUrl, $args->draft_id, $reply['data']);

        if ($formatted === null) {
            return ToolResult::error(
                'invalid_response',
                'The site returned a preview link on another host, so it was not used.',
                'The link must be on the site’s own address.',
                'Do not retry. Ask the user to check the site’s WordPress Address and Site Address settings, then have a site admin reconnect it under McpMate → Connection.'
            );
        }

        return self::out($formatted);
    }

    /**
     * The preview link carries its token, so it cannot be rebuilt like an
     * approval link: it is shown only when it is on the site's own host,
     * over https or the site's own scheme. Otherwise null.
     *
     * @param array<string, mixed> $preview
     * @return array{text: string, structured: array<string, mixed>}|null
     */
    public static function format(string $siteId, string $siteUrl, string $draftId, array $preview): ?array
    {
        $site = Url::parse($siteUrl);
        $link = Url::parse($preview['url']);

        if ($site === null || $link === null || $link['host'] !== $site['host'] || ($link['protocol'] !== 'https:' && $link['protocol'] !== $site['protocol'])) {
            return null;
        }

        $structured = ['site_id' => $siteId, 'draft_id' => $draftId, 'url' => $link['href'], 'expires_at' => $preview['expires_at'], 'expires_in' => $preview['expires_in']];

        return [
            'text'       => C::finish(
                [
                    'Preview of draft ' . $draftId . ' (' . C::oneLine($preview['draft']['slug'], 200) . '), valid until ' . $preview['expires_at'] . ':',
                    $link['href'],
                    'Only this link shows the draft. Do not post it anywhere public.',
                ],
                'Give the link to the user and ask them to review the page. Publish only when they explicitly ask: then call publish_draft_theme.'
            ),
            'structured' => $structured,
        ];
    }
}
