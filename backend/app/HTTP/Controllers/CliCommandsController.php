<?php

namespace McpMate\HTTP\Controllers;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\HTTP\Request\Request;
use McpMate\HTTP\Response;
use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\CommandSettings;

/**
 * Admin settings: which CLI commands POST /cli may run.
 *
 * AJAX routes (nonce + admin), used by the Settings page.
 */
final class CliCommandsController
{
    public function index(Request $request): Response
    {
        return Response::success(['commands' => self::commands(CommandRegistry::defaults())]);
    }

    public function update(Request $request): Response
    {
        $enabled = $request->get('enabled', []);

        // FormData sends arrays as JSON strings.
        if (is_string($enabled)) {
            $enabled = json_decode($enabled, true);
        }

        if (!is_array($enabled)) {
            return Response::error(__('The list of enabled commands is invalid.', 'mcpmate'), 400, 'mcpmate_invalid_commands');
        }

        $registry = CommandRegistry::defaults();
        CommandSettings::update(array_map('sanitize_text_field', array_filter($enabled, 'is_string')), $registry);

        return Response::success([
            'message'  => __('Command settings saved.', 'mcpmate'),
            'commands' => self::commands($registry),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function commands(CommandRegistry $registry): array
    {
        $enabled = CommandSettings::enabled();

        return array_values(array_map(
            static fn (Command $command) => $command->describe() + ['enabled' => in_array($command->name(), $enabled, true)],
            $registry->all()
        ));
    }
}
