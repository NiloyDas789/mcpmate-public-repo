<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\ToolResult;

/**
 * publish_draft_theme → POST theme-draft/publish (cloud
 * publishDraftTheme.ts). The route always holds it for approval.
 */
final class PublishDraftThemeTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $reply = $this->client->publishThemeDraft($args->draft_id, $args->dry_run);

        return self::out(ThemeTools::formatChange($this->siteId, $this->siteUrl, $reply['data']));
    }
}
