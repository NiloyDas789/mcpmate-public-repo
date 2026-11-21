<?php

namespace MCPMate\Console\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Console\Console;
use MCPMate\Console\ConsoleException;
use MCPMate\HTTP\ErrorPresenter;

/**
 * Read MCPMate's audit log.
 */
final class AuditCommand
{
    private const FIELDS = ['id', 'created_at', 'tool', 'action', 'result', 'auth', 'client_name', 'mcp_tool', 'target'];

    /**
     * Show the newest audit log entries, oldest of them first.
     *
     * ## OPTIONS
     *
     * [--n=<count>]
     * : How many entries, up to 100.
     * ---
     * default: 20
     * ---
     *
     * [--grant=<grant-id>]
     * : Only this AI app's entries.
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
     *     $ wp mcpmate audit tail --n=50
     *
     * @param string[]             $args
     * @param array<string, mixed> $assoc
     */
    public function tail(array $args, array $assoc): void
    {
        try {
            $rows = Console::auditTail(absint($assoc['n'] ?? 20), (string) ($assoc['grant'] ?? ''));
        } catch (ConsoleException $e) {
            \WP_CLI::error(ErrorPresenter::plain($e));
        }

        Output::items($rows, self::FIELDS, (string) ($assoc['format'] ?? 'table'));
    }
}
