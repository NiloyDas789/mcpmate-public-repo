<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `user list`. Read-only; password hashes and activation keys are never returned.
 */
final class UserCommands extends CommandGroup
{
    public const FIELDS = ['ID', 'user_login', 'display_name', 'user_email', 'user_registered', 'roles'];

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('user list', [
            'summary'    => __('List users with their roles.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'list_users',
            'flags'      => [
                'role'   => ['max_length' => 100, 'pattern' => '/^[a-z0-9_-]+$/D'],
                'number' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PER_PAGE, 'default' => 50],
                'paged'  => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100000, 'default' => 1],
                'fields' => self::fieldsFlag(self::FIELDS),
            ],
            'handler'    => [self::class, 'list'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $role = $command->flag('role');

        if ($role !== null && !wp_roles()->is_role($role)) {
            throw self::notFound(
                /* translators: %s: role name. */
                sprintf(esc_html__('Role "%s" does not exist.', 'mcpmate'), esc_html($role))
            );
        }

        $number = (int) $command->flag('number');
        $paged  = (int) $command->flag('paged');

        $query = new \WP_User_Query([
            'number'      => $number,
            'paged'       => $paged,
            'orderby'     => 'ID',
            'order'       => 'ASC',
            'role'        => $role ?? '',
            'count_total' => true,
        ]);

        $items = [];
        foreach ($query->get_results() as $user) {
            $items[] = [
                'ID'              => (int) $user->ID,
                'user_login'      => $user->user_login,
                'display_name'    => $user->display_name,
                'user_email'      => $user->user_email,
                'user_registered' => $user->user_registered,
                'roles'           => array_values($user->roles),
            ];
        }

        $total = (int) $query->get_total();

        return [
            'items'       => self::pickFields($items, $command->flag('fields')),
            'total'       => $total,
            'page'        => $paged,
            'per_page'    => $number,
            'total_pages' => (int) ceil($total / $number),
        ];
    }
}
