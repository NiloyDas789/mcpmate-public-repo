<?php

namespace MCPMate\Extensions;

if (!defined('ABSPATH')) {
    exit;
}

use MCPMate\Config;

/**
 * What an AI app is told when it asks for something only MCPMate Pro does,
 * on a site where Pro is missing or its license is not active.
 *
 * Names only, never code (WordPress.org guideline 5): the MCP tools and
 * WP-CLI commands MCPMate Pro adds, so a call of one is answered with
 * "this needs MCPMate Pro" and what to do, instead of "Tool X not found".
 * ProUpsellTest keeps both lists equal to Pro's own (pro/docs/mcp-tools.json,
 * ProPolicy::CLI_COMMANDS). Nothing here unlocks anything: with Pro active
 * the lists are never consulted.
 */
final class ProUpsell
{
    public const CODE = 'mcpmate_needs_pro';

    public const ACTIVE = 'active';

    /** MCPMate Pro is active but its license is not valid, so its features do not run. */
    public const UNLICENSED = 'unlicensed';

    /** MCPMate Pro is not installed, or not active. */
    public const MISSING = 'missing';

    /** MCPMate Pro's MCP tools, as named in pro/docs/mcp-tools.json. */
    public const TOOLS = [
        'upload_media', 'create_draft_theme', 'list_theme_files', 'read_theme_file', 'write_theme_file',
        'edit_theme_file', 'get_preview_url', 'publish_draft_theme', 'rollback_theme', 'elementor_get',
        'elementor_widgets', 'elementor_save', 'save_memory', 'list_memories', 'get_memory', 'delete_memory',
        'update_skill', 'delete_skill', 'get_object', 'query_objects', 'list_options', 'get_options',
        'list_posts', 'get_post', 'create_post', 'update_post', 'trash_post', 'run_sql_select',
        'get_post_blocks', 'write_post_blocks', 'create_block_batch', 'add_block_change',
        'request_batch_finalization', 'get_block_batch', 'list_block_batches', 'delete_block_batch',
        'get_active_design', 'list_designs', 'get_design', 'save_design', 'check_design', 'activate_design',
        'create_upload_link', 'get_cli_job', 'create_sandbox_plugin', 'list_sandbox_files',
        'read_sandbox_file', 'write_sandbox_file', 'edit_sandbox_file', 'enable_sandbox_plugin',
        'disable_sandbox_plugin', 'list_files', 'read_file', 'list_integration_tools', 'run_integration_tool',
        'execute_php', 'run_wp_cli_shell', 'create_admin_login_link',
    ];

    /** MCPMate Pro's WP-CLI commands, as in ProPolicy::CLI_COMMANDS. */
    public const CLI_COMMANDS = [
        'cache flush', 'cache delete', 'rewrite flush', 'transient delete', 'option update', 'option delete',
        'post meta update', 'post meta delete', 'term create', 'term update', 'menu item add-post',
        'menu item add-term', 'menu item add-custom', 'menu item update', 'comment approve',
        'comment unapprove', 'comment spam', 'cron event run', 'plugin activate', 'plugin deactivate',
        'plugin update', 'plugin install', 'theme activate', 'rewrite structure', 'search-replace',
    ];

    /** @var string|null Test seam: the status to report instead of the real one. */
    private static $status;

    /**
     * MCPMate Pro's state on this site: ACTIVE (its features run here),
     * UNLICENSED (it is active, its license is not) or MISSING.
     */
    public static function status(): string
    {
        if (self::$status !== null) {
            return self::$status;
        }

        // Only MCPMate Pro registers features (Features::register()), and only with a valid license.
        if (Features::all() !== [] || Config::isProActivated()) {
            return self::ACTIVE;
        }

        return Config::isProInstalled() ? self::UNLICENSED : self::MISSING;
    }

    /**
     * Test seam; null goes back to the real status.
     */
    public static function useStatus(?string $status): void
    {
        self::$status = $status;
    }

    /**
     * A tool this site would run with MCPMate Pro, while Pro does not run here.
     */
    public static function isProTool(string $name): bool
    {
        return self::status() !== self::ACTIVE && in_array($name, self::TOOLS, true);
    }

    /**
     * The MCPMate Pro command a run_wp_cli call names, while Pro does not run here, or null.
     *
     * @param string[] $positional The command's words, lowercased, without `wp` and flags.
     */
    public static function proCommand(array $positional): ?string
    {
        if (self::status() === self::ACTIVE) {
            return null;
        }

        for ($n = min(3, count($positional)); $n > 0; $n--) {
            $name = implode(' ', array_slice($positional, 0, $n));

            if (in_array($name, self::CLI_COMMANDS, true)) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Why the call is refused: `"<what>" needs MCPMate Pro, …`.
     */
    public static function message(string $what): string
    {
        return self::status() === self::UNLICENSED
            /* translators: %s: an MCP tool or WP-CLI command, e.g. create_post or wp cache flush. */
            ? sprintf(__('"%s" is an MCPMate Pro feature. MCPMate Pro is installed on this site, but its license is not active, so its features are off.', 'mcpmate'), $what)
            /* translators: %s: an MCP tool or WP-CLI command, e.g. create_post or wp cache flush. */
            : sprintf(__('"%s" is an MCPMate Pro feature, and this site runs the free MCPMate plugin.', 'mcpmate'), $what);
    }

    /**
     * What the user has to do, for the AI app to pass on.
     */
    public static function hint(): string
    {
        $hint = self::status() === self::UNLICENSED
            /* translators: %s: where the license is entered, e.g. wp-admin → MCPMate → Settings → License. */
            ? sprintf(__('To use it, a site admin activates the MCPMate Pro license in %s.', 'mcpmate'), self::licensePlace())
            /* translators: %s: where the license is entered, e.g. wp-admin → MCPMate → Settings → License. */
            : sprintf(__('To use it, a site admin installs and activates the MCPMate Pro plugin, then activates its license in %s.', 'mcpmate'), self::licensePlace());

        $upgrade = Config::links()['upgrade'];

        if ($upgrade !== '' && self::status() === self::MISSING) {
            /* translators: %s: the address where MCPMate Pro can be bought. */
            $hint .= ' ' . sprintf(__('MCPMate Pro is available at %s.', 'mcpmate'), $upgrade);
        }

        return $hint;
    }

    /**
     * The next step for the AI app: tell the user, do not work around it.
     */
    public static function nextStep(): string
    {
        return __('Tell the user that this needs MCPMate Pro and what to do to get it, in your own words.', 'mcpmate') . ' '
            . __('Do not retry this call, and do not try to reach the same result another way, until they say MCPMate Pro is active.', 'mcpmate');
    }

    /**
     * Where the license is entered, as a site admin finds it.
     */
    private static function licensePlace(): string
    {
        return __('wp-admin → MCPMate → Settings → License', 'mcpmate');
    }

    /**
     * The server instructions' paragraph while MCPMate Pro does not run here ('' while it does).
     */
    public static function instructions(): string
    {
        $status = self::status();

        if ($status === self::ACTIVE) {
            return '';
        }

        $state = $status === self::UNLICENSED
            ? __('MCPMate Pro is installed on this site, but its license is not active, so only the free features run.', 'mcpmate')
            : __('This site runs the free MCPMate plugin, without MCPMate Pro.', 'mcpmate');

        return implode(' ', [
            $state,
            __('Your tools read the site, validate blocks, run read-only WP-CLI commands and read-only abilities, and save skills.', 'mcpmate'),
            __('MCPMate Pro adds the rest: creating and editing posts, pages and their blocks (as drafts), uploading media, theme drafts, Elementor and other page builders.', 'mcpmate'),
            __('It also adds plugin integrations (forms, SEO, custom fields, WooCommerce, themes) and WP-CLI commands that change the site.', 'mcpmate'),
            __('And write and destructive abilities, memory, design systems, read-only SQL, reading files, and sandbox plugins.', 'mcpmate'),
            __('When the user asks for one of these, do not try to work around it: tell them it needs MCPMate Pro.', 'mcpmate'),
            self::hint(),
        ]);
    }
}
