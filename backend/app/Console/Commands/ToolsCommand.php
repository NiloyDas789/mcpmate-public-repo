<?php

namespace MCPMate\Console\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Console\Console;
use MCPMate\Console\ConsoleException;
use MCPMate\HTTP\ErrorPresenter;

/**
 * List MCPMate's tools and turn them on or off for every AI app.
 */
final class ToolsCommand
{
    private const FIELDS = ['name', 'group', 'kind', 'approval', 'enabled', 'available'];

    /**
     * List every tool, in the Tools page's order.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate tools list
     *
     * @subcommand list
     *
     * @param string[]             $args
     * @param array<string, mixed> $assoc
     */
    public function listTools(array $args, array $assoc): void
    {
        Output::items(Console::tools(), self::FIELDS, (string) ($assoc['format'] ?? 'table'));
    }

    /**
     * Turn a tool on for every AI app whose access includes it.
     *
     * ## OPTIONS
     *
     * <name>
     * : The tool name, from "wp mcpmate tools list".
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate tools enable get_audit_log
     *
     * @param string[] $args
     */
    public function enable(array $args): void
    {
        $this->set((string) ($args[0] ?? ''), true);
    }

    /**
     * Turn a tool off for every AI app.
     *
     * ## OPTIONS
     *
     * <name>
     * : The tool name, from "wp mcpmate tools list".
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate tools disable run_wp_cli
     *
     * @param string[] $args
     */
    public function disable(array $args): void
    {
        $this->set((string) ($args[0] ?? ''), false);
    }

    private function set(string $name, bool $enabled): void
    {
        try {
            $changed = Console::setTool($name, $enabled);
        } catch (ConsoleException $e) {
            \WP_CLI::error(ErrorPresenter::plain($e));
        }

        if (!$changed) {
            /* translators: %s: tool name. */
            \WP_CLI::warning(sprintf($enabled ? __('%s was already on.', 'mcpmate') : __('%s was already off.', 'mcpmate'), $name));
            return;
        }

        /* translators: %s: tool name. */
        \WP_CLI::success(sprintf($enabled ? __('%s is on.', 'mcpmate') : __('%s is off.', 'mcpmate'), $name));
    }
}
