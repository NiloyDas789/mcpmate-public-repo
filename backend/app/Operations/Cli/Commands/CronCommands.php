<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `cron event list`. Event arguments are not returned: plugins pass
 * arbitrary data there (emails, IDs, sometimes tokens).
 */
final class CronCommands extends CommandGroup
{
    public const FIELDS = ['hook', 'next_run_gmt', 'next_run_relative', 'recurrence', 'interval', 'args_count'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('cron event list', [
            'summary'    => __('List scheduled cron events.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'flags'      => [
                'hook'     => ['max_length' => 200, 'pattern' => '/^[A-Za-z0-9_.:\/-]+$/D'],
                'per_page' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => self::MAX_PER_PAGE],
                'paged'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields'   => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        // WordPress 6.0 returns false when the cron option is missing.
        $crons     = _get_cron_array() ?: [];
        $schedules = wp_get_schedules();
        $hook      = $command->flag('hook');
        $now       = time();
        $items     = [];

        foreach ($crons as $timestamp => $hooks) {
            foreach ((array) $hooks as $name => $events) {
                if ($hook !== null && $name !== $hook) {
                    continue;
                }

                foreach ((array) $events as $event) {
                    $schedule = !empty($event['schedule']) ? (string) $event['schedule'] : '';

                    $items[] = [
                        'hook'              => (string) $name,
                        'next_run_gmt'      => gmdate('Y-m-d H:i:s', (int) $timestamp),
                        'next_run_relative' => (int) $timestamp <= $now
                            ? __('now', 'mcpmate')
                            : human_time_diff($now, (int) $timestamp),
                        'recurrence'        => $schedule !== '' ? ($schedules[$schedule]['display'] ?? $schedule) : __('Non-repeating', 'mcpmate'),
                        'interval'          => isset($event['interval']) ? (int) $event['interval'] : null,
                        'args_count'        => count($event['args']),
                    ];
                }
            }
        }

        $perPage = (int) $command->flag('per_page');
        $paged   = (int) $command->flag('paged');
        $total   = count($items);

        return [
            'items'       => self::pickFields(array_slice($items, ($paged - 1) * $perPage, $perPage), $command->flag('fields')),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}
