<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `user list`, `user get`, `role list`. Read-only: users and roles are
 * never created, changed or deleted through MCPMate (Policy refuses that).
 * Password hashes, activation keys and session tokens are never returned.
 */
final class UserCommands extends CommandGroup
{
    public const FIELDS = ['ID', 'user_login', 'display_name', 'user_email', 'user_registered', 'roles'];

    public const GET_FIELDS = ['ID', 'user_login', 'user_nicename', 'display_name', 'user_email', 'user_url', 'user_registered', 'roles', 'first_name', 'last_name', 'nickname'];

    public const ROLE_FIELDS = ['name', 'display_name', 'capability_count', 'capabilities'];

    public const ROLE_LIST_FIELDS = ['name', 'display_name', 'capability_count'];

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

        $registry->add('user get', [
            'summary'    => __('Show one user by ID, login or email.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'list_users',
            'args'       => [
                ['name' => 'user', 'required' => true, 'max_length' => 100],
            ],
            'flags'      => [
                'fields' => self::fieldsFlag(self::GET_FIELDS),
            ],
            'target'     => ['user', 'user'],
            'handler'    => [self::class, 'get'],
        ]);

        $registry->add('role list', [
            'summary'    => __('List the user roles and how many capabilities each has.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'list_users',
            'flags'      => [
                'fields' => self::fieldsFlag(self::ROLE_FIELDS),
            ],
            'handler'    => [self::class, 'roles'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $value = (string) $command->arg('user');

        if (preg_match('/^[1-9]\d{0,19}$/D', $value)) {
            $user = get_user_by('id', (int) $value);
        } elseif (is_email($value)) {
            $user = get_user_by('email', $value);
        } else {
            $user = get_user_by('login', $value);
        }

        if (!$user instanceof \WP_User) {
            throw self::notFound(
                /* translators: %s: user ID, login or email. */
                sprintf(esc_html__('There is no user "%s".', 'mcpmate'), esc_html($value)),
                esc_html__('Give the user ID, login or email; "user list" lists them.', 'mcpmate')
            );
        }

        $item = [
            'ID'              => (int) $user->ID,
            'user_login'      => $user->user_login,
            'user_nicename'   => $user->user_nicename,
            'display_name'    => $user->display_name,
            'user_email'      => $user->user_email,
            'user_url'        => $user->user_url,
            'user_registered' => $user->user_registered,
            'roles'           => array_values($user->roles),
            'first_name'      => (string) $user->first_name,
            'last_name'       => (string) $user->last_name,
            'nickname'        => (string) $user->nickname,
        ];

        return self::pickFields([$item], $command->flag('fields'))[0];
    }

    /**
     * @return array<string, mixed>
     */
    public static function roles(ParsedCommand $command, bool $dryRun): array
    {
        $items = [];

        foreach (wp_roles()->roles as $name => $role) {
            $granted = array_keys(array_filter((array) ($role['capabilities'] ?? [])));
            sort($granted);

            $items[] = [
                'name'             => (string) $name,
                'display_name'     => translate_user_role((string) ($role['name'] ?? $name)),
                'capability_count' => count($granted),
                'capabilities'     => $granted,
            ];
        }

        return ['items' => self::pickFields($items, $command->flag('fields') ?? self::ROLE_LIST_FIELDS), 'total' => count($items)];
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
