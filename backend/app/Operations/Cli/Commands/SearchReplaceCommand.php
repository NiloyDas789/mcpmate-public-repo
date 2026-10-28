<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;
use McpMate\Services\SearchReplace\TableReplacer;

/**
 * `search-replace <old> <new> <table>...`: serialization-safe replace in
 * the named tables only (see TableReplacer for the scope rules).
 *
 * Destructive and dry run by default: without dry_run (or with
 * `--no-dry-run` / dry_run: false) it waits for admin approval.
 */
final class SearchReplaceCommand extends CommandGroup
{
    public const MIN_SEARCH_LENGTH = 3;

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('search-replace', [
            'summary'          => __('Replace text in the given database tables, safely for serialized data. Dry run unless approved.', 'mcpmate'),
            'risk'             => Command::RISK_DESTRUCTIVE,
            'capability'       => 'manage_options',
            'supports_dry_run' => true,
            'dry_run_default'  => true,
            'args'             => [
                ['name' => 'old', 'required' => true, 'max_length' => 1000],
                ['name' => 'new', 'required' => true, 'max_length' => 1000],
                ['name' => 'table', 'required' => true, 'variadic' => true, 'unique' => true, 'max_items' => 20, 'max_length' => 64, 'pattern' => '/^[A-Za-z0-9_$]+$/D'],
            ],
            'flags'            => [
                'skip-columns' => ['type' => 'list', 'max_items' => 20, 'max_length' => 1000, 'pattern' => '/^[A-Za-z0-9_]{1,64}$/D'],
            ],
            'target'           => ['table', 'table'],
            'handler'          => [self::class, 'run'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function run(ParsedCommand $command, bool $dryRun): array
    {
        $old    = (string) $command->arg('old');
        $new    = (string) $command->arg('new');
        $tables = (array) $command->arg('table');

        if (strlen($old) < self::MIN_SEARCH_LENGTH) {
            throw self::notAllowed(
                /* translators: %d: minimum length. */
                sprintf(esc_html__('The search string must be at least %d characters long.', 'mcpmate'), (int) self::MIN_SEARCH_LENGTH)
            );
        }

        if ($old === $new) {
            throw self::notAllowed(esc_html__('The search and replacement strings are the same.', 'mcpmate'));
        }

        $allowed = TableReplacer::allowedTables();
        $denied  = array_values(array_diff($tables, $allowed));

        if ($denied !== []) {
            throw self::notAllowed(
                /* translators: %s: comma-separated table names. */
                sprintf(esc_html__('These tables cannot be changed through McpMate: %s.', 'mcpmate'), esc_html(implode(', ', $denied))),
                /* translators: %s: comma-separated table names. */
                sprintf(esc_html__('Allowed tables: %s.', 'mcpmate'), esc_html(implode(', ', $allowed)))
            );
        }

        return ['old' => $old, 'new' => $new] + TableReplacer::run($old, $new, $tables, (array) $command->flag('skip-columns', []), $dryRun);
    }
}
