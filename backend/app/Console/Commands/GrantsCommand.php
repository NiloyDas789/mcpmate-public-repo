<?php

namespace MCPMate\Console\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Console\Console;
use MCPMate\Console\ConsoleException;
use MCPMate\HTTP\ErrorPresenter;

/**
 * List connected AI apps (grants) and revoke them.
 */
final class GrantsCommand
{
    private const FIELDS = ['grant_id', 'client_name', 'user', 'access', 'status', 'created_at', 'last_used_at'];

    /**
     * List every AI app approved on this site, live ones first.
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
     *     $ wp mcpmate grants list
     *
     * @subcommand list
     *
     * @param string[]             $args
     * @param array<string, mixed> $assoc
     */
    public function listGrants(array $args, array $assoc): void
    {
        Output::items(Console::grants(), self::FIELDS, (string) ($assoc['format'] ?? 'table'));
    }

    /**
     * Revoke one AI app's access. Its tokens stop working at once; to come back it must be approved again in wp-admin.
     *
     * ## OPTIONS
     *
     * <grant-id>
     * : The grant ID, from "wp mcpmate grants list".
     *
     * ## EXAMPLES
     *
     *     $ wp mcpmate grants revoke 3f2a9c…
     *
     * @param string[] $args
     */
    public function revoke(array $args): void
    {
        try {
            $grant = Console::revokeGrant((string) ($args[0] ?? ''));
        } catch (ConsoleException $e) {
            \WP_CLI::error(ErrorPresenter::plain($e));
        }

        \WP_CLI::success(sprintf(
            /* translators: %s: AI app name. */
            __('Revoked %s.', 'mcpmate'),
            (string) $grant['client_name']
        ));
    }
}
