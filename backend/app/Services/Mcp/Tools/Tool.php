<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Services\Mcp\InternalClient;
use McpMate\Services\Mcp\ToolResult;

/**
 * One MCP tool's handler (docs/mcp-in-plugin.md §7.2). Its name,
 * description and schemas come from docs/mcp-tools.json (ToolRegistry);
 * the class supplies the call and the formatter, ported from the cloud's
 * src/mcp/tools/<name>.ts. McpEndpoint has already validated the
 * arguments (defaults filled in), run the policy layer, and turns a
 * SiteError thrown here into the tool error with its next step.
 */
abstract class Tool
{
    protected InternalClient $client;

    /** This site's id in tool output (McpSite::id()). */
    protected string $siteId;

    /** The home URL, without a trailing slash: links people open are built from it. */
    protected string $siteUrl;

    public function __construct(InternalClient $client, string $siteId, string $siteUrl)
    {
        $this->client  = $client;
        $this->siteId  = $siteId;
        $this->siteUrl = $siteUrl;
    }

    abstract public function run(\stdClass $args): ToolResult;

    /**
     * @param array{text: string, structured: mixed} $formatted
     */
    protected static function out(array $formatted): ToolResult
    {
        return ToolResult::output($formatted['text'], $formatted['structured']);
    }
}
