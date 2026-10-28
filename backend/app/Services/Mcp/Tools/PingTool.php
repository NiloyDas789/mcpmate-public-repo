<?php

namespace McpMate\Services\Mcp\Tools;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Services\Mcp\Js;
use McpMate\Services\Mcp\ToolResult;

/**
 * ping: the plugin version and the time (cloud ping.ts). Calls nothing.
 */
final class PingTool extends Tool
{
    public function run(\stdClass $args): ToolResult // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- This tool takes no arguments.
    {
        $now    = microtime(true);
        $output = [
            'version' => Config::VERSION,
            'time'    => gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000)),
        ];

        return ToolResult::output(Js::stringify($output), $output);
    }
}
