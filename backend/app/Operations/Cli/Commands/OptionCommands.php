<?php

namespace McpMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use McpMate\Config;
use McpMate\Hooks\Hooks;
use McpMate\Operations\Cli\Command;
use McpMate\Operations\Cli\CommandException;
use McpMate\Operations\Cli\CommandRegistry;
use McpMate\Operations\Cli\ParsedCommand;

/**
 * `option get|list|update`, limited to allowlisted options.
 *
 * Reads: READABLE (filter `MCPMATE_cli_option_get_allowlist`). Options
 * often hold API keys and licence codes, so nothing outside the list is
 * read or listed.
 *
 * Writes: WRITABLE, each with its own validator (filter
 * `MCPMATE_cli_option_update_allowlist`; names a filter adds are
 * stored through sanitize_option() + sanitize_text_field()). NEVER_WRITABLE
 * and every auto_update_* option win over the filter: those options change
 * URLs, users, roles, plugins, themes or updates, or this plugin's own
 * settings.
 */
final class OptionCommands extends CommandGroup
{
    public const READABLE = [
        'admin_email', 'blog_public', 'blogdescription', 'blogname', 'category_base', 'comment_registration',
        'comments_per_page', 'date_format', 'default_comment_status', 'default_ping_status', 'default_role',
        'gmt_offset', 'home', 'large_size_h', 'large_size_w', 'medium_size_h', 'medium_size_w', 'page_for_posts',
        'page_on_front', 'permalink_structure', 'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'show_on_front',
        'siteurl', 'start_of_week', 'stylesheet', 'tag_base', 'template', 'thumbnail_size_h', 'thumbnail_size_w',
        'time_format', 'timezone_string', 'uploads_use_yearmonth_folders', 'users_can_register', 'WPLANG',
    ];

    /** Option name => validator rule. */
    public const WRITABLE = [
        'blogname'               => 'text',
        'blogdescription'        => 'text',
        'date_format'            => 'format',
        'time_format'            => 'format',
        'timezone_string'        => 'timezone',
        'start_of_week'          => 'weekday',
        'posts_per_page'         => 'count',
        'posts_per_rss'          => 'count',
        'comments_per_page'      => 'count',
        'default_comment_status' => 'open_closed',
        'default_ping_status'    => 'open_closed',
    ];

    public const NEVER_WRITABLE = [
        'active_plugins', 'admin_email', 'cron', 'default_role', 'home',
        'new_admin_email', 'recently_activated', 'siteurl', 'stylesheet', 'template', 'upload_path', 'upload_url_path',
        'users_can_register',
    ];

    /** Every auto-update setting (plugins, themes, core) is never writable either. */
    public const AUTO_UPDATE_PREFIX = 'auto_update_';

    private const NAME_PATTERN = '/^[A-Za-z0-9_.:-]{1,191}$/D';

    public static function register(CommandRegistry $registry): void
    {
        $registry->add('option get', [
            'summary'    => __('Read one allowlisted option.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'args'       => [
                ['name' => 'key', 'required' => true, 'pattern' => self::NAME_PATTERN],
            ],
            'handler'    => [self::class, 'get'],
        ]);

        $registry->add('option list', [
            'summary'    => __('List allowlisted options and their values.', 'mcpmate'),
            'risk'       => Command::RISK_READ,
            'capability' => 'manage_options',
            'flags'      => [
                'search' => ['max_length' => 100, 'pattern' => '/^[A-Za-z0-9_.:*?-]+$/D'],
            ],
            'handler'    => [self::class, 'list'],
        ]);

        $registry->add('option update', [
            'summary'          => __('Change one allowlisted option (site title, tagline, date formats, timezone, reading settings).', 'mcpmate'),
            'risk'             => Command::RISK_WRITE,
            'capability'       => 'manage_options',
            'supports_dry_run' => true,
            'args'             => [
                ['name' => 'key', 'required' => true, 'pattern' => self::NAME_PATTERN],
                ['name' => 'value', 'required' => true, 'max_length' => 255],
            ],
            'target'           => ['option', 'key'],
            'handler'          => [self::class, 'update'],
        ]);
    }

    // ──────────────────────────────────────────────
    //  Allowlists
    // ──────────────────────────────────────────────

    /**
     * @return string[]
     */
    public static function readable(): array
    {
        $names = Hooks::applyFilter(Config::withPrefix('cli_option_get_allowlist'), self::READABLE);

        return is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
    }

    /**
     * Writable option names (always also readable).
     *
     * @return string[]
     */
    public static function writable(): array
    {
        $names = Hooks::applyFilter(Config::withPrefix('cli_option_update_allowlist'), array_keys(self::WRITABLE));
        $names = is_array($names) ? array_filter($names, 'is_string') : [];

        return array_values(array_filter($names, static fn (string $name) => !self::neverWritable($name)));
    }

    private static function neverWritable(string $name): bool
    {
        global $wpdb;

        return in_array($name, self::NEVER_WRITABLE, true)
            || stripos($name, self::AUTO_UPDATE_PREFIX) === 0
            || $name === $wpdb->get_blog_prefix() . 'user_roles'
            || stripos($name, Config::VAR_PREFIX) === 0;
    }

    // ──────────────────────────────────────────────
    //  Handlers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public static function get(ParsedCommand $command, bool $dryRun): array
    {
        $name = (string) $command->arg('key');

        if (!in_array($name, self::readable(), true) && !in_array($name, self::writable(), true)) {
            throw self::notAllowedOption(esc_html($name), false);
        }

        return ['name' => $name, 'value' => get_option($name)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function list(ParsedCommand $command, bool $dryRun): array
    {
        $names = array_values(array_unique(array_merge(self::readable(), self::writable())));
        sort($names);

        $search = $command->flag('search');
        if ($search !== null) {
            $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote((string) $search, '/')) . '$/D';
            $names = array_values(array_filter($names, static fn (string $name) => (bool) preg_match($regex, $name)));
        }

        $items = [];
        foreach ($names as $name) {
            $items[] = ['name' => $name, 'value' => get_option($name), 'writable' => in_array($name, self::writable(), true)];
        }

        return ['items' => $items, 'total' => count($items)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(ParsedCommand $command, bool $dryRun): array
    {
        $name = (string) $command->arg('key');

        if (!in_array($name, self::writable(), true)) {
            throw self::notAllowedOption(esc_html($name), true);
        }

        $old = get_option($name);
        $new = self::validate($name, (string) $command->arg('value'));

        $result = ['name' => $name, 'old' => $old, 'new' => $new, 'changed' => (string) $old !== (string) $new];

        if ($dryRun || !$result['changed']) {
            return $result;
        }

        update_option($name, $new);

        return $result;
    }

    // ──────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────

    /**
     * @return int|string
     */
    private static function validate(string $name, string $value)
    {
        $rule = self::WRITABLE[$name] ?? 'text';

        switch ($rule) {
            case 'timezone':
                if ($value !== 'UTC' && !in_array($value, timezone_identifiers_list(), true)) {
                    throw self::invalidValue(esc_html($name), esc_html__('Use a timezone identifier such as Europe/London or UTC.', 'mcpmate'));
                }

                return $value;

            case 'weekday':
                if (!preg_match('/^[0-6]$/D', $value)) {
                    throw self::invalidValue(esc_html($name), esc_html__('Use 0 (Sunday) to 6 (Saturday).', 'mcpmate'));
                }

                return (int) $value;

            case 'count':
                if (!preg_match('/^\d{1,3}$/D', $value) || (int) $value < 1 || (int) $value > 100) {
                    throw self::invalidValue(esc_html($name), esc_html__('Use a whole number from 1 to 100.', 'mcpmate'));
                }

                return (int) $value;

            case 'open_closed':
                if ($value !== 'open' && $value !== 'closed') {
                    throw self::invalidValue(esc_html($name), esc_html__('Use "open" or "closed".', 'mcpmate'));
                }

                return $value;

            case 'format':
                if (strlen($value) > 50) {
                    throw self::invalidValue(esc_html($name), esc_html__('Use a PHP date format of at most 50 characters, e.g. F j, Y.', 'mcpmate'));
                }

                return (string) sanitize_option($name, sanitize_text_field($value));

            default:
                return (string) sanitize_option($name, sanitize_text_field($value));
        }
    }

    private static function invalidValue(string $name, string $hint): CommandException
    {
        return new CommandException(
            /* translators: %s: option name. */
            sprintf(esc_html__('Invalid value for %s.', 'mcpmate'), esc_html($name)),
            esc_html(CommandException::INVALID_ARGS),
            400,
            esc_html($hint)
        );
    }

    private static function notAllowedOption(string $name, bool $write): CommandException
    {
        $allowed = $write ? self::writable() : array_unique(array_merge(self::readable(), self::writable()));
        sort($allowed);

        return self::notAllowed(
            $write
                /* translators: %s: option name. */
                ? sprintf(__('Option "%s" cannot be changed through McpMate.', 'mcpmate'), $name)
                /* translators: %s: option name. */
                : sprintf(__('Option "%s" cannot be read through McpMate.', 'mcpmate'), $name),
            /* translators: %s: comma-separated option names. */
            sprintf(__('Allowed options: %s.', 'mcpmate'), implode(', ', $allowed))
        );
    }
}
