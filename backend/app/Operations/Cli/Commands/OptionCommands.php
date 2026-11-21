<?php

namespace MCPMate\Operations\Cli\Commands;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;
use MCPMate\Hooks\Hooks;
use MCPMate\Operations\Cli\Command;
use MCPMate\Operations\Cli\CommandException;
use MCPMate\Operations\Cli\CommandRegistry;
use MCPMate\Operations\Cli\ParsedCommand;

/**
 * `option get|list`, limited to allowlisted options. MCPMate Pro adds
 * `option update` and `option delete` (OptionWriteCommands), which write
 * only writable() names and delete only deletable() ones.
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
 *
 * Deletes: none by default. A site admin names the options that may be
 * deleted (filter `MCPMATE_cli_option_delete_allowlist`), typically ones a
 * removed plugin left behind; READABLE, WRITABLE, NEVER_WRITABLE, every
 * auto_update_* and MCPMate's own options are never deletable.
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

    public const NAME_PATTERN = '/^[A-Za-z0-9_.:-]{1,191}$/D';

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

    /**
     * Option names `option delete` may delete: only what a site admin added
     * with the filter, and never a core setting or a protected option.
     *
     * @return string[]
     */
    public static function deletable(): array
    {
        $names = Hooks::applyFilter(Config::withPrefix('cli_option_delete_allowlist'), []);
        $names = is_array($names) ? array_filter($names, 'is_string') : [];

        return array_values(array_filter($names, static function (string $name): bool {
            return preg_match(self::NAME_PATTERN, $name)
                && !self::neverWritable($name)
                && !in_array($name, self::READABLE, true)
                && !isset(self::WRITABLE[$name]);
        }));
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

    // ──────────────────────────────────────────────
    //  Validation
    // ──────────────────────────────────────────────

    public static function notAllowedOption(string $name, bool $write): CommandException
    {
        $allowed = $write ? self::writable() : array_unique(array_merge(self::readable(), self::writable()));
        sort($allowed);

        return self::notAllowed(
            $write
                /* translators: %s: option name. */
                ? sprintf(__('Option "%s" cannot be changed through MCPMate.', 'mcpmate'), $name)
                /* translators: %s: option name. */
                : sprintf(__('Option "%s" cannot be read through MCPMate.', 'mcpmate'), $name),
            /* translators: %s: comma-separated option names. */
            sprintf(__('Allowed options: %s.', 'mcpmate'), implode(', ', $allowed))
        );
    }
}
