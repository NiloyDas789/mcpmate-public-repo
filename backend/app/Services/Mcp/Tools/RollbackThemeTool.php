<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\ToolResult;

/**
 * rollback_theme → POST theme-draft/rollback (cloud rollbackTheme.ts).
 * The route always holds it for approval.
 */
final class RollbackThemeTool extends Tool
{
    public function run(\stdClass $args): ToolResult
    {
        $reply = $this->client->rollbackTheme($args->backup_id ?? null, $args->dry_run);

        return self::out(ThemeTools::formatChange($this->siteId, $this->siteUrl, $reply['data']));
    }
}
