<?php

namespace MCPMate\Console;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Console\Commands\AuditCommand;
use MCPMate\Console\Commands\GrantsCommand;
use MCPMate\Console\Commands\McpmateCommand;
use MCPMate\Console\Commands\ToolsCommand;

/**
 * Adds `wp mcpmate` (and its `grants`, `tools` and `audit` groups) while
 * WP-CLI runs; never during a web request. MCPMate Pro adds `wp mcpmate
 * license` the same way.
 */
final class Registrar
{
    public static function register(): void
    {
        if (!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command('mcpmate', McpmateCommand::class);
        \WP_CLI::add_command('mcpmate grants', GrantsCommand::class);
        \WP_CLI::add_command('mcpmate tools', ToolsCommand::class);
        \WP_CLI::add_command('mcpmate audit', AuditCommand::class);
    }
}
