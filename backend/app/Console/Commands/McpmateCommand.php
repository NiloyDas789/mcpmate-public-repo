<?php

namespace MCPMate\Console\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Console\Console;
use MCPMate\Console\ConsoleException;
use MCPMate\HTTP\ErrorPresenter;

/**
 * Control MCPMate from the server: AI access, read mode, connected AI apps, tools and the audit log.
 *
 * Every command is audited with auth "cli", as the WordPress user given with --user (or none).
 *
 * ## EXAMPLES
 *
 *     # Stop every AI app at once, then let them back in
 *     $ wp mcpmate pause
 *     $ wp mcpmate resume
 *
 *     # Let AI apps read the site but change nothing
 *     $ wp mcpmate read-mode on --user=admin
 */
final class McpmateCommand
{
    /**
     * Show whether AI access is paused or in read mode, and what is connected.
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
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate status
     *
     * @param string[]             $args
     * @param array<string, mixed> $assoc
     */
    public function status(array $args, array $assoc): void
    {
        $status = Console::status();
        $format = (string) ($assoc['format'] ?? 'table');

        if ($format !== 'table') {
            Output::items([$status], array_keys($status), $format);
            return;
        }

        $rows = [];
        foreach ($status as $key => $value) {
            $rows[] = ['field' => $key, 'value' => Output::scalar($value)];
        }

        Output::items($rows, ['field', 'value'], 'table');
    }

    /**
     * Pause AI access: every connected AI app gets an error until you resume. Nothing is revoked.
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate pause
     *
     */
    public function pause(): void
    {
        if (Console::pause()) {
            \WP_CLI::success(__('AI access paused.', 'mcpmate'));
            return;
        }

        \WP_CLI::warning(__('AI access was already paused.', 'mcpmate'));
    }

    /**
     * Resume AI access after a pause.
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate resume
     *
     */
    public function resume(): void
    {
        if (Console::resume()) {
            \WP_CLI::success(__('AI access resumed.', 'mcpmate'));
            return;
        }

        \WP_CLI::warning(__('AI access was not paused.', 'mcpmate'));
    }

    /**
     * Turn read mode on or off, or show it. While it is on, every AI app may read the site only, and approval requests cannot be approved.
     *
     * ## OPTIONS
     *
     * <action>
     * : What to do.
     * ---
     * options:
     *   - on
     *   - off
     *   - status
     * ---
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate read-mode on
     *     $ wp mcpmate read-mode status
     *
     * @subcommand read-mode
     *
     * @param string[] $args
     */
    public function readMode(array $args): void
    {
        $action = (string) ($args[0] ?? '');

        try {
            $result = Console::readMode($action);
        } catch (ConsoleException $e) {
            \WP_CLI::error(ErrorPresenter::plain($e));
        }

        $state = $result['read_mode'];

        if ($action === 'status') {
            \WP_CLI::line($state['on']
                ? sprintf(
                    /* translators: 1: when (ISO 8601), 2: who. */
                    __('Read mode is on (since %1$s, by %2$s).', 'mcpmate'),
                    (string) ($state['at'] ?? '?'),
                    (string) ($state['by']['name'] ?? __('an unknown user', 'mcpmate'))
                )
                : __('Read mode is off.', 'mcpmate'));
            return;
        }

        if (!$result['changed']) {
            \WP_CLI::warning($action === 'on' ? __('Read mode was already on.', 'mcpmate') : __('Read mode was already off.', 'mcpmate'));
            return;
        }

        \WP_CLI::success($action === 'on'
            ? __('Read mode is on. AI apps can read the site but not change it.', 'mcpmate')
            : __('Read mode is off.', 'mcpmate'));
    }
}
