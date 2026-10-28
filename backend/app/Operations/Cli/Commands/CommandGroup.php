<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\CommandException;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Utils\Capabilities;

/**
 * Shared helpers for the command handler classes. Each subclass registers
 * its commands in register() and implements them as static handlers with
 * the signature (ParsedCommand $command, bool $dryRun): array.
 */
abstract class CommandGroup
{
    /** Largest page size for list commands. */
    public const MAX_PER_PAGE = 100;

    abstract public static function register(CommandRegistry $registry): void;

    /**
     * Object-level capability check inside a handler.
     *
     * @param mixed ...$args
     * @throws CommandException 403 when the current user lacks $capability.
     */
    protected static function authorize(string $capability, ...$args): void
    {
        if (Capabilities::check($capability, ...$args)) {
            return;
        }

        throw new CommandException(
            esc_html__('Sorry, you are not allowed to do that.', 'mcpmate'),
            esc_html(CommandException::FORBIDDEN),
            403
        );
    }

    /**
     * Keep only $fields (already validated against the flag's enum) of each item.
     *
     * @param array<int, array<string, mixed>> $items
     * @param string[]|null                    $fields Null keeps everything.
     * @return array<int, array<string, mixed>>
     */
    protected static function pickFields(array $items, ?array $fields): array
    {
        if ($fields === null || $fields === []) {
            return $items;
        }

        $keep = array_flip($fields);

        return array_map(static fn (array $item) => array_intersect_key($item, $keep), $items);
    }

    /**
     * `--fields` flag schema for a list command.
     *
     * @param string[] $fields
     * @return array<string, mixed>
     */
    protected static function fieldsFlag(array $fields): array
    {
        return ['type' => 'list', 'enum' => $fields, 'max_items' => count($fields)];
    }

    /**
     * Load a wp-admin include that front-end and REST requests do not have.
     */
    protected static function requireAdmin(string $file): void
    {
        require_once ABSPATH . 'wp-admin/includes/' . $file;
    }

    protected static function notFound(string $message, string $hint = ''): CommandException
    {
        return new CommandException(esc_html($message), esc_html(CommandException::NOT_FOUND), 404, esc_html($hint));
    }

    protected static function notAllowed(string $message, string $hint = ''): CommandException
    {
        return new CommandException(esc_html($message), esc_html(CommandException::NOT_ALLOWED), 400, esc_html($hint));
    }
}
